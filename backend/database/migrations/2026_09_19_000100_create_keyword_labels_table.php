<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Метки фраз: происхождение rule/manual; ручное переопределение
        // хранится отдельно от машинных меток (ТЗ §7.3, §8).
        Schema::create('keyword_labels', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('keyword_id');
            $table->string('label');
            $table->string('origin'); // rule | manual
            $table->string('rule_version')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['keyword_id', 'label', 'origin']);
            $table->index(['label', 'origin']);
        });

        // Заметки владельца на уровне фразы (ТЗ §5.3).
        Schema::create('keyword_notes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('keyword_id');
            $table->text('body');
            $table->timestamps();
            $table->index('keyword_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_notes');
        Schema::dropIfExists('keyword_labels');
    }
};
