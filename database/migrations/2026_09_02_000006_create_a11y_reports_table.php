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
        Schema::create('a11y_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('site')->nullable()->index();
            $table->foreignId('scan_id')->constrained('a11y_scans');
            // Who generated it and against which scan are not optional. An
            // undated, unattributed conformance document is worthless as
            // evidence and dangerous as a claim.
            $table->timestamp('generated_at');
            $table->string('generated_by');
            $table->string('standard', 16);
            $table->text('coverage_note')->nullable();
            $table->string('evaluator_name')->nullable();
            $table->string('evaluator_org')->nullable();
            $table->text('remediation_plan')->nullable();
            $table->string('html_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('json_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_reports');
    }
};
