<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Автопредложения групп по токенам и парам из отобранных растущих
        // фраз (ТЗ §7.6). Участники выводятся запросом по якорю.
        Schema::create('candidate_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('metric_run_id')->constrained('metric_runs')->cascadeOnDelete();
            $table->string('method'); // token | bigram
            $table->string('anchor'); // токен или «токен1 токен2»
            $table->integer('member_count');
            $table->unsignedBigInteger('exact_sum_current'); // сумма точных участников
            $table->double('growing_share');                 // доля растущих
            $table->bigInteger('leader_keyword_id');
            $table->double('leader_share');
            $table->jsonb('dynamics')->nullable(); // суммы exact по замерам
            $table->integer('source_set_size');    // размер исходного топ-набора
            $table->timestamps();

            $table->unique(['metric_run_id', 'method', 'anchor']);
            $table->index(['metric_run_id', 'member_count']);
        });

        // Словарь брендов для исключения из поиска (ТЗ §5.2).
        Schema::create('brand_dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('terms'); // [токены]
            $table->timestamps();
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_dictionaries');
        Schema::dropIfExists('candidate_groups');
    }
};
