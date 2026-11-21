<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slack parity: a channel description for the About tab, per-person keyword notifications,
 * and a bookmarks bar of links under the channel header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('topic');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('notification_keywords', 500)->nullable();
        });

        Schema::create('channel_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 100);
            $table->string('url', 2000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_bookmarks');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_keywords');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
