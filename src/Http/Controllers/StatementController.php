<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Statement\StatementRoute;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\FrontendController;
use Statamic\View\View;

/**
 * The accessibility statement's own page.
 *
 * A controller class and not a closure, which is the whole reason it exists.
 * Statamic's `Route::statamic()` takes a closure for the view, stores it as a
 * route default, and Laravel writes route defaults into
 * `bootstrap/cache/routes-v7.php` with `var_export`, which cannot express a
 * closure. `php artisan route:cache` then wrote a file that fatals with "Call
 * to undefined method Closure::__set_state()" on the first request, and it did
 * not fail while caching, so nothing warned anybody. `route:cache` is part of
 * `optimize` and of the deploy script Forge ships: in 1.0.0 this took down
 * every page of a live site, the control panel included, on deploy.
 *
 * Freezing the view and the layout into the route's defaults as plain strings
 * would cache, and would be wrong. The layout is chosen by asking whether the
 * site has one, and the answer has to be asked for when the page is asked for:
 * a value decided while routes boot is decided before the view finder knows
 * what the site has. A controller keeps the decision at request time and is a
 * class name in the cache file, which `var_export` has no trouble with.
 *
 * It extends Statamic's own front-end controller for that class's middleware,
 * so the statement is a Statamic page like any other: the right site, the
 * site's protection, and the site's static cache.
 */
final class StatementController extends FrontendController
{
    public function __invoke(Request $request)
    {
        $data = StatementRoute::data();

        $view = app(View::class)
            ->template(StatementRoute::view())
            ->layout($data['layout'])
            ->with($data);

        return response($view->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
