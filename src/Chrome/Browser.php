<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * Where Chrome is, and how to start one.
 *
 * Two things in this addon drive a browser: the PDF printer, which asks
 * Chrome's command line to print a file, and the axe engine, which talks the
 * DevTools protocol to a running one. They looked for the binary in the same
 * places by copying the list, and a list of paths kept in two files is a
 * support ticket about one platform that was fixed in one of them.
 *
 * Framework-free. The configured path is handed in.
 */
final class Browser
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

    /**
     * Flags shared by both users: no GPU, no profile of its own to inherit,
     * and nothing that phones home. A scan of a whole site starts this often,
     * and an update check per page is somebody's network bill.
     */
    public const FLAGS = [
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        '--no-first-run',
        '--disable-extensions',
        '--disable-sync',
        '--disable-background-networking',
        '--disable-component-update',
    ];

    public function __construct(private readonly ?string $configured = null) {}

    /** The first Chrome that exists, configured or found, or null. */
    public function binary(): ?string
    {
        $candidates = $this->configured !== null && $this->configured !== '' ? [$this->configured] : self::CANDIDATES;

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

    /** Delete a temporary profile directory and everything under it. */
    public static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
