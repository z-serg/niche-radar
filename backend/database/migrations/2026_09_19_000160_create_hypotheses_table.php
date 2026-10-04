<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Продуктовые гипотезы (ТЗ §5.6).
        Schema::create('hypotheses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('niche_id')->nullable()->constrained('niches')->nullOnDelete();
            $table->string('title');
            $table->text('audience')->nullable();
            $table->text('problem')->nullable();
            $table->text('current_solution')->nullable();
            $table->text('product_idea')->nullable();
            $table->text('mvp')->nullable();
            $table->text('monetization')->nullable();
            $table->text('notes')->nullable();
            $table->text('research_links')->nullable();
            $table->text('next_experiment')->nullable();
            $table->text('success_criterion')->nullable();
            $table->string('status')->default('candidate');
            // кандидат|исследую|проверяю|в разработке|отложено|отклонено
            $table->text('status_reason')->nullable();
            $table->foreignUuid('dataset_id')->nullable()->constrained('datasets')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        // Подтверждающие фразы с сохранёнными значениями на момент записи.
        Schema::create('hypothesis_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('hypothesis_id')->constrained('hypotheses')->cascadeOnDelete();
            $table->bigInteger('keyword_id');
            $table->foreignUuid('dataset_id')->nullable()->constrained('datasets')->nullOnDelete();
            $table->foreignUuid('metric_run_id')->nullable()->constrained('metric_runs')->nullOnDelete();
            $table->foreignUuid('niche_version_id')->nullable()->constrained('niche_versions')->nullOnDelete();
            $table->jsonb('snapshot'); // фраза + метрики на момент сохранения
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['hypothesis_id', 'keyword_id']);
            $table->index('keyword_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypothesis_evidence');
        Schema::dropIfExists('hypotheses');
    }
};
