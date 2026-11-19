<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal AI agents. Each agent belongs to one member in one workspace.
 * Plain strings are used instead of enums so the same schema runs on SQLite, MySQL and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->text('personality')->nullable();
            $table->text('instructions')->nullable();
            $table->text('responsibilities')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('avatar_preset', 32)->nullable();
            $table->string('status', 16)->default('active'); // active | paused | inactive
            $table->string('mode', 16)->default('suggest'); // suggest | auto_tasks | controlled
            $table->json('settings')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'owner_id', 'name']);
            $table->index(['workspace_id', 'status']);
        });

        // One policy per action: deny, approve (ask the owner first) or allow.
        Schema::create('ai_agent_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('policy', 16);
            $table->timestamps();

            $table->unique(['ai_agent_id', 'action']);
        });

        // The conversations an agent may read. DMs are channels too, so each one must be granted on its own.
        Schema::create('ai_agent_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_agent_id', 'channel_id']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_sources');
        Schema::dropIfExists('ai_agent_permissions');
        Schema::dropIfExists('ai_agents');
    }
};
