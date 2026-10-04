<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Повторный импорт отчёта пересоздаёт metric_runs; версии нишей при этом
     * сохраняются (неизменяемость состава, ТЗ §5.5) и уже содержат снимок
     * агрегатов в jsonb. Ссылка на прогон становится nullable и гасится при
     * удалении прогона — вместо падения импорта по NOT NULL.
     */
    public function up(): void
    {
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->dropForeign(['metric_run_id']);
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreignUuid('metric_run_id')->nullable()->change();
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreign('metric_run_id')->references('id')->on('metric_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->dropForeign(['metric_run_id']);
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreignUuid('metric_run_id')->nullable(false)->change();
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreign('metric_run_id')->references('id')->on('metric_runs')->restrictOnDelete();
        });
    }
};
