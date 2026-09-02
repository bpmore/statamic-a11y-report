<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Widgets;

use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Trends\Overview;
use Bpmore\A11yReport\Trends\TrendChart;
use Statamic\Facades\Utility;
use Statamic\Widgets\Widget;

/**
 * Open issues by impact and a 30-day line, on the dashboard.
 *
 * The thing that makes the product visible daily. Add `accessibility_report`
 * to the widgets in `config/statamic/cp.php`. Optional `site` config narrows
 * it to one site; the default is every site.
 */
class AccessibilityReport extends Widget
{
    public function html()
    {
        $overview = new Overview(app(ReportDatabase::class), $this->config('site'));

        $trend = $overview->installed() ? $overview->trend(30) : [];

        return view('a11y-report::widgets.report', [
            'overview' => $overview,
            'byImpact' => $overview->installed() ? $overview->openByImpact() : [],
            'latest' => $overview->installed() ? $overview->latest() : null,
            'chart' => TrendChart::render($trend, 320, 64, sparkline: true),
            'url' => Utility::find('a11y-report')?->url(),
        ]);
    }
}
