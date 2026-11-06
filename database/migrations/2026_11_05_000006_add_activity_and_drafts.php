<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // mention | reply
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'message_id']);
            $table->index(['user_id', 'workspace_id', 'read_at']);
        });

        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->unique(['user_id', 'channel_id']);
        });

        Schema::table('channel_user', function (Blueprint $table) {
            $table->boolean('notify_all')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn('notify_all');
        });

        Schema::dropIfExists('drafts');
        Schema::dropIfExists('activities');
    }
};
