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
        // The human layer of the conformance report. A person's judgement on a
        // criterion no scanner can decide lives here, and once `locked` it is
        // never overwritten by a scan. Getting that wrong destroys the product
        // the first time somebody's careful manual work vanishes overnight.
        Schema::create('a11y_criteria_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('site')->nullable();
            $table->string('criterion', 16);
            $table->string('level', 3);
            // Defaults to not_evaluated and never to supports. A criterion
            // nobody looked at is a criterion nobody looked at.
            $table->string('status', 24)->default('not_evaluated');
            $table->text('remarks')->nullable();
            $table->string('method', 16)->default('automated');
            $table->string('assessed_by')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->unique(['site', 'criterion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a11y_criteria_assessments');
    }
};
