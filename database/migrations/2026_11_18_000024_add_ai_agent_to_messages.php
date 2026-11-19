<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks messages written by an AI agent. They are bot messages (no user_id) named "<Agent> (AI Assistant)",
 * so every existing web and mobile client already shows them as not written by a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('ai_agent_id')->nullable()->after('bot_name')->constrained('ai_agents')->nullOnDelete();
            $table->index(['ai_agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['ai_agent_id', 'created_at']);
            $table->dropConstrainedForeignId('ai_agent_id');
        });
    }
};
