<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('channel_user', function (Blueprint $table) {
            $table->boolean('muted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn('muted');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
