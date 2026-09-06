<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return ReportDatabase::connectionName();
    }

    public function up(): void
    {
        // Which engine found this problem, so a scan by another one cannot
        // close it. Two engines are two sets of answers: axe not looking for
        // something the gate's checker found is not evidence it was fixed, and
        // without this the first axe scan of a site marked every existing
        // issue fixed and the report said so in a sentence.
        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->string('engine', 16)->nullable()->index();
        });

        // What is already there was found by whichever engine last saw it.
        $connection = ReportDatabase::connectionName();

        DB::connection($connection)->statement(
            'update a11y_issue_states set engine = (select engine from a11y_scans where a11y_scans.id = a11y_issue_states.last_scan_id)',
        );

        // A row whose scan has been pruned still needs an engine, and before
        // this migration there was only one.
        DB::connection($connection)->table('a11y_issue_states')->whereNull('engine')->update(['engine' => 'php']);
    }

    public function down(): void
    {
        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->dropIndex(['engine']);
        });

        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
