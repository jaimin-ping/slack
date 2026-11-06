<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->json('steps')->nullable();
        });

        DB::table('workflows')->orderBy('id')->each(function (object $workflow) {
            DB::table('workflows')->where('id', $workflow->id)->update([
                'steps' => json_encode([['type' => 'message', 'channel_id' => $workflow->channel_id, 'text' => $workflow->message]]),
            ]);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn('message');
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('step')->default(0);
            $table->timestamp('resume_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');

        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
        });

        DB::table('workflows')->orderBy('id')->each(function (object $workflow) {
            $first = collect(json_decode($workflow->steps, true))->firstWhere('type', 'message');

            DB::table('workflows')->where('id', $workflow->id)->update([
                'channel_id' => $first['channel_id'] ?? null,
                'message' => $first['text'] ?? '',
            ]);
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('steps');
        });
    }
};
