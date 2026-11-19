<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace tasks, created by people or detected by agents.
 * The older per-person `todos` table is left untouched; it keeps serving "add file to to-do".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            // The person the task belongs to. For an agent's task this is the agent's owner.
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->string('status', 16)->default('pending'); // suggested | pending | in_progress | blocked | paused | completed | cancelled
            $table->string('paused_from', 16)->nullable();
            $table->date('start_date')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('source_channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->json('source_ref')->nullable();
            $table->string('source_state', 16)->nullable(); // null | edited | removed
            // Same source message + same task always gives the same key, so retries and other agents cannot duplicate it.
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'due_at']);
            $table->index(['workspace_id', 'creator_id']);
            $table->index('source_message_id');
            $table->index('source_channel_id');
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_id']);
            $table->index('depends_on_id');
        });

        // The full history: every change, assignment and comment, by a person or by an agent.
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('type', 32);
            $table->json('data')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'id']);
        });

        // Further messages about a task, such as a status update or "done" posted later.
        Schema::create('task_message_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 16); // source | update | completion
            $table->timestamps();

            $table->unique(['task_id', 'message_id', 'relation']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_message_links');
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
    }
};
