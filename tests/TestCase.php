<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Tests;

use Bpmore\A11yReport\ServiceProvider;
use ReflectionClass;
use Statamic\Addons\Manifest;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesViews;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A booted Statamic with both addons in it.
 *
 * Statamic's harness knows how to boot one addon: the one under test. This
 * addon depends on Accessibility Gate, whose provider finds its own manifest
 * entry by namespace and fails to boot without one, so the gate is put into
 * the manifest and the provider list here, the same way the harness does it
 * for the addon under test.
 */
abstract class TestCase extends AddonTestCase
{
    use FakesViews {
        withFakeViews as private fakeTheViews;
    }
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [\Bpmore\A11yGate\ServiceProvider::class]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Testbench ships no app key, and Statamic's boot goes through the
        // session middleware, which encrypts.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Two users is a Pro feature, and one test needs a second user to
        // prove the permission check refuses somebody who cannot run a scan.
        $app['config']->set('statamic.editions.pro', true);

        $gate = dirname((new ReflectionClass(\Bpmore\A11yGate\ServiceProvider::class))->getFileName());

        $app->make(Manifest::class)->manifest['bpmore/statamic-a11y-gate'] = [
            'id' => 'bpmore/statamic-a11y-gate',
            'slug' => 'statamic-a11y-gate',
            'version' => 'dev-main',
            'namespace' => 'Bpmore\\A11yGate',
            'autoload' => 'src/',
            'provider' => \Bpmore\A11yGate\ServiceProvider::class,
        ];

        // The report's tables, and the queue's batch records, in memory. One
        // connection for both, so the whole scan pipeline runs with nothing on
        // disk. The addon's own connection setting is applied in `setUp()`,
        // after the config file has merged: the merge is shallow and would
        // otherwise be overridden wholesale.
        $app['config']->set('database.connections.a11y_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.batching.database', 'a11y_testing');
    }

    protected function setUp(): void
    {
        // The gate's panel providers are process-wide, and this addon's
        // provider registers one every time an application boots. Cleared
        // before the boot below, so every test has exactly one.
        \Bpmore\A11yGate\Panel\PanelExtensions::flush();

        parent::setUp();

        config()->set('statamic-a11y-report.connection', 'a11y_testing');

        // The settings screen saves to a real file in the app's resource
        // path, and a test that saves one would otherwise decide the answer
        // for every test after it. Cleared before each, so every test starts
        // from "nobody has opened the settings screen".
        \Illuminate\Support\Facades\File::delete(resource_path('addons/statamic-a11y-report.yaml'));
    }

    /**
     * The fake view finder the scans render entries through starts with no
     * view namespaces at all, so a test that fakes the site's templates and
     * then opens one of this addon's own views would get "no hint path",
     * first for this addon and then for Statamic's control panel layout.
     * Every namespace the real finder knew is put back on the fake one here,
     * at the lowest level the trait offers so both entry points get it.
     */
    public function withFakeViews()
    {
        $hints = $this->app['view']->getFinder()->getHints();

        $this->fakeTheViews();

        foreach ($hints as $namespace => $paths) {
            $this->fakeViewFinder->addNamespace($namespace, $paths);
        }
    }
}
