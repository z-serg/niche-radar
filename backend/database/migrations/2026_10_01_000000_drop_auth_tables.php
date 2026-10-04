<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Локальное приложение без аутентификации: убираем таблицы владельца,
 * токенов и сессий (users, password_reset_tokens, sessions,
 * personal_access_tokens). На свежей базе таблиц ещё нет — dropIfExists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }

    public function down(): void
    {
        // Схема аутентификации не восстанавливается: таблицы не создавались
        // заново после отказа от владельца/токенов (ТЗ §12.3).
    }
};
