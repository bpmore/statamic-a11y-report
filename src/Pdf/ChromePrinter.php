<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Pdf;

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
    public const CANDIDATES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        'google-chrome',
        'google-chrome-stable',
        'chromium',
        'chromium-browser',
        'chrome',
    ];

    public function __construct(
        private readonly ?string $binary = null,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /** The first Chrome that exists, configured or found, or null. */
    public function binary(): ?string
    {
        $candidates = $this->binary !== null && $this->binary !== '' ? [$this->binary] : self::CANDIDATES;

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/')) {
                if (is_executable($candidate)) {
                    return $candidate;
                }

                continue;
            }

            $found = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($found !== '') {
                return $found;
            }
        }

        return null;
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

        $profile = sys_get_temp_dir().'/a11y-report-chrome-'.bin2hex(random_bytes(4));
        @unlink($pdfPath);

        $command = implode(' ', array_map('escapeshellarg', [
            $binary,
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--no-first-run',
            '--disable-extensions',
            '--disable-sync',
            '--disable-background-networking',
            '--disable-component-update',
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

        try {
            while (microtime(true) < $deadline) {
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);

                if (self::complete($pdfPath)) {
                    $size = filesize($pdfPath);
                    $stableFor = $size === $lastSize ? $stableFor + 1 : 0;
                    $lastSize = $size;

                    if (! $status['running'] || $stableFor >= 4) {
                        return;
                    }
                }

                if (! $status['running']) {
                    break;
                }

                usleep(250_000);
            }
        } finally {
            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, 9);
            }

            fclose($pipes[2]);
            proc_close($process);
            self::removeDirectory($profile);
        }

        if (self::complete($pdfPath)) {
            return;
        }

        @unlink($pdfPath);

        throw new ChromeUnavailable('Chrome did not produce a PDF within '.$this->timeoutSeconds.' seconds.'.($stderr !== '' ? ' '.trim(substr($stderr, -300)) : ''));
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

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
