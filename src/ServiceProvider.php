<?php

declare(strict_types=1);

namespace Bpmore\A11yReport;

use Bpmore\A11yGate\Gate\EntryRenderer;
use Bpmore\A11yReport\Document\Brand;
use Bpmore\A11yReport\Document\ReportBuilder;
use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Engine\Engines;
use Bpmore\A11yReport\Engine\PhpDomEngine;
use Bpmore\A11yReport\Engine\ScanEngine;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanSchedule;
use Bpmore\A11yReport\Statement\StatementBuilder;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Support\VueSafe;
use Bpmore\A11yReport\Trends\Overview;
use Bpmore\A11yReport\Trends\TrendChart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Statamic\Facades\Addon;
use Statamic\Facades\Permission;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\Utility;
use Statamic\Facades\YAML;
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
        Commands\Report::class,
        Commands\Scan::class,
        Commands\StatementRefresh::class,
    ];

    protected $tags = [
        Tags\A11y::class,
    ];

    protected $widgets = [
        Widgets\AccessibilityReport::class,
    ];

    protected $viewNamespace = 'a11y-report';

    /**
     * The recurring scan. `scan.schedule` sat in the config file from the
     * first release with nothing reading it, so every install was told it
     * scanned weekly and none of them did.
     *
     * Statamic's own hook, which its boot sequence calls only when running in
     * console. That is the right gate: the scheduler is a console concern and
     * registering it on every web request is work nobody reads.
     *
     * The registration itself waits for the application to finish booting.
     * Statamic's chain calls this hook several steps *before* it merges the
     * addon's config, so reading `scan.schedule` here gets null and quietly
     * schedules nothing, which is the exact bug this method exists to fix,
     * reintroduced one line lower down.
     */
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
    {
        $this->app->booted(fn () => ScanSchedule::register(
            $schedule,
            (array) config('statamic-a11y-report.scan', []),
        ));
    }

    /**
     * The settings screen, with the brand section added in PHP.
     *
     * An asset picker with no container throws while it renders, and a site
     * with two containers gives it no obvious one, so a brand section
     * written into the form's own file would return a server error for the
     * whole settings screen on any site that keeps pictures in more than one
     * place. The picker appears when a container can be named and a plain
     * text field stands in when one cannot, which is a choice only PHP can
     * make. Registered after the parent, whose own decision about whether to
     * boot at all is the guard here.
     */
    protected function bootSettingsBlueprint()
    {
        parent::bootSettingsBlueprint();

        if (! $this->getAddon()->hasSettingsBlueprint()) {
            return $this;
        }

        $path = $this->getAddon()->directory().'resources/blueprints/settings.yaml';

        $this->registerSettingsBlueprint(fn () => Brand::addSettingsSection(
            YAML::file($path)->parse(),
            Brand::container(config('statamic-a11y-report.report.brand.container')),
        ));

        return $this;
    }

    public function bootAddon()
    {
        // After the config file has merged, which is what decides whether
        // the addon's own SQLite connection is wanted at all.
        $this->app->make(ReportDatabase::class)->defineDefaultConnection();

        // `@plain($x)` for any value that reaches a view Vue will compile. See
        // `VueSafe` for why Blade's own escaping is not enough there.
        Blade::directive('plain', fn ($expression) => "<?php echo \\Bpmore\\A11yReport\\Support\\VueSafe::text({$expression}); ?>");

        // Seeing the report is Statamic's own "access utility" permission.
        // Running a scan is separate: it makes the site render every page.
        Permission::extend(function () {
            Permission::group('a11y-report', 'Accessibility Report', function () {
                Permission::register('run accessibility scans')
                    ->label('Run accessibility scans')
                    ->description('Start a scan of every page from the control panel. Scans render the whole site on the queue.');
                Permission::register('manage accessibility issues')
                    ->label('Manage accessibility issues')
                    ->description('Change the status, assignee and notes of issues in the remediation queue.');
                Permission::register('assess accessibility criteria')
                    ->label('Assess accessibility criteria')
                    ->description('Record a manual assessment of a WCAG success criterion on the worksheet. What is recorded is printed in the conformance report under the assessor\'s name.');
                Permission::register('generate accessibility reports')
                    ->label('Generate accessibility reports')
                    ->description('Produce a conformance document from a completed scan. The document names who generated it.');
            });
        });

        // The gate's sidebar panel, with this page's open issues from the last
        // scan beneath the gate's own result, so the two agree in the one
        // place an author looks.
        \Bpmore\A11yGate\Panel\PanelExtensions::register(new Panel\OpenIssuesForEntry(
            $this->app->make(ReportDatabase::class),
            $this->app->make(Settings::class),
        ));

        Utility::extend(fn () => Utility::register(
            Utility::make('a11y-report')
                ->title('Accessibility Report')
                ->navTitle('Accessibility Report')
                ->icon('pulse')
                ->description('Every scan, what it found, and how that is changing.')
                ->view('a11y-report::utilities.report', fn (Request $request) => $this->utilityData($request))
                ->routes(function ($router) {
                    $router->post('run', Http\Controllers\RunScanController::class)->name('run');
                    $router->post('reports', Http\Controllers\GenerateReportController::class)->name('reports.generate');
                    $router->get('reports/{uuid}/{format}', Http\Controllers\DownloadReportController::class)->name('reports.download');
                    $router->get('issues', Http\Controllers\IssuesController::class)->name('issues');
                    $router->post('issues', Http\Controllers\UpdateIssuesController::class)->name('issues.update');
                    $router->get('mark', Http\Controllers\MarkController::class)->name('mark');
                    $router->get('criteria', Http\Controllers\CriteriaController::class)->name('criteria');
                    $router->post('criteria', Http\Controllers\SaveCriteriaController::class)->name('criteria.save');
                })
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function utilityData(Request $request): array
    {
        $sites = Site::all()->map(fn ($site) => ['handle' => $site->handle(), 'name' => $site->name()])->values()->all();
        $site = $request->query('site');
        $site = is_string($site) && Site::get($site) ? $site : null;

        $overview = new Overview($this->app->make(ReportDatabase::class), $site);
        $installed = $overview->installed();
        $trend = $installed ? $overview->trend(90) : [];

        return [
            'installed' => $installed,
            'ownsConnection' => $this->app->make(ReportDatabase::class)->ownsConnection(),
            'connection' => ReportDatabase::connectionName(),
            'sites' => $sites,
            'site' => $site,
            'overview' => $overview,
            'latest' => $installed ? $overview->latest() : null,
            'history' => $installed ? $overview->history(20) : collect(),
            'byImpact' => $installed ? $overview->openByImpact() : [],
            'openTotal' => $installed ? $overview->openTotal() : 0,
            'oldestOpenDays' => $installed ? $overview->oldestOpenDays() : null,
            'trend' => $trend,
            'chart' => TrendChart::render($trend),
            'canRun' => (bool) User::current()?->can('run accessibility scans'),
            'runUrl' => cp_route('utilities.a11y-report.run'),
            'indexUrl' => cp_route('utilities.a11y-report.issues'),
            'criteriaUrl' => cp_route('utilities.a11y-report.criteria'),
            'overviewUrl' => cp_route('utilities.index').'/a11y-report',
            'settingsUrl' => Addon::get(Settings::PACKAGE)?->settingsUrl(),
            'canGenerate' => (bool) User::current()?->can('generate accessibility reports'),
            'generateUrl' => cp_route('utilities.a11y-report.reports.generate'),
            'hasCompleteScan' => $installed && $overview->history(1)->first()?->status === \Bpmore\A11yReport\Models\Scan::COMPLETE,
            'reports' => $installed ? \Bpmore\A11yReport\Models\Report::query()->when($site !== null, fn ($q) => $q->where('site', $site))->with('scan')->orderByDesc('id')->limit(20)->get() : collect(),
            'queueIsSync' => config('queue.default') === 'sync',
            'queueConnection' => (string) config('queue.default'),
            'stale' => $installed ? $overview->stale((int) config('statamic-a11y-report.scan.stale_after_minutes', 10)) : null,
            'schedule' => $installed ? $overview->schedule() : null,
            'engine' => (string) config('statamic-a11y-report.engine', PhpDomEngine::KEY),
        ];
    }

    public function register()
    {
        parent::register();

        $this->app->bind(EntryRenderer::class, fn ($app) => new EntryRenderer($app));

        // One place builds engines, and it answers two different questions:
        // what a new scan should run with, and how to rebuild exactly the one
        // a scan row already names. See `Engines`.
        $this->app->singleton(Engines::class, fn ($app) => new Engines($app));

        // A singleton, not a binding. The axe engine holds a headless Chrome
        // open across pages because starting one costs the better part of a
        // second, and `Scans` is resolved once per queued page: bound rather
        // than shared, every page would start and stop its own browser and the
        // saving would be exactly reversed.
        $this->app->singleton(ScanEngine::class, fn ($app) => $app->make(Engines::class)->configured());

        $this->app->bind(Settings::class, fn () => new Settings);

        $this->app->bind(ReportBuilder::class, fn ($app) => new ReportBuilder(
            $app->make(ScanEngine::class),
            $app->make(Settings::class)->block('report'),
        ));

        $this->app->bind(\Bpmore\A11yReport\Pdf\ChromePrinter::class, fn () => new \Bpmore\A11yReport\Pdf\ChromePrinter(
            config('statamic-a11y-report.chrome.binary'),
            (int) config('statamic-a11y-report.chrome.timeout', 30),
        ));

        $this->app->bind(ReportWriter::class, fn ($app) => new ReportWriter(
            $app,
            $app->make(ReportBuilder::class),
            $app->make(\Bpmore\A11yReport\Pdf\ChromePrinter::class),
        ));

        $this->app->bind(\Bpmore\A11yReport\Worksheet\Worksheet::class, fn ($app) => new \Bpmore\A11yReport\Worksheet\Worksheet(
            new \Bpmore\A11yReport\Document\ScanEvidence($app->make(ScanEngine::class)),
        ));

        $this->app->bind(StatementBuilder::class, fn ($app) => new StatementBuilder(
            $app->make(ReportDatabase::class),
            $app->make(ReportWriter::class),
            $app->make(Settings::class)->block('statement'),
        ));

        $this->app->bind(Scans::class, fn ($app) => new Scans(
            $app->make(Engines::class),
            $app->make(EntryRenderer::class),
            $app->make(Settings::class)->effective(),
        ));
    }
}
