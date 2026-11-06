<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('send_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('failed')->default(false);
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('text', 500);
            $table->timestamp('remind_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 30);
            $table->timestamps();
            $table->unique(['workspace_id', 'handle']);
        });

        Schema::create('user_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_group_id', 'user_id']);
        });

        Schema::create('custom_emojis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 32);
            $table->string('path');
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('sidebar_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->timestamps();
        });

        Schema::table('channel_user', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->constrained('sidebar_sections')->nullOnDelete();
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('trigger', 20); // daily | member_joined
            $table->string('time', 5)->nullable();
            $table->string('timezone', 60)->nullable();
            $table->text('message');
            $table->boolean('enabled')->default(true);
            $table->date('last_run_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');

        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });

        foreach (['sidebar_sections', 'custom_emojis', 'user_group_user', 'user_groups', 'reminders', 'scheduled_messages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
