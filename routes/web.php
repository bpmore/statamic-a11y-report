<?php

declare(strict_types=1);

use Bpmore\A11yReport\Statement\StatementRoute;
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
 */
$route = '/'.ltrim((string) config('statamic-a11y-report.statement.route', '/accessibility'), '/');

if ($route !== '/') {
    Site::all()
        ->map(fn ($site) => rtrim((string) parse_url((string) $site->url(), PHP_URL_PATH), '/'))
        ->unique()
        ->each(function (string $prefix) use ($route) {
            // A closure returning a view, so the template and the layout are
            // decided when the page is asked for rather than when routes boot.
            Route::statamic($prefix.$route, fn () => view(StatementRoute::view(), StatementRoute::data()))
                ->name('a11y-report.statement'.($prefix === '' ? '' : '.'.trim($prefix, '/')));
        });
}
