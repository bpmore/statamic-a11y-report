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
            // The success criteria the engine that ran this scan could cite,
            // recorded while it was running rather than asked for afterwards.
            //
            // The report used to ask whichever engine was bound when the
            // document was generated, which was right while there was one. A
            // report generated from an axe scan on a site configured for the
            // PHP checker then said no criterion had been evaluated
            // automatically, of a scan that evaluated two dozen. The same rule
            // as the mark on the cover and the remediation policy: the record
            // carries what it was made with.
            $table->json('criteria')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('a11y_scans', function (Blueprint $table) {
            $table->dropColumn('criteria');
        });
    }
};
