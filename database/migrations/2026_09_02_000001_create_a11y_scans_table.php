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
        Schema::create('a11y_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null means every site. A report is per site because legal
            // exposure is per domain, and a scan that covered all of them says
            // so rather than pretending to be one of them.
            $table->string('site')->nullable()->index();
            $table->string('trigger', 16);
            $table->string('status', 16)->index();
            $table->string('engine', 32);
            $table->string('engine_version', 64);
            $table->string('ruleset', 16);
            $table->string('batch_id', 64)->nullable();
            $table->json('scope')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);
            $table->unsignedInteger('pages_errored')->default(0);
            $table->unsignedInteger('pages_skipped')->default(0);
            $table->unsignedInteger('issues_total')->default(0);
            $table->json('issues_by_impact')->nullable();
            $table->json('diff')->nullable();
            $table->string('initiated_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_scans');
    }
};
