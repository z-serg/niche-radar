<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Отчёт (dataset) = ревизия обработки source_file с конкретным
        // сопоставлением колонок. Статусы ТЗ §6.2.
        Schema::create('datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_file_id')->constrained('source_files')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('revision')->default(1);
            $table->uuid('original_dataset_id')->nullable(); // FK добавляется ниже
            $table->string('title')->nullable();
            $table->string('source')->default('bukvarix');
            $table->string('region')->nullable();
            $table->string('frequency_definition')->nullable();
            $table->string('period_description')->nullable();
            $table->string('coverage_type')->default('unknown'); // unknown|full_top|sample|filtered
            $table->string('status')->default('uploaded')->index();
            $table->jsonb('mapping')->nullable();
            $table->jsonb('params')->nullable(); // метаданные приёма
            $table->jsonb('policy')->nullable(); // skip_invalid, authoritative_current, drop_conflicting_duplicates
            $table->jsonb('quality_flags')->nullable(); // [] строк
            $table->unsignedBigInteger('row_count')->nullable();
            $table->smallInteger('scan_count')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('is_active');
        });

        // Ревизии того же оригинала связаны явно (самоссылка — отдельным DDL).
        Schema::table('datasets', function (Blueprint $table) {
            $table->foreign('original_dataset_id')->references('id')->on('datasets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
