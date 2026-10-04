<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Именованные сохранённые поиски (ТЗ §5.2, §8).
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('filters');     // единая схема фильтров UI/API/MCP
            $table->smallInteger('schema_version')->default(1);
            $table->string('report_mode')->default('latest'); // fixed|latest
            $table->foreignUuid('dataset_id')->nullable()
                ->references('id')->on('datasets')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
