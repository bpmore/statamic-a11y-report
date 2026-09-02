<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Statement;

/**
 * What the statement's own page renders with, decided per request.
 *
 * Statamic wants a closure for route data, so the closure is one line and
 * the decision lives here where a test can call it. The
 * layout is the one config names, else Statamic's system layout, else a
 * plain shell of this addon's own, because a site whose layout is Blade
 * with `@yield` (the first real site this ran on) has no `layout` view at
 * all, and a 500 on the accessibility statement is the one page that must
 * not fail. A site like that sets `statement.view` to a Blade template of
 * its own that calls the tag.
 */
final class StatementRoute
{
    public const FALLBACK_LAYOUT = 'a11y-report::statement-layout';

    public const DEFAULT_VIEW = 'a11y-report::statement';

    public static function view(): string
    {
        $view = config('statamic-a11y-report.statement.view');

        return is_string($view) && $view !== '' ? $view : self::DEFAULT_VIEW;
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'title' => 'Accessibility statement',
            'layout' => self::layout(),
        ];
    }

    public static function layout(): string
    {
        $configured = config('statamic-a11y-report.statement.layout');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $system = (string) config('statamic.system.layout', 'layout');

        return self::viewExists($system) ? $system : self::FALLBACK_LAYOUT;
    }

    /**
     * Asked of the finder rather than `View::exists()`, which Statamic's
     * test harness answers from its fake engine alone, and of the factory
     * the container holds now rather than the facade, which keeps the one it
     * first resolved. The finder is what both the real factory and the fake
     * one actually resolve through.
     */
    private static function viewExists(string $name): bool
    {
        try {
            app('view')->getFinder()->find($name);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
