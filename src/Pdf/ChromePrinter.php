<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Pdf;

use Bpmore\A11yReport\Chrome\Browser;

/**
 * Headless Chrome, printing a local HTML file to a tagged PDF.
 *
 * Chrome's own command line rather than the DevTools protocol. Current
 * Chrome tags its printed PDFs by default (structure tree, headings, tables
 * with header cells, captions, language), verified by reading the output of
 * Chrome 151 on a real report, and the outline flag adds a document outline.
 * `--export-tagged-pdf` is passed anyway for versions that needed asking.
 * The wrapper this saves is a WebSocket client written in PHP to reach
 * `Page.printToPDF`, which offers nothing the flags do not for this document.
 *
 * Chrome does not always exit after writing the file: the new headless mode
 * keeps background services alive on some profiles. So this waits for a
 * complete file, one ending in %%EOF that has stopped growing, and stops
 * Chrome itself if it is still there. Framework-free; the binary and the
 * timeout are handed in.
 */
final class ChromePrinter
{
    /** @deprecated Kept as the address it was published at; `Browser` owns the list. */
    public const CANDIDATES = Browser::CANDIDATES;

    /**
     * @param  \Closure(string): void|null  $warn  told when a print had to give up Chrome's sandbox
     */
    public function __construct(
        private readonly ?string $binary = null,
        private readonly int $timeoutSeconds = 30,
        private readonly ?\Closure $warn = null,
    ) {}

    /** The first Chrome that exists, configured or found, or null. */
    public function binary(): ?string
    {
        return (new Browser($this->binary))->binary();
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * @throws ChromeUnavailable
     */
    public function print(string $htmlPath, string $pdfPath): void
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw new ChromeUnavailable('No Chrome or Chromium was found. Set A11Y_CHROME_PATH to the browser binary to produce PDFs.');
        }

        if (! is_file($htmlPath)) {
            throw new ChromeUnavailable("There is no HTML file at {$htmlPath} to print.");
        }

        $first = $this->run($binary, true, $htmlPath, $pdfPath);

        if ($first === null) {
            return;
        }

        // Chrome exited without a file rather than running out of time.
        // With the sandbox on that is what it does as root, and in a
        // container that will not let it make namespaces, so it is asked
        // once more without. See `Browser::flags()` for why it is on at all.
        // A print that failed for some other reason fails again the same way
        // and the first attempt's words are the ones reported.
        if ($first['exited']) {
            $second = $this->run($binary, false, $htmlPath, $pdfPath);

            if ($second === null) {
                if ($this->warn !== null) {
                    ($this->warn)('Chrome exited without printing with its sandbox on, which is what it does as root or in a container that cannot make namespaces. Printed again without the sandbox. Running as an ordinary user keeps it on.'.($first['stderr'] !== '' ? ' Chrome said: '.trim(substr($first['stderr'], -300)) : ''));
                }

                return;
            }
        }

        throw new ChromeUnavailable('Chrome did not produce a PDF within '.$this->timeoutSeconds.' seconds.'.($first['stderr'] !== '' ? ' '.trim(substr($first['stderr'], -300)) : ''));
    }

    /**
     * One attempt: null when the PDF is on disk and complete, otherwise
     * whether Chrome exited on its own and what it wrote to stderr.
     *
     * @return array{exited: bool, stderr: string}|null
     *
     * @throws ChromeUnavailable
     */
    private function run(string $binary, bool $sandbox, string $htmlPath, string $pdfPath): ?array
    {
        $profile = sys_get_temp_dir().'/a11y-report-chrome-'.bin2hex(random_bytes(4));

        // Checked first rather than silenced: the `@` operator no longer
        // stops a test runner's error handler turning the warning into a
        // failure, and a second attempt starts from a file the first left.
        if (is_file($pdfPath)) {
            unlink($pdfPath);
        }

        $command = implode(' ', array_map('escapeshellarg', [
            $binary,
            ...Browser::flags($sandbox),
            '--user-data-dir='.$profile,
            '--export-tagged-pdf',
            '--generate-pdf-document-outline',
            '--no-pdf-header-footer',
            '--print-to-pdf='.$pdfPath,
            'file://'.$htmlPath,
        ]));

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new ChromeUnavailable('Chrome could not be started.');
        }

        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $this->timeoutSeconds;
        $stderr = '';
        $lastSize = -1;
        $stableFor = 0;
        $exited = false;

        try {
            while (microtime(true) < $deadline) {
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);

                if (self::complete($pdfPath)) {
                    $size = filesize($pdfPath);
                    $stableFor = $size === $lastSize ? $stableFor + 1 : 0;
                    $lastSize = $size;

                    if (! $status['running'] || $stableFor >= 4) {
                        return null;
                    }
                }

                if (! $status['running']) {
                    $exited = true;
                    break;
                }

                usleep(250_000);
            }
        } finally {
            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, 9);
            }

            $stderr .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($process);
            Browser::removeDirectory($profile);
        }

        if (self::complete($pdfPath)) {
            return null;
        }

        if (is_file($pdfPath)) {
            unlink($pdfPath);
        }

        return ['exited' => $exited, 'stderr' => $stderr];
    }

    private static function complete(string $path): bool
    {
        clearstatcache(true, $path);

        if (! is_file($path) || filesize($path) < 16) {
            return false;
        }

        $handle = fopen($path, 'rb');
        fseek($handle, -16, SEEK_END);
        $tail = (string) fread($handle, 16);
        fclose($handle);

        return str_contains($tail, '%%EOF');
    }
}
