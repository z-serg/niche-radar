<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Наблюдения: одна строка на (замер, фраза) с парой exact/broad
        // (ТЗ §8). NULL = пропущенное значение, 0 = наблюдаемый ноль.
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('dataset_scans')->cascadeOnDelete();
            $table->bigInteger('keyword_id');
            $table->unsignedBigInteger('exact')->nullable();
            $table->unsignedBigInteger('broad')->nullable();

            $table->unique(['scan_id', 'keyword_id']);
            $table->index(['keyword_id', 'scan_id']);
        });

        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_exact_nonnegative CHECK (exact IS NULL OR exact >= 0)");
        DB::statement("ALTER TABLE observations ADD CONSTRAINT observations_broad_nonnegative CHECK (broad IS NULL OR broad >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
