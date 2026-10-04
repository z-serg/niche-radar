<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Материализованные токены фраз: непрерывные последовательности
        // Unicode-букв/цифр (ТЗ §5.2). Основа токенного поиска,
        // признаков задачи и предложений групп.
        Schema::create('keyword_tokens', function (Blueprint $table) {
            $table->bigInteger('keyword_id');
            $table->string('token');

            $table->primary(['keyword_id', 'token']);
            $table->index(['token', 'keyword_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_tokens');
    }
};
