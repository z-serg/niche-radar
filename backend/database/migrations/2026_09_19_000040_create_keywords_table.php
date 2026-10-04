<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Глобальный словарь фраз. Идентичность — NFC + trim (ТЗ §4.2),
        // при совпадении хеша импортёр проверяет саму строку.
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->text('phrase_original');
            $table->char('identity_hash', 64)->unique();
            $table->text('search_text'); // нижний регистр, свёрнутые пробелы
            $table->integer('word_count');
            $table->integer('char_count');
            // Кеш признаков задачи (§7.3): T и метки зависят только от фразы
            // и версии правил; metric_run фиксирует использованную версию.
            $table->double('task_score')->default(0);
            $table->jsonb('task_categories')->nullable();
            $table->jsonb('task_rule_ids')->nullable();
            $table->string('task_rules_version')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement("CREATE INDEX keywords_search_text_trgm ON keywords USING gin (search_text gin_trgm_ops)");
        DB::statement('CREATE INDEX keywords_search_text_btree ON keywords (search_text)');
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
