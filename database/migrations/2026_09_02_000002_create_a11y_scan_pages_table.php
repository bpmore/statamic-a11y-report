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
        Schema::create('a11y_scan_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('a11y_scans')->cascadeOnDelete();
            $table->string('entry_id');
            $table->string('collection')->index();
            $table->string('site')->index();
            // The address as served, for a person to open. The path is what
            // identifies the page across environments, and what the fingerprint
            // is built on: a domain changes between staging and production and
            // between one year and the next, and the page does not.
            $table->string('url', 2048);
            $table->string('path', 2048);
            // pending until a worker takes it, then scanned, error, or skipped.
            // Pending rows are what a resumed scan picks up, so the status has
            // to live here rather than be inferred from the batch.
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('render_ms')->nullable();
            $table->unsignedInteger('issues_count')->default(0);
            // Every page records how much of it the engine could see. A page
            // with zero issues and no coverage record is indistinguishable
            // from a clean page, and the gate's first rule is that it must not be.
            $table->json('coverage')->nullable();
            $table->string('coverage_summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_scan_pages');
    }
};
