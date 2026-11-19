<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What agents remember, what they did, what they read, and what waits for their owner's approval. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('kind', 16); // preference | context | instruction | fact
            $table->text('content');
            $table->string('origin', 8); // owner | agent
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->index(['ai_agent_id', 'pinned']);
        });

        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('trigger', 16); // message | history | chat | schedule
            $table->string('status', 16); // running | succeeded | failed | skipped | cancelled
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('steps')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0); // millionths of a US dollar
            $table->json('context')->nullable();
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['ai_agent_id', 'id']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['owner_id', 'created_at']);
        });

        Schema::create('ai_agent_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_run_id')->constrained('ai_agent_runs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 16); // model | tool | extraction | guard
            $table->string('name', 64)->nullable();
            $table->string('status', 24); // ok | denied | error | approval_required | skipped
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_agent_run_id', 'sequence']);
        });

        // One row per agent, message and version of its text: a retry or a second job cannot read it twice,
        // while an edited message gets a fresh look.
        Schema::create('ai_agent_message_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->string('status', 16); // claimed | done | skipped
            $table->foreignId('ai_agent_run_id')->nullable()->constrained('ai_agent_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ai_agent_id', 'message_id', 'content_hash']);
            $table->index('message_id');
        });

        Schema::create('ai_agent_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_agent_run_id')->nullable()->constrained('ai_agent_runs')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete(); // the decision record outlives the task
            $table->string('action', 40);
            $table->string('summary', 500);
            $table->json('payload');
            $table->string('status', 16)->default('pending'); // pending | executed | rejected | failed | expired
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['ai_agent_id', 'status']);
        });

        // The owner's private conversation with their agent. It never appears in a channel.
        Schema::create('ai_agent_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('role', 8); // owner | agent
            $table->text('content');
            $table->foreignId('ai_agent_run_id')->nullable()->constrained('ai_agent_runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['ai_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_chat_messages');
        Schema::dropIfExists('ai_agent_approvals');
        Schema::dropIfExists('ai_agent_message_scans');
        Schema::dropIfExists('ai_agent_run_steps');
        Schema::dropIfExists('ai_agent_runs');
        Schema::dropIfExists('ai_agent_memories');
    }
};
