<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * The smallest WebSocket client that can hold a DevTools conversation.
 *
 * Written rather than pulled in because the whole of what this needs is one
 * opcode in each direction against local Chrome. The optional Cloud transport
 * permits only Cloudflare's fixed endpoint over verified TLS with header-only
 * authentication. Neither mode negotiates extensions, compression or subprotocols.
 * A dependency for that is a dependency to keep up to date for the life of a
 * commercial addon.
 *
 * The parts that are not optional, because getting any of them wrong loses
 * data silently rather than loudly, which in this product means a page
 * reported clean because its findings never arrived:
 *
 * - Every client frame is masked. Chrome closes the connection on an
 *   unmasked one, and the failure looks like a hang.
 * - Payloads over 65535 bytes need the 64-bit length. axe-core's source is
 *   over half a megabyte and a result from a large page can be hundreds of
 *   kilobytes, so both directions cross that line in normal use.
 * - A message can arrive as a first frame and any number of continuations.
 *   Reading only the first frame yields valid-looking truncated JSON.
 * - Ping must be answered, or Chrome hangs up mid-scan on a slow page.
 */
final class WebSocket
{
    private const OP_CONTINUATION = 0x0;

    private const OP_TEXT = 0x1;

    private const OP_BINARY = 0x2;

    private const OP_CLOSE = 0x8;

    private const OP_PING = 0x9;

    private const OP_PONG = 0xA;

    /** @var resource */
    private $socket;

    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * @throws ChromeProtocolError
     */
    public static function connect(string $url, float $timeoutSeconds = 10.0, #[\SensitiveParameter] ?string $bearer = null): self
    {
        $parts = parse_url($url);

        if (! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['ws', 'wss'], true)) {
            throw new ChromeProtocolError("[{$url}] is not a WebSocket address this can open.");
        }

        $secure = $parts['scheme'] === 'wss';
        // Bearer credentials belong only to this fixed service over verified TLS.
        // No custom host, port, query or redirect may carry the private key.
        if ($secure) {
            if ($parts['host'] !== 'api.cloudflare.com' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('#^/client/v4/accounts/[a-f0-9]{32}/browser-run/devtools/browser$#D', $parts['path'] ?? '') !== 1
                || $bearer === null || strlen($bearer) < 20 || strlen($bearer) > 4096
                || preg_match('/[^\x21-\x7e]/', $bearer) === 1) {
                throw new ChromeProtocolError('Cloud browser configuration is invalid.');
            }
        } elseif ($bearer !== null) {
            throw new ChromeProtocolError('Browser credentials require the fixed secure service.');
        }
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // Silenced by a handler and not by `@`: a refused connection is an
        // ordinary answer here (nothing is listening yet, or Chrome has gone),
        // and it is reported below as an exception with the reason in it. The
        // `@` operator no longer stops a test runner's error handler from
        // turning the warning into a failure.
        set_error_handler(static fn (): bool => true);

        try {
            $transport = $secure ? 'tls' : 'tcp';
            $socket = stream_socket_client("{$transport}://{$host}:{$port}", $errno, $error, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new ChromeProtocolError("Could not open a socket to Chrome at {$host}:{$port}: {$error} ({$errno}).");
        }

        $key = base64_encode(random_bytes(16));

        $request = implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            ...($bearer === null ? [] : ['Authorization: Bearer '.$bearer]),
            '', '',
        ]);
        stream_set_timeout($socket, max(1, (int) ceil($timeoutSeconds)));
        $sent = 0;
        while ($sent < strlen($request)) {
            $written = @fwrite($socket, substr($request, $sent));
            if ($written === false || $written === 0) {
                fclose($socket);
                throw new ChromeProtocolError('Browser handshake could not be sent.');
            }
            $sent += $written;
        }

        $client = new self($socket);
        try {
            $headers = $client->readHandshake(microtime(true) + $timeoutSeconds);
        } catch (\Throwable $e) {
            $client->close();
            throw $e;
        }

        if (! str_contains($headers, ' 101 ')) {
            $client->close();

            if ($secure) {
                throw new ChromeProtocolError('Cloud browser upgrade failed.', preg_match('#^HTTP/1\.[01] 429(?: |\r|\n)#', $headers) === 1 ? 429 : 0);
            }

            throw new ChromeProtocolError('Chrome refused the WebSocket upgrade: '.trim(strtok($headers, "\r\n") ?: 'no response').'.');
        }

        // Proves we are talking to a WebSocket peer and not to something that
        // happens to answer 101, which is worth four lines here because the
        // alternative failure is a hang while reading a frame that never comes.
        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (! str_contains($headers, $expected)) {
            $client->close();

            throw new ChromeProtocolError('Chrome answered the WebSocket upgrade with the wrong accept key.');
        }

        return $client;
    }

    /**
     * @throws ChromeProtocolError
     */
    public function send(string $payload, ?float $deadline = null): void
    {
        $length = strlen($payload);

        $header = chr(0x80 | self::OP_TEXT);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);

        // PHP's string XOR works byte by byte over the shorter operand, which
        // masks half a megabyte in one operation instead of half a million.
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        $frame = $header.$mask.$masked;
        $this->writeFrame($frame, $deadline ?? microtime(true) + 10);

    }

    /**
     * One whole message, however many frames it arrived in.
     *
     * @throws ChromeProtocolError
     */
    public function receive(float $deadline): string
    {
        $message = '';

        while (true) {
            [$fin, $opcode, $payload] = $this->readFrame($deadline);

            switch ($opcode) {
                case self::OP_PING:
                    $this->sendControl(self::OP_PONG, $payload, $deadline);

                    continue 2;

                case self::OP_PONG:
                    continue 2;

                case self::OP_CLOSE:
                    throw new ChromeProtocolError('Chrome closed the DevTools connection.');
                case self::OP_TEXT:
                case self::OP_BINARY:
                case self::OP_CONTINUATION:
                    $message .= $payload;

                    if ($fin) {
                        return $message;
                    }

                    continue 2;

                default:
                    throw new ChromeProtocolError(sprintf('Chrome sent a frame with opcode 0x%X, which this does not speak.', $opcode));
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            try {
                $this->sendControl(self::OP_CLOSE, pack('n', 1000), microtime(true) + 0.1);
            } catch (\Throwable) {
                // Closing must release a stalled socket even when its peer stopped reading.
            } finally {
                @fclose($this->socket);
            }
        }
    }

    /**
     * @return array{0: bool, 1: int, 2: string}
     *
     * @throws ChromeProtocolError
     */
    private function readFrame(float $deadline): array
    {
        $head = $this->readExactly(2, $deadline);
        $first = ord($head[0]);
        $second = ord($head[1]);

        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $length = $second & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->readExactly(2, $deadline))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->readExactly(8, $deadline))[1];
        }

        // A server frame is never masked. If one is, we are not talking to
        // what we think we are, and reading on would return rubbish.
        if (($second & 0x80) !== 0) {
            throw new ChromeProtocolError('Chrome sent a masked frame, which a server must not.');
        }

        return [$fin, $opcode, $length === 0 ? '' : $this->readExactly($length, $deadline)];
    }

    private function sendControl(int $opcode, string $payload, ?float $deadline = null): void
    {
        // A control frame carries at most 125 bytes, and the length goes in
        // seven bits. A longer payload would set the bit that means "read two
        // more bytes of length" and the peer would start reading the mask as a
        // length: not a rejected frame, a stream out of step from there on.
        // Chrome obeys the limit, so this is about the pong echoing a ping
        // that did not.
        $payload = substr($payload, 0, 125);

        $mask = random_bytes(4);
        $length = strlen($payload);
        $masked = $length === 0 ? '' : ($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));

        $this->writeFrame(chr(0x80 | $opcode).chr(0x80 | $length).$mask.$masked, $deadline ?? microtime(true) + 1);
    }

    /** A writable socket can still stall mid-frame. Include every write in the deadline. */
    private function writeFrame(string $frame, float $deadline): void
    {
        $blocking = stream_get_meta_data($this->socket)['blocked'];
        stream_set_blocking($this->socket, false);
        $written = 0;
        try {
            while ($written < strlen($frame)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new ChromeTimedOut('Chrome stopped reading before the command deadline.');
                }
                $read = $except = [];
                $write = [$this->socket];
                $seconds = (int) floor($remaining);
                $micros = (int) (($remaining - $seconds) * 1000000);
                $ready = @stream_select($read, $write, $except, $seconds, $micros);
                if ($ready === false) {
                    throw new ChromeProtocolError('The browser socket could not accept a command.');
                }
                if ($ready === 0) {
                    continue;
                }
                $n = @fwrite($this->socket, substr($frame, $written, 65536));
                if ($n === false || ($n === 0 && feof($this->socket))) {
                    throw new ChromeProtocolError('The connection to Chrome closed while sending a command.');
                }
                $written += $n;
            }
        } finally {
            if (is_resource($this->socket)) {
                stream_set_blocking($this->socket, $blocking);
            }
        }
    }

    /**
     * @throws ChromeProtocolError
     */
    private function readExactly(int $bytes, float $deadline): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                // Its own type: whether silence is the browser's fault or the
                // page's is the caller's to say, and it is not the same answer
                // for a command that went unanswered and a page that never
                // finished loading.
                throw new ChromeTimedOut('Chrome stopped answering: '.strlen($buffer)." of {$bytes} bytes arrived before the timeout.");
            }

            $seconds = (int) floor($remaining);
            stream_set_timeout($this->socket, $seconds, max(1, (int) (($remaining - $seconds) * 1000000)));
            $chunk = @fread($this->socket, $bytes - strlen($buffer));

            // Asked before `feof`, and this order is the whole of it: PHP sets
            // the stream's end-of-file flag when a read times out, so `feof`
            // is true on a socket that is perfectly healthy and merely quiet.
            // Read the other way round, every silence was reported as Chrome
            // hanging up, the deadline below was never reached, and a page
            // that simply took its time was a browser thrown away and started
            // again.
            if (stream_get_meta_data($this->socket)['timed_out']) {
                continue;
            }

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new ChromeProtocolError('The connection to Chrome closed while reading a reply.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readHandshake(float $deadline): string
    {
        $headers = '';

        while (! str_contains($headers, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                throw new ChromeProtocolError('Chrome did not answer the WebSocket handshake in time.');
            }

            stream_set_timeout($this->socket, 1);
            $chunk = @fgets($this->socket, 1024);

            if ($chunk === false) {
                if (feof($this->socket)) {
                    throw new ChromeProtocolError('Chrome closed the connection during the WebSocket handshake.');
                }

                continue;
            }

            $headers .= $chunk;
            if (strlen($headers) > 32768) {
                throw new ChromeProtocolError('Browser handshake headers are too large.');
            }
        }

        return $headers;
    }
}
