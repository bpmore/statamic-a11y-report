<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Site;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return ReportDatabase::connectionName();
    }

    /**
     * A scan of every site on an install with one site is that site's scan,
     * and from 1.2.2 its row says so. The rows from before said nothing, so
     * without this the first scan after upgrading would compare against no
     * previous scan and print "first scan" into a history that is anything
     * but, and a widget or report asked for the site by name would not see
     * a day of it.
     *
     * Only where the pages prove it. A scan is named for the install's one
     * site when no page it read belongs to another, which is every scan on
     * an install that has only ever had one site and none on an install
     * that used to have more: a row must never claim less than it covered.
     * A report follows its scan.
     */
    public function up(): void
    {
        $sites = Site::all();

        if ($sites->count() !== 1) {
            return;
        }

        $handle = (string) $sites->first()->handle();
        $connection = DB::connection(ReportDatabase::connectionName());

        $connection->table('a11y_scans')
            ->whereNull('site')
            ->whereNotExists(function ($q) use ($handle) {
                $q->selectRaw('1')
                    ->from('a11y_scan_pages')
                    ->whereColumn('a11y_scan_pages.scan_id', 'a11y_scans.id')
                    ->where('a11y_scan_pages.site', '!=', $handle);
            })
            ->update(['site' => $handle]);

        $connection->table('a11y_reports')
            ->whereNull('site')
            ->whereExists(function ($q) use ($handle) {
                $q->selectRaw('1')
                    ->from('a11y_scans')
                    ->whereColumn('a11y_scans.id', 'a11y_reports.scan_id')
                    ->where('a11y_scans.site', $handle);
            })
            ->update(['site' => $handle]);
    }

    /**
     * Nothing. A name this put on a row is true, and a row named by a
     * narrowed scan before this ran is indistinguishable from one this
     * named; clearing both would make every scan of the site anonymous.
     */
    public function down(): void {}
};
