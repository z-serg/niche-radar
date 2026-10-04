<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Замеры отчёта: ordinal 1 = самый старый (ТЗ §8). Исходный суффикс
        // колонки хранится в метаданных.
        Schema::create('dataset_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->smallInteger('ordinal');
            $table->string('source_header');
            $table->string('source_suffix')->nullable();
            $table->date('scan_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_scans');
    }
};
