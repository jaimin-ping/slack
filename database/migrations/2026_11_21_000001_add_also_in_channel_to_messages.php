<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thread reply sent with "Also send to #channel" is shown both in its thread and in the channel,
 * like Slack. It stays one message, so edits, reactions and deletes apply in both places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('also_in_channel')->default(false)->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('also_in_channel');
        });
    }
};
