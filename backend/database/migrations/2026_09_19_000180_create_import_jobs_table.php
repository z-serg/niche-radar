<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('stage')->default('queued');
            // queued|validating|importing|calculating|done|failed|cancelled|cancelling
            $table->string('status')->default('queued');
            $table->jsonb('progress')->nullable();
            // {stage, pct, rows, bytes, total_bytes, rows_per_sec, eta_sec}
            $table->jsonb('policy')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'heartbeat_at']);
            $table->index('dataset_id');
        });

        // Ошибки импорта ссылаются на логический номер записи CSV (ТЗ §6.1 п.9).
        Schema::create('import_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('record_no');
            $table->smallInteger('column_index')->nullable();
            $table->string('code');
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_job_id', 'record_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_jobs');
    }
};
