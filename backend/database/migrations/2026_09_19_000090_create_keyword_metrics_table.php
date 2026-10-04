<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Материализованные метрики прогона (ТЗ §7). NULL + причины
        // null_reasons вместо подмены оценками.
        Schema::create('keyword_metrics', function (Blueprint $table) {
            $table->uuid('metric_run_id');
            $table->bigInteger('keyword_id');
            $table->smallInteger('n_observations');
            $table->boolean('history_complete');
            $table->unsignedBigInteger('exact_current')->nullable(); // NULL только для произвольных поддиапазонов
            $table->unsignedBigInteger('broad_current')->nullable();
            $table->unsignedBigInteger('exact_first')->nullable();
            // Граничное сравнение fn - f1 (доступно и без полного ряда).
            $table->bigInteger('exact_delta')->nullable();
            $table->double('growth_pct')->nullable(); // (fn-f1)/f1, только f1 > 0
            $table->double('base_avg')->nullable();        // B
            $table->double('late_avg')->nullable();        // R
            $table->double('smoothed_delta')->nullable();  // A
            $table->double('smoothed_growth')->nullable(); // G
            $table->double('consistency')->nullable();     // C
            $table->double('peak_retention')->nullable();  // P
            $table->double('task_score')->default(0);      // T
            $table->jsonb('task_categories')->nullable();
            $table->jsonb('task_rule_ids')->nullable();
            $table->smallInteger('priority')->nullable(); // priority_v1
            $table->boolean('is_new')->default(false);         // rank_previous = 0
            $table->boolean('low_base')->default(false);
            $table->boolean('zero_baseline')->default(false);
            $table->jsonb('null_reasons')->nullable(); // {}
            $table->integer('rank_current')->nullable();
            $table->integer('rank_previous')->nullable();

            $table->primary(['metric_run_id', 'keyword_id']);
        });

        DB::statement('CREATE INDEX keyword_metrics_priority_idx ON keyword_metrics (metric_run_id, priority DESC NULLS LAST, keyword_id)');
        DB::statement('CREATE INDEX keyword_metrics_exact_idx ON keyword_metrics (metric_run_id, exact_current DESC, keyword_id)');
        DB::statement('CREATE INDEX keyword_metrics_delta_idx ON keyword_metrics (metric_run_id, smoothed_delta DESC NULLS LAST, keyword_id)');
        DB::statement('CREATE INDEX keyword_metrics_growth_idx ON keyword_metrics (metric_run_id, smoothed_growth DESC NULLS LAST, keyword_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_metrics');
    }
};
