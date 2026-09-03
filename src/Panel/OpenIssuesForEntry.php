<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Panel;

use Bpmore\A11yReport\Document\Brand;
use Bpmore\A11yReport\Engine\Fingerprint;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Queue\IssueQuery;
use Bpmore\A11yReport\Settings;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Contracts\Entries\Entry;

/**
 * What the gate's panel says about this page on the report's behalf: the
 * open issues the last scan left on it, and a way into the queue filtered to
 * this page, so the gate and the queue agree with each other in the one
 * place an author looks.
 *
 * Nothing to say for a page no scan has read, because "no open issues" for
 * a page nobody looked at would be the silent zero. A scanned page with
 * nothing open is told so, because that is news an author can use.
 *
 * The block carries the same mark as the cover of a report, when the site
 * owner has set one, so an author can see at a glance whose report this is.
 * Only the mark: the accent colour stays on paper. It is validated against
 * the white page a report is printed on, and no single colour can clear
 * 4.5:1 against both control-panel themes, so a heading tinted with it would
 * fail contrast in one of them for every customer who set one.
 */
final class OpenIssuesForEntry
{
    public const SHOWN = 3;

    public function __construct(
        private readonly ReportDatabase $database,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Entry $entry): ?array
    {
        if (! $this->database->isInstalled()) {
            return null;
        }

        $site = (string) $entry->locale();
        $path = (string) $entry->url();

        if ($path === '') {
            return null;
        }

        $lastRead = ScanPage::where('site', $site)->where('path', $path)->where('status', ScanPage::SCANNED)->orderByDesc('id')->first();

        if ($lastRead === null) {
            return null;
        }

        $issues = (new IssueQuery(['site' => $site, 'path' => $path]))->query()->limit(self::SHOWN + 1)->get();
        $total = (new IssueQuery(['site' => $site, 'path' => $path]))->query()->count();

        $when = ($lastRead->scanned_at ?? $lastRead->created_at)?->diffForHumans() ?? 'at an unrecorded time';

        if ($total === 0) {
            return [
                'heading' => 'No open issues from the last scan',
                'lines' => ["Read {$when}. A scan reads the page as it was published, not as it is here."],
                'mark' => $this->mark($site),
            ];
        }

        $lines = $issues->take(self::SHOWN)->map(fn ($i) => $i->message.($i->pointer ? ' ('.$i->pointer.')' : ''))->all();

        if ($total > self::SHOWN) {
            $lines[] = 'And '.($total - self::SHOWN).' more.';
        }

        $lines[] = "From the scan of {$when}.";

        return [
            'heading' => $total === 1 ? '1 open issue from the last scan' : "{$total} open issues from the last scan",
            'lines' => $lines,
            'link' => [
                'url' => cp_route('utilities.a11y-report.issues', ['site' => $site, 'path' => $path]),
                'text' => 'Open in the queue',
            ],
            'mark' => $this->mark($site),
        ];
    }

    /**
     * The site's mark, as an address the panel can fetch, or null.
     *
     * The picture is served from a route rather than embedded here. A data
     * URI in this block would be carried in the page data of every entry an
     * author opens, and again on the next entry; a URL is fetched once and
     * cached against the digest of the picture.
     *
     * Resolved here rather than trusted from a setting, so a logo the report
     * would refuse to print is a logo the panel does not wear either: one
     * with no words to stand in for it, above all, since the panel is the one
     * place where that would be this product failing on its own screen.
     *
     * @return array{url: string, alt: string}|null
     */
    private function mark(string $site): ?array
    {
        $brand = Brand::resolve((array) ($this->settings->block('report')['brand'] ?? []), $site);

        if ($brand['logo'] === null) {
            return null;
        }

        return [
            'url' => cp_route('utilities.a11y-report.mark', ['site' => $site]),
            'alt' => (string) $brand['logo']['alt'],
        ];
    }
}
