<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Panel;

use Bpmore\A11yReport\Engine\Fingerprint;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Queue\IssueQuery;
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
 */
final class OpenIssuesForEntry
{
    public const SHOWN = 3;

    public function __construct(private readonly ReportDatabase $database) {}

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
        ];
    }
}
