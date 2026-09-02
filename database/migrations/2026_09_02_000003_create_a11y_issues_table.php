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
        Schema::create('a11y_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('a11y_scans')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('a11y_scan_pages')->cascadeOnDelete();
            // rule + target + url, hashed. The one thing that lets a problem be
            // followed from scan to scan instead of rediscovered every night.
            $table->string('fingerprint', 40)->index();
            $table->string('rule_id', 64)->index();
            // What the engine allows the finding to cite, verbatim. For a house
            // rule that is a plain name, never a number, and `wcag_criteria`
            // is empty. Storing the label alongside the parsed criteria is what
            // stops a report quietly promoting "Heading structure" to 1.3.1.
            $table->string('label', 64);
            $table->json('wcag_criteria');
            $table->string('impact', 16)->index();
            $table->string('selector', 1024)->nullable();
            $table->string('pointer', 1024)->nullable();
            $table->text('html_snippet')->nullable();
            $table->text('message');
            $table->string('remedy')->nullable();
            $table->string('help_url')->nullable();
            // The same problem raised several times on one page is one issue
            // seen more than once, not several issues. A grid of identical
            // "Read more" links is one fix.
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();

            $table->unique(['scan_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_issues');
    }
};
