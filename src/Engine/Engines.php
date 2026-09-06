<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

use Bpmore\A11yGate\Accessibility\StaticAccessibilityChecker;
use Bpmore\A11yGate\Gate\GateSettings;
use Bpmore\A11yReport\Chrome\Browser;
use Bpmore\A11yReport\Chrome\DevTools;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Settings;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Addon;

/**
 * Builds the engines, and is the only thing that does.
 *
 * Two questions, and they are deliberately not the same one:
 *
 * - `configured()` is "what should a new scan run with", which is allowed to
 *   fall back. Asking for axe on a machine with no Chrome scans with the PHP
 *   checker rather than failing every page, and the scan row records the one
 *   that actually ran.
 * - `make()` is "build exactly this engine", which is never allowed to fall
 *   back. It is what a queued page uses, from the key on its own scan row.
 *   A page is read by the engine its scan says read it, or it is not read.
 *
 * That split is the whole point of this class. The engine used to be resolved
 * from the config file wherever it was needed, which was correct only while
 * every process agreed. `a11y:scan --engine=axe` without `--sync` sets the
 * config in the console process and queues the pages; the worker is another
 * process, reads the config file, and built the PHP checker. The row said axe
 * and carried axe's two dozen criteria, and the report printed "automated
 * checks found no failures" for criteria nothing had looked at.
 *
 * Instances are cached by key, because the axe engine holds a headless Chrome
 * open across pages: `Scans` is built once per queued page, and an engine
 * rebuilt with it would start and stop a browser for every page.
 */
final class Engines
{
    /** Every engine key this addon knows, in the order a message should list them. */
    public const KEYS = [PhpDomEngine::KEY, AxeEngine::KEY];

    /** @var array<string, ScanEngine> */
    private array $built = [];

    /** @var array<string, string> what each configured value settled on, so the warning is said once and not once per page */
    private array $settled = [];

    public function __construct(private readonly Container $app) {}

    /** Whether this is the name of an engine at all, whatever this machine can run. */
    public static function knows(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * The engine a new scan should run with: what the config asks for, or the
     * PHP checker with a warning when that cannot be had.
     */
    public function configured(): ScanEngine
    {
        $wanted = (string) config('statamic-a11y-report.engine', PhpDomEngine::KEY);

        return $this->make($this->settled[$wanted] ??= $this->settle($wanted));
    }

    /**
     * What a configured value comes to on this machine, said out loud once.
     *
     * Remembered rather than worked out again per call, because a fall back
     * that logged once per page would be a line in the log for every page of
     * every scan, which is how the warning stops being read.
     */
    private function settle(string $wanted): string
    {
        if ($wanted === AxeEngine::KEY) {
            if ($this->browser()->available()) {
                return AxeEngine::KEY;
            }

            // Scanning with the lesser engine beats failing every page, and it
            // is not a quiet swap: the scan row carries "php", and the report
            // says which criteria that engine can speak to.
            Log::warning('a11y-report: the axe engine needs Chrome and none was found; scanning with the PHP checker instead. Set A11Y_CHROME_PATH to the browser binary.');
        } elseif ($wanted !== PhpDomEngine::KEY) {
            Log::warning("a11y-report: there is no [{$wanted}] engine; scanning with the PHP checker instead.");
        }

        return PhpDomEngine::KEY;
    }

    /**
     * Exactly the engine this key names, and never another one.
     *
     * @throws EngineUnavailable when this machine cannot build it
     */
    public function make(string $key): ScanEngine
    {
        // A scan row written before there was more than one engine names none.
        $key = $key === '' ? PhpDomEngine::KEY : $key;

        if (isset($this->built[$key])) {
            return $this->built[$key];
        }

        if (! self::knows($key)) {
            throw new EngineUnavailable("This scan was run with the [{$key}] engine, which this addon does not have. Available: ".implode(', ', self::KEYS).'.');
        }

        return $this->built[$key] = $key === AxeEngine::KEY ? $this->axe() : $this->php();
    }

    /**
     * @throws EngineUnavailable
     */
    private function axe(): AxeEngine
    {
        $browser = $this->browser();

        if (! $browser->available()) {
            throw new EngineUnavailable('This scan was run with axe, and no Chrome was found here to run it with. Set A11Y_CHROME_PATH to the browser binary on every machine that works the queue.');
        }

        return new AxeEngine(
            new DevTools(
                $browser,
                (int) config('statamic-a11y-report.chrome.timeout', 30),
                (int) config('statamic-a11y-report.axe.settle_ms', 250),
            ),
            $this->reportStandard(),
            (bool) config('statamic-a11y-report.axe.best_practices', true),
        );
    }

    private function php(): PhpDomEngine
    {
        $settings = $this->app->make(GateSettings::class);

        return new PhpDomEngine(
            new StaticAccessibilityChecker,
            (string) (Addon::get('bpmore/statamic-a11y-gate')?->version() ?: 'dev'),
            $settings->standard,
            $settings->optedIn,
        );
    }

    private function browser(): Browser
    {
        return new Browser(config('statamic-a11y-report.chrome.binary'));
    }

    /**
     * The WCAG version the report is written against, which is also the set of
     * rules an engine that can choose should run.
     */
    private function reportStandard(): string
    {
        $configured = $this->app->make(Settings::class)->block('report')['standard'] ?? null;

        return is_string($configured) && isset(Wcag::STANDARDS[$configured]) ? $configured : 'wcag22aa';
    }
}
