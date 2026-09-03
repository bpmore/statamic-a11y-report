<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Document\Brand;
use Bpmore\A11yReport\Settings;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The mark on a report's cover, served to the control panel.
 *
 * Served rather than embedded. The document embeds it, because headless
 * Chrome fetches nothing at all for print, but the panel is an ordinary page
 * in an ordinary browser: a data URI there would put the whole picture into
 * the page data of every entry an author opens, and the same bytes again on
 * the next entry. A URL is fetched once and cached.
 *
 * Behind the control panel's own authentication, like every other route this
 * addon adds. The mark is not a secret, and it is not published either: it is
 * whatever the site owner chose, and where they keep it is their business.
 */
class MarkController extends CpController
{
    public function __invoke(Request $request)
    {
        $site = $request->query('site');
        $site = is_string($site) && Site::get($site) !== null ? $site : null;

        $brand = Brand::resolve(app(Settings::class)->block('report')['brand'] ?? [], $site);

        abort_if($brand['logo'] === null, 404);

        return response(Brand::bytes($brand['logo']), 200, [
            'Content-Type' => $brand['logo']['media_type'],
            // The digest of the picture itself, so a replaced logo is a
            // different address to the browser and an unchanged one is not
            // fetched twice. Private, because this is behind a login.
            'ETag' => '"'.$brand['logo']['sha256'].'"',
            'Cache-Control' => 'private, max-age=3600',
            // A drawing is markup, and a browser that decided for itself what
            // this file was could decide something other than a picture.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
