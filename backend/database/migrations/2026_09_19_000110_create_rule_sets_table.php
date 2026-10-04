<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Версионированные наборы правил: признаки задачи (§7.3) и
        // стоп-слова для групп (§7.6). Хеш конфигурации фиксирует версию.
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind'); // task_signals | stopwords
            $table->string('version');
            $table->jsonb('config');
            $table->char('config_hash', 64)->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_sets');
    }
};
