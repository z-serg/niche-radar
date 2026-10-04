<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Принадлежность фразы отчёту + ранги и исходные длины (ТЗ §8).
        // rank_previous = 0 означает «не было в прошлом рейтинге».
        Schema::create('dataset_keywords', function (Blueprint $table) {
            $table->uuid('dataset_id');
            $table->bigInteger('keyword_id');
            $table->unsignedBigInteger('source_row');
            $table->integer('rank_current');
            $table->integer('rank_previous');
            $table->integer('word_count_source')->nullable();
            $table->integer('char_count_source')->nullable();

            $table->primary(['dataset_id', 'keyword_id']);
            $table->index(['dataset_id', 'rank_current']);
            $table->index('keyword_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_keywords');
    }
};
