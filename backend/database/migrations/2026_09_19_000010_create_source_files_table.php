<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Оригиналы CSV: один файл — один хеш. Повторная загрузка того же
        // файла возвращает существующий отчёт (ТЗ §6.1 п.2).
        Schema::create('source_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_name');
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->string('path');
            $table->timestamp('uploaded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_files');
    }
};
