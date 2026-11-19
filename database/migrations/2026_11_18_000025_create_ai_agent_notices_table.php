<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What an agent has already told people on a schedule (daily summaries, overdue alerts, follow-ups), so it never repeats itself. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('subject', 100);
            $table->timestamp('created_at')->nullable();
            $table->unique(['ai_agent_id', 'kind', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_notices');
    }
};
