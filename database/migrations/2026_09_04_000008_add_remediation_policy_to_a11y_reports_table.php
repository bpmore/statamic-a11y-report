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
        Schema::table('a11y_reports', function (Blueprint $table) {
            // The promise that was in force when this document was filed. A
            // report kept as evidence has to stay readable after the targets
            // change, and "fixed within 7 days" means nothing in a document
            // that does not say whose seven days those were. The same rule as
            // the mark on the cover: the report carries what it was made with
            // rather than pointing at a setting that has moved on.
            $table->json('remediation_policy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('a11y_reports', function (Blueprint $table) {
            $table->dropColumn('remediation_policy');
        });
    }
};
