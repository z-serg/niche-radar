<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Версии ниш — неизменяемые снимки (агрегаты в jsonb). При удалении
     * отчёта с «отвязкой зависимостей» (DELETE /datasets/{id}?detach=1)
     * ссылка версии на отчёт гасится, а снимок сохраняется как история.
     * Для этого dataset_id делается nullable с ON DELETE SET NULL.
     */
    public function up(): void
    {
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->dropForeign(['dataset_id']);
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreignUuid('dataset_id')->nullable()->change();
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreign('dataset_id')->references('id')->on('datasets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->dropForeign(['dataset_id']);
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreignUuid('dataset_id')->nullable(false)->change();
        });
        Schema::table('niche_versions', function (Blueprint $table) {
            $table->foreign('dataset_id')->references('id')->on('datasets')->restrictOnDelete();
        });
    }
};
