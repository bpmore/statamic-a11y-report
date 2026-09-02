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
        // Keyed on the fingerprint and on nothing that belongs to one scan.
        // This is the triage queue, and a queue that reset every night would
        // not be one.
        Schema::create('a11y_issue_states', function (Blueprint $table) {
            $table->string('fingerprint', 40)->primary();
            $table->string('status', 16)->index();
            // Denormalised from the issue so a scoped scan can close only the
            // issues on pages it actually looked at. The fingerprint hides the
            // page; these columns do not. `url` is the address as last served,
            // for a person to open; `site` and `path` are the identity.
            $table->string('url', 2048);
            $table->string('site')->index();
            $table->string('path', 2048);
            $table->string('rule_id', 64)->index();
            // As last reported by the engine, so "open issues by impact" is one
            // query on this table rather than a join to the latest scan.
            $table->string('impact', 16)->index();
            $table->string('assigned_to')->nullable()->index();
            $table->text('note')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->foreignId('last_scan_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_issue_states');
    }
};
