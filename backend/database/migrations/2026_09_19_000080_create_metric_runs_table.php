<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Прогон расчёта метрик: диапазон замеров + версия алгоритма +
        // профиль настроек + версия правил (ТЗ §8). Готовые прогоны
        // переиспользуются по хешу параметров.
        Schema::create('metric_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->smallInteger('ordinal_from');
            $table->smallInteger('ordinal_to');
            $table->string('algorithm_version'); // metrics_v1
            $table->string('rules_version');
            $table->jsonb('profile');           // пороги пресета, масштабы приоритета
            $table->char('settings_hash', 64);
            $table->string('status')->default('queued'); // queued|running|ready|failed
            $table->boolean('is_default')->default(false);
            $table->jsonb('results_summary')->nullable(); // счётчики пресетов, размер топ-набора
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'settings_hash']);
            $table->index(['dataset_id', 'is_default', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_runs');
    }
};
