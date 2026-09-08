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

    /**
     * The name of the file a browser's owner writes its pid into, inside the
     * profile directory it told Chrome to use.
     */
    public const OWNER_FILE = '.a11y-owner';

    /** The prefix every profile directory this addon makes begins with. */
    public const PROFILE_PREFIX = 'a11y-report-axe-';

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

    /**
     * Kill the browsers this machine was left holding, and clear their
     * profiles.
     *
     * `DevTools` closes its browser in a destructor, which covers the scan
     * that ends, the scan that throws, and the worker that stops. It cannot
     * cover the process that is killed outright, and that is the one that
     * matters: an axe scan on a small server is exactly the process the kernel
     * picks when memory runs out, and PHP that is killed runs no destructor.
     * The browser it started keeps running, holding its share of the memory
     * that ran out, for ever.
     *
     * Found on a live site: three hundred and twenty-five orphaned Chrome
     * processes from scans that had died hours earlier, holding six and a half
     * of eight gigabytes. The site and its SSH both stopped answering.
     *
     * Only ours, and only the abandoned ones. A profile carries the pid of the
     * process that owns it, so a browser whose owner is still alive is a
     * second worker's and is left alone: killing that would break a scan that
     * is working, which is worse than the leak. A profile with no owner file
     * is one whose owner died before it could write one, and it has no browser
     * to kill.
     *
     * Best effort throughout. This runs before a scan, and a scan must not
     * fail because tidying up did.
     */
    public static function sweepAbandoned(): void
    {
        // `pgrep` and signals. Windows has neither, and there is nothing to
        // sweep there that this could do safely.
        if (! function_exists('posix_kill') || stripos(PHP_OS_FAMILY, 'win') === 0) {
            return;
        }

        $profiles = glob(sys_get_temp_dir().'/'.self::PROFILE_PREFIX.'*', GLOB_ONLYDIR) ?: [];

        foreach ($profiles as $profile) {
            $owner = @file_get_contents($profile.'/'.self::OWNER_FILE);

            // Still owned by a living process: somebody else's browser, and
            // the one thing worse than the leak is killing a working scan.
            if (is_string($owner) && ctype_digit(trim($owner)) && posix_kill((int) trim($owner), 0)) {
                continue;
            }

            self::killUsing($profile);
            self::removeDirectory($profile);
        }
    }

    /**
     * Kill every process holding this profile open.
     *
     * Matched on the profile path, which is this addon's own directory with
     * eight random characters on the end, so nothing else on the machine can
     * match it. Chrome's children carry the same `--user-data-dir` on their
     * own command lines, which is what makes them findable at all once their
     * parent is gone.
     */
    private static function killUsing(string $profile): void
    {
        $found = @shell_exec('pgrep -f '.escapeshellarg('user-data-dir='.$profile).' 2>/dev/null');

        $pids = array_filter(
            array_map('trim', explode("\n", (string) $found)),
            fn ($pid) => $pid !== '' && ctype_digit($pid) && (int) $pid !== getmypid(),
        );

        foreach ($pids as $pid) {
            @posix_kill((int) $pid, SIGTERM);
        }

        if ($pids === []) {
            return;
        }

        // Chrome does leave on a TERM, and a moment to do it means the tree
        // comes down with it rather than in pieces.
        usleep(500_000);

        foreach ($pids as $pid) {
            @posix_kill((int) $pid, SIGKILL);
        }
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
