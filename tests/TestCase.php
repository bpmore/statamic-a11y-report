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
    use FakesViews;
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
        parent::setUp();

        config()->set('statamic-a11y-report.connection', 'a11y_testing');
    }
}
