<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ниша: fixed (конкретные фразы) или rule (фильтры + ручные
        // включения/исключения), ТЗ §5.5.
        Schema::create('niches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable(); // описание задачи
            $table->string('type')->default('fixed'); // fixed | rule
            $table->jsonb('rule')->nullable();         // фильтры для type=rule
            $table->jsonb('manual_include')->nullable(); // [keyword_id]
            $table->jsonb('manual_exclude')->nullable(); // [keyword_id]
            $table->timestamps();
        });

        // Неизменяемая версия состава ниши с агрегатами на момент оценки.
        // metric_run_id nullable: повторный импорт пересоздаёт прогоны, а версия
        // сохраняет снимок агрегатов (см. миграцию 000220 для существующих БД).
        Schema::create('niche_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('niche_id')->constrained('niches')->cascadeOnDelete();
            // dataset_id/metric_run_id nullable: версии — неизменяемые снимки;
            // при удалении отчёта с отвязкой ссылка гасится, история остаётся.
            $table->foreignUuid('dataset_id')->nullable()->constrained('datasets')->nullOnDelete();
            $table->foreignUuid('metric_run_id')->nullable()->constrained('metric_runs')->nullOnDelete();
            $table->integer('version');
            $table->integer('member_count');
            $table->integer('complete_member_count');
            $table->double('coverage_pct');
            $table->jsonb('aggregates'); // суммы по замерам, доли, лидеры
            $table->timestamps();

            $table->unique(['niche_id', 'version']);
        });

        Schema::create('niche_members', function (Blueprint $table) {
            $table->uuid('niche_version_id');
            $table->bigInteger('keyword_id');
            $table->boolean('has_observation')->default(true);

            $table->primary(['niche_version_id', 'keyword_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niche_members');
        Schema::dropIfExists('niche_versions');
        Schema::dropIfExists('niches');
    }
};
