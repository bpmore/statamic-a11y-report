<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return ReportDatabase::connectionName();
    }

    public function up(): void
    {
        Schema::table('a11y_scans', function (Blueprint $table) {
            // The reading-level dimension: whether it ran, with which engine
            // at which version, against which target, and once the scan has
            // finished, where the pages landed and the median page. Kept as
            // one record for the reason the criteria are: a report made
            // later reads what the scan was measured with, not what the
            // config says now. Null on a scan from before the dimension
            // existed, which is a different answer from "not measured".
            $table->json('readability')->nullable();
        });

        Schema::table('a11y_scan_pages', function (Blueprint $table) {
            // One page's reading: a band and the decimal behind it, or why
            // there is none. Null on a page that was not read at all.
            $table->json('readability')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('a11y_scans', function (Blueprint $table) {
            $table->dropColumn('readability');
        });

        Schema::table('a11y_scan_pages', function (Blueprint $table) {
            $table->dropColumn('readability');
        });
    }
};
