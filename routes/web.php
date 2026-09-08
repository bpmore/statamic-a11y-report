<?php

declare(strict_types=1);

use Bpmore\A11yReport\Http\Controllers\StatementController;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Site;

/*
 * The accessibility statement's own page, once per site.
 *
 * A subdirectory site (`/fr`) needs its own prefixed route; a domain-based
 * site shares the path and the request's host picks the site, as it does
 * for every other page. The template is one line: the `a11y:statement` tag,
 * which is also what a site uses to put the statement inside a page of its
 * own instead.
 *
 * A controller and never a closure. Laravel writes route defaults into
 * `bootstrap/cache/routes-v7.php` with `var_export`, which cannot express a
 * closure, and `route:cache` writes the broken file without complaining. See
 * `StatementController` for what that did to a live site in 1.0.0.
 */
$route = '/'.ltrim((string) config('statamic-a11y-report.statement.route', '/accessibility'), '/');

if ($route !== '/') {
    Site::all()
        ->map(fn ($site) => rtrim((string) parse_url((string) $site->url(), PHP_URL_PATH), '/'))
        ->unique()
        ->each(function (string $prefix) use ($route) {
            Route::get($prefix.$route, StatementController::class)
                ->name('a11y-report.statement'.($prefix === '' ? '' : '.'.trim($prefix, '/')));
        });
}
