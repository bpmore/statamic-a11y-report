<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Remediation\Policy;
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
        // The exception register. "Won't fix" was a status and a free note,
        // which in a compliance context is an accepted failure with nothing
        // recorded about who accepted it or for how long, and that is the
        // first thing an auditor asks about. It is on this table because an
        // acceptance is a decision about a fingerprint and this is already
        // the table of decisions about fingerprints.
        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->text('exception_reason')->nullable();
            $table->string('exception_by')->nullable();
            $table->timestamp('exception_at')->nullable();
            // Every acceptance expires. Indexed because "what has run out" is
            // asked on every page of the queue and in every report.
            $table->timestamp('exception_expires_at')->nullable()->index();
        });

        // Anything already accepted gets a review date rather than either of
        // the two quiet answers: grandfathering it forever, or reopening the
        // lot on the morning somebody upgrades. What was known about it is
        // carried across, and the date is counted from the upgrade because
        // nobody agreed to a review before there was one to agree to.
        $days = (int) (config('statamic-a11y-report.report.remediation.exception_days') ?: Policy::DEFAULT_EXCEPTION_DAYS);

        DB::connection(ReportDatabase::connectionName())
            ->table('a11y_issue_states')
            ->where('status', IssueState::WONT_FIX)
            ->update([
                'exception_reason' => DB::raw('note'),
                'exception_by' => DB::raw('updated_by'),
                'exception_at' => DB::raw('updated_at'),
                'exception_expires_at' => now()->copy()->addDays($days),
            ]);
    }

    public function down(): void
    {
        // The index goes first. SQLite refuses to drop a column an index still
        // names, with an error about the index rather than about the column.
        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->dropIndex(['exception_expires_at']);
        });

        Schema::table('a11y_issue_states', function (Blueprint $table) {
            $table->dropColumn(['exception_reason', 'exception_by', 'exception_at', 'exception_expires_at']);
        });
    }
};
