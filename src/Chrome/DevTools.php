<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * One headless Chrome, held open, driven over the DevTools protocol.
 *
 * Held open on purpose. Starting Chrome costs the better part of a second,
 * and a scan of a site is one page after another in the same worker: paying
 * that per page turns a five minute scan into twenty. So the session outlives
 * a single page, the script that carries axe-core is sent once, and each page
 * is a navigation.
 *
 * What it insists on, because each one is the difference between a page that
 * could not be read and a page reported as clean:
 *
 * - The navigation has to succeed. A DNS failure or a refused connection is
 *   an error, never an empty result.
 * - The document's own HTTP status has to be a success. A published entry
 *   whose route is broken serves the site's 404 page, and scanning that would
 *   file the 404 page's problems against the entry, in a document somebody
 *   hands to a regulator.
 * - The load event has to arrive. A page read halfway is not a page read.
 */
final class DevTools
{
    private const START_TIMEOUT = 20;

    /** @var resource|null */
    private $process = null;

    private ?WebSocket $socket = null;

    private ?string $profile = null;

    private ?string $sessionId = null;

    private int $nextId = 0;

    /** @var array<int, array<string, mixed>> events that arrived while waiting for a command's reply */
    private array $events = [];

    /**
     * How many browsers this session has had to start.
     *
     * One is the healthy number for a whole scan. More means Chrome is dying
     * and being replaced, which is worth being able to see: a page refused
     * because the browser broke and a page refused because the page is broken
     * look identical from the outside, and only one of them is the site's
     * fault.
     */
    private int $starts = 0;

    public function __construct(
        private readonly Browser $browser,
        private readonly int $timeoutSeconds = 30,
        private readonly int $settleMs = 250,
    ) {}

    public function isOpen(): bool
    {
        return $this->socket !== null;
    }

    /** How many browsers have been started for this session. */
    public function starts(): int
    {
        return $this->starts;
    }

    /**
     * Start Chrome and attach to a page in it. Safe to call again; it does
     * nothing when a session is already open.
     *
     * @throws ChromeProtocolError
     */
    public function open(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $binary = $this->browser->binary();

        if ($binary === null) {
            throw new ChromeProtocolError('No Chrome or Chromium was found. Set A11Y_CHROME_PATH to the browser binary to scan with axe.');
        }

        $this->profile = sys_get_temp_dir().'/a11y-report-axe-'.bin2hex(random_bytes(4));

        $command = implode(' ', array_map('escapeshellarg', [
            $binary,
            ...Browser::FLAGS,
            '--user-data-dir='.$this->profile,
            // Port zero, then read the one Chrome chose out of its profile.
            // A fixed port is a collision between two workers on one machine,
            // and the collision looks like a scan reading another scan's page.
            '--remote-debugging-port=0',
            'about:blank',
        ]));

        $this->process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (! is_resource($this->process)) {
            $this->shutdown();

            throw new ChromeProtocolError('Chrome could not be started.');
        }

        $this->starts++;

        try {
            $this->socket = WebSocket::connect($this->browserEndpoint(), $this->timeoutSeconds);
            $target = $this->send('Target.createTarget', ['url' => 'about:blank']);
            $attached = $this->send('Target.attachToTarget', ['targetId' => $target['targetId'], 'flatten' => true]);
            $this->sessionId = $attached['sessionId'];
            $this->send('Page.enable');
            $this->send('Network.enable');
            $this->send('Runtime.enable');
        } catch (\Throwable $e) {
            $this->shutdown();

            throw $e instanceof ChromeProtocolError ? $e : new ChromeProtocolError('Chrome could not be attached to: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Run this before every document, for the life of the session. Chrome
     * replays it on each navigation, so half a megabyte of axe-core crosses
     * the socket once rather than once a page.
     *
     * @throws ChromeProtocolError
     */
    public function onEveryDocument(string $source): string
    {
        $this->open();

        return $this->send('Page.addScriptToEvaluateOnNewDocument', ['source' => $source])['identifier'];
    }

    /**
     * Go to a page and wait for it to finish loading.
     *
     * @throws ChromeProtocolError
     */
    public function navigate(string $url): void
    {
        $this->open();

        $this->events = [];
        $result = $this->send('Page.navigate', ['url' => $url]);

        if (isset($result['errorText']) && $result['errorText'] !== '') {
            throw new PageNotReadable("Chrome could not open {$url}: {$result['errorText']}.");
        }

        $loaderId = $result['loaderId'] ?? null;
        $deadline = microtime(true) + $this->timeoutSeconds;

        $this->waitForEvent('Page.loadEventFired', $deadline, "{$url} did not finish loading within {$this->timeoutSeconds} seconds.", pageFault: true);

        // Only where there is an HTTP status to have. A file: or data: URL
        // reports zero, and refusing that would refuse the one kind of page a
        // test can serve without a web server.
        if (preg_match('#^https?://#i', $url) === 1) {
            $status = $this->documentStatus($loaderId);

            if ($status !== null && $status !== 0 && ($status < 200 || $status > 399)) {
                throw new PageNotReadable("{$url} answered {$status}, so what Chrome read is not that page.");
            }
        }

        if ($this->settleMs > 0) {
            usleep($this->settleMs * 1000);
        }
    }

    /**
     * Evaluate an expression in the page and return what it resolved to.
     *
     * @throws ChromeProtocolError
     */
    public function evaluate(string $expression): mixed
    {
        $this->open();

        $result = $this->send('Runtime.evaluate', [
            'expression' => $expression,
            'awaitPromise' => true,
            'returnByValue' => true,
        ]);

        if (isset($result['exceptionDetails'])) {
            $text = $result['exceptionDetails']['exception']['description']
                ?? $result['exceptionDetails']['text']
                ?? 'an error with no description';

            throw new PageNotReadable('The page threw while being checked: '.substr((string) $text, 0, 300));
        }

        return $result['result']['value'] ?? null;
    }

    public function close(): void
    {
        $this->shutdown();
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * The status the document itself answered with, or null when Chrome
     * reported no document response (a data: or about: URL, say).
     */
    private function documentStatus(?string $loaderId): ?int
    {
        foreach ($this->events as $event) {
            if (($event['method'] ?? '') !== 'Network.responseReceived') {
                continue;
            }

            $params = $event['params'] ?? [];

            if (($params['type'] ?? '') === 'Document' && ($loaderId === null || ($params['loaderId'] ?? null) === $loaderId)) {
                return (int) ($params['response']['status'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ChromeProtocolError
     */
    private function send(string $method, array $params = []): array
    {
        if ($this->socket === null) {
            throw new ChromeProtocolError('There is no open Chrome session to send '.$method.' to.');
        }

        $id = ++$this->nextId;
        $message = ['id' => $id, 'method' => $method, 'params' => (object) $params];

        if ($this->sessionId !== null && ! str_starts_with($method, 'Target.')) {
            $message['sessionId'] = $this->sessionId;
        }

        $this->socket->send((string) json_encode($message));

        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $frame = json_decode($this->socket->receive($deadline), true);

            if (! is_array($frame)) {
                throw new ChromeProtocolError('Chrome sent something that is not JSON.');
            }

            if (($frame['id'] ?? null) !== $id) {
                if (isset($frame['method'])) {
                    $this->events[] = $frame;
                }

                continue;
            }

            if (isset($frame['error'])) {
                throw new ChromeProtocolError("Chrome refused {$method}: ".($frame['error']['message'] ?? 'no reason given').'.');
            }

            return (array) ($frame['result'] ?? []);
        }
    }

    /**
     * @throws ChromeProtocolError
     */
    private function waitForEvent(string $method, float $deadline, string $timeoutMessage, bool $pageFault = false): void
    {
        foreach ($this->events as $event) {
            if (($event['method'] ?? '') === $method) {
                return;
            }
        }

        while (true) {
            if (microtime(true) > $deadline) {
                throw $pageFault ? new PageNotReadable($timeoutMessage) : new ChromeProtocolError($timeoutMessage);
            }

            $frame = json_decode($this->socket->receive($deadline), true);

            if (! is_array($frame) || ! isset($frame['method'])) {
                continue;
            }

            $this->events[] = $frame;

            if ($frame['method'] === $method) {
                return;
            }
        }
    }

    /**
     * The address Chrome is listening on, from the file it writes into its
     * own profile once it is ready. Polled rather than assumed: Chrome writes
     * it when it has finished starting, so its appearance is the signal.
     *
     * @throws ChromeProtocolError
     */
    private function browserEndpoint(): string
    {
        $file = $this->profile.'/DevToolsActivePort';
        $deadline = microtime(true) + self::START_TIMEOUT;

        while (microtime(true) < $deadline) {
            if (is_file($file)) {
                $lines = explode("\n", (string) file_get_contents($file));

                if (count($lines) >= 2 && trim($lines[0]) !== '' && trim($lines[1]) !== '') {
                    return 'ws://127.0.0.1:'.trim($lines[0]).trim($lines[1]);
                }
            }

            if (is_resource($this->process) && ! proc_get_status($this->process)['running']) {
                throw new ChromeProtocolError('Chrome exited before it was ready to be driven.');
            }

            usleep(50_000);
        }

        throw new ChromeProtocolError('Chrome did not become ready within '.self::START_TIMEOUT.' seconds.');
    }

    private function shutdown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        $this->sessionId = null;
        $this->events = [];

        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }

            proc_close($this->process);
        }

        $this->process = null;

        if ($this->profile !== null) {
            Browser::removeDirectory($this->profile);
            $this->profile = null;
        }
    }
}
