<?php

declare(strict_types=1);

namespace Bpmore\A11yReport;

use Bpmore\A11yGate\Accessibility\StaticAccessibilityChecker;
use Bpmore\A11yGate\Gate\EntryRenderer;
use Bpmore\A11yGate\Gate\GateSettings;
use Bpmore\A11yReport\Engine\PhpDomEngine;
use Bpmore\A11yReport\Engine\ScanEngine;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Addon;
use Statamic\Providers\AddonServiceProvider;

/**
 * The addon: a config file, a database, an engine, and two commands.
 *
 * It depends on Accessibility Gate and does not repeat it. The renderer that
 * turns an entry into a page, the checker that reads the page, and the
 * settings that say which standard and which opt-in checks apply are all the
 * gate's, resolved from its bindings, so a page the gate refuses and a page
 * this addon flags are the same page for the same reason.
 *
 * There is no licence check here and there must never be one. Statamic
 * resolves the licence and shows its own banner; anything stronger would be a
 * code path whose failure stops a site being scanned.
 */
class ServiceProvider extends AddonServiceProvider
{
    public const PACKAGE = 'bpmore/statamic-a11y-report';

    protected $config = true;

    protected $commands = [
        Commands\Install::class,
        Commands\Scan::class,
    ];

    public function bootAddon()
    {
        // After the config file has merged, which is what decides whether
        // the addon's own SQLite connection is wanted at all.
        $this->app->make(ReportDatabase::class)->defineDefaultConnection();
    }

    public function register()
    {
        parent::register();

        $this->app->bind(EntryRenderer::class, fn ($app) => new EntryRenderer($app));

        $this->app->bind(ScanEngine::class, function ($app) {
            $settings = $app->make(GateSettings::class);
            $wanted = (string) config('statamic-a11y-report.engine', PhpDomEngine::KEY);

            if ($wanted !== PhpDomEngine::KEY) {
                // Said out loud rather than swapped quietly. The scan row will
                // carry "php" as its engine either way, so nothing downstream
                // can mistake the result for the fuller one.
                Log::warning("a11y-report: the [{$wanted}] engine is not available yet; scanning with the PHP checker instead.");
            }

            return new PhpDomEngine(
                new StaticAccessibilityChecker,
                (string) (Addon::get('bpmore/statamic-a11y-gate')?->version() ?: 'dev'),
                $settings->standard,
                $settings->optedIn,
            );
        });

        $this->app->bind(Scans::class, fn ($app) => new Scans(
            $app->make(ScanEngine::class),
            $app->make(EntryRenderer::class),
            $app->make(GateSettings::class)->standard->value,
            (array) config('statamic-a11y-report', []),
        ));
    }
}
