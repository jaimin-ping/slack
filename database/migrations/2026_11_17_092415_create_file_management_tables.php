<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update attachments table with new columns
        Schema::table('attachments', function (Blueprint $table) {
            $table->text('description')->nullable()->after('size');
            $table->unsignedInteger('download_count')->default(0)->after('description');
        });

        // Saved items (bookmarks) - polymorphic
        Schema::create('saved_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('saveable_type', 50);
            $table->unsignedBigInteger('saveable_id');
            $table->timestamps();

            $table->unique(['user_id', 'saveable_type', 'saveable_id'], 'unique_saved_item');
            $table->index(['saveable_type', 'saveable_id']);
        });

        // To-do items
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->date('due_date')->nullable();
            $table->string('linked_type', 50)->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index(['user_id', 'status']);
            $table->index(['linked_type', 'linked_id']);
        });

        // Folders for organizing files
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7)->default('#4A90D9');
            $table->string('icon', 50)->default('folder');
            $table->timestamps();

            $table->index(['user_id', 'workspace_id']);
        });

        // Folder items (pivot)
        Schema::create('folder_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();

            $table->unique(['folder_id', 'attachment_id']);
        });

        // Sections (categories like Starred, Important)
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('icon', 50)->default('bookmark');
            $table->enum('type', ['default', 'custom'])->default('custom');
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'workspace_id']);
        });

        // Section items (pivot)
        Schema::create('section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();

            $table->unique(['section_id', 'attachment_id']);
        });

        // Attachment shares
        Schema::create('attachment_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->cascadeOnDelete();
            $table->enum('shared_to_type', ['channel', 'dm']);
            $table->unsignedBigInteger('shared_to_id');
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['attachment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_shares');
        Schema::dropIfExists('section_items');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('folder_items');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('todos');
        Schema::dropIfExists('saved_items');

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['description', 'download_count']);
        });
    }
};
