<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique(); // The token itself is shown once and never stored.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('webhook_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bot_name', 80)->nullable();
            $table->json('cards')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_id');
            $table->dropColumn(['bot_name', 'cards', 'edited_at', 'removed_at']);
        });

        Schema::dropIfExists('webhooks');
    }
};
