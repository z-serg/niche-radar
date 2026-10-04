<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Фоновые экспорты (CSV выборки, состав ниши, Markdown гипотезы).
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind'); // search_csv | niche_csv | hypothesis_md
            $table->jsonb('params');
            $table->string('status')->default('queued'); // queued|running|done|failed|cancelled
            $table->string('result_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Отложенные аналитические задания: общий механизм jobs для
        // API и MCP (ТЗ §9).
        Schema::create('analytics_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind'); // summary | compare | ...
            $table->jsonb('params');
            $table->string('status')->default('queued'); // queued|running|done|failed
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_jobs');
        Schema::dropIfExists('export_jobs');
    }
};
