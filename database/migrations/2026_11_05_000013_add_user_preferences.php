<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'theme', 'timezone', 'title', 'phone', 'dnd_until', 'dnd_from', 'dnd_to', 'status_expires_at',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('system');
            $table->string('timezone', 60)->nullable();
            $table->string('title', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamp('dnd_until')->nullable();
            $table->string('dnd_from', 5)->nullable();
            $table->string('dnd_to', 5)->nullable();
            $table->timestamp('status_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
