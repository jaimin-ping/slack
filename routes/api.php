<?php

use App\Http\Controllers\AI\AgentActivityController;
use App\Http\Controllers\AI\AgentChatController;
use App\Http\Controllers\AI\AgentController;
use App\Http\Controllers\AI\AgentMemoryController;
use App\Http\Controllers\AI\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CanvasController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\IncomingWebhookController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\SavedItemController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SlashCommandController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\WorkspaceToolsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// External systems post here with the secret token in the URL, so there is no session or CSRF.
Route::post('/hooks/{token}', IncomingWebhookController::class)->middleware('throttle:webhooks')->name('hooks.incoming');

// The mobile app. It reuses the web controllers and signs in with a token.
Route::prefix('api/v1')->name('api.')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
    Route::post('/login/email', [MobileAuthController::class, 'sendCode'])->middleware('throttle:10,1')->name('login.email');
    Route::post('/login/code', [MobileAuthController::class, 'loginWithCode'])->middleware('throttle:10,1')->name('login.code');
    Route::get('/sso', [MobileAuthController::class, 'sso'])->name('sso');
    Route::post('/sso/exchange', [MobileAuthController::class, 'ssoExchange'])->middleware('throttle:5,1')->name('sso.exchange');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
        Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
        Route::get('/config', [MobileAuthController::class, 'config'])->name('config');
        Route::post('/device', [MobileAuthController::class, 'device'])->name('device');
        Route::get('/workspaces', [MobileAuthController::class, 'workspaces'])->name('workspaces');
        Route::post('/broadcasting/auth', fn (Request $request) => Broadcast::auth($request))->name('broadcasting.auth');

        Route::patch('/me/status', [StatusController::class, 'update'])->name('status');
        Route::post('/me/away', [StatusController::class, 'away'])->name('away');
        Route::post('/me/pause', [PreferencesController::class, 'pause'])->name('pause');
        Route::post('/me/avatar', [AvatarController::class, 'store'])->name('avatar.store');
        Route::delete('/me/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::get('/attachments/{attachment}/details', [AttachmentController::class, 'details'])->name('attachments.details');
        Route::patch('/attachments/{attachment}', [AttachmentController::class, 'update'])->name('attachments.update');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
        Route::post('/attachments/{attachment}/share', [AttachmentController::class, 'share'])->name('attachments.share');
        Route::get('/attachments/{attachment}/shares', [AttachmentController::class, 'shares'])->name('attachments.shares');

        Route::get('/avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show');
        Route::get('/emoji/{emoji}', [WorkspaceToolsController::class, 'showEmoji'])->name('emoji.show');

        // Saved Items (Bookmarks)
        Route::get('/saved-items', [SavedItemController::class, 'index'])->name('saved-items.index');
        Route::post('/saved-items', [SavedItemController::class, 'store'])->name('saved-items.store');
        Route::delete('/saved-items/{savedItem}', [SavedItemController::class, 'destroy'])->name('saved-items.destroy');
        Route::delete('/saved-items/by-item', [SavedItemController::class, 'destroyByItem'])->name('saved-items.destroy-by-item');

        // Todos
        Route::get('/todos', [TodoController::class, 'index'])->name('todos.index');
        Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
        Route::patch('/todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
        Route::delete('/todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');

        // Folders
        Route::get('/folders', [FolderController::class, 'index'])->name('folders.index');
        Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
        Route::patch('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
        Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
        Route::get('/folders/{folder}/items', [FolderController::class, 'items'])->name('folders.items');
        Route::post('/folders/{folder}/items', [FolderController::class, 'addItem'])->name('folders.items.store');
        Route::delete('/folders/{folder}/items/{item}', [FolderController::class, 'removeItem'])->name('folders.items.destroy');

        // Sections
        Route::get('/sections', [SectionController::class, 'index'])->name('sections.index');
        Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
        Route::patch('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
        Route::get('/sections/{section}/items', [SectionController::class, 'items'])->name('sections.items');
        Route::post('/sections/{section}/items', [SectionController::class, 'addItem'])->name('sections.items.store');
        Route::delete('/sections/{section}/items/{item}', [SectionController::class, 'removeItem'])->name('sections.items.destroy');

        Route::get('/w/{workspace:slug}', [ChannelController::class, 'bootstrap'])->name('workspace');
        Route::get('/w/{workspace:slug}/search', [ChannelController::class, 'search'])->name('search');
        Route::get('/w/{workspace:slug}/browse', [ChannelController::class, 'browse'])->name('browse');
        Route::post('/w/{workspace:slug}/channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('/w/{workspace:slug}/activity', [FeedController::class, 'activity'])->name('activity');
        Route::post('/w/{workspace:slug}/activity/read', [FeedController::class, 'readActivity'])->name('activity.read');
        Route::get('/w/{workspace:slug}/threads', [FeedController::class, 'threads'])->name('threads');
        Route::get('/w/{workspace:slug}/files', [FeedController::class, 'files'])->name('files');
        Route::get('/w/{workspace:slug}/saved', [FeedController::class, 'saved'])->name('saved');
        Route::get('/w/{workspace:slug}/drafts', [FeedController::class, 'drafts'])->name('drafts');
        Route::get('/w/{workspace:slug}/reminders', [WorkspaceToolsController::class, 'reminders'])->name('reminders');
        Route::delete('/w/{workspace:slug}/reminders/{reminder}', [WorkspaceToolsController::class, 'cancelReminder'])->name('reminders.cancel');
        Route::get('/w/{workspace:slug}/scheduled', [WorkspaceToolsController::class, 'scheduled'])->name('scheduled');
        Route::delete('/w/{workspace:slug}/scheduled/{item}', [WorkspaceToolsController::class, 'cancelScheduled'])->name('scheduled.destroy');
        Route::post('/w/{workspace:slug}/sections', [WorkspaceToolsController::class, 'storeSection'])->name('sections.store');
        Route::post('/w/{workspace:slug}/dm/{user}', [ChannelController::class, 'direct'])->name('direct');

        // Workspace tasks: the same actions and rules as the web app.
        Route::prefix('/w/{workspace:slug}/tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::get('/counts', [TaskController::class, 'counts'])->name('counts');
            Route::post('/', [TaskController::class, 'store'])->middleware('throttle:60,1')->name('store');
            Route::get('/{task}', [TaskController::class, 'show'])->whereNumber('task')->name('show');

            Route::whereNumber('task')->middleware('throttle:120,1')->group(function () {
                Route::patch('/{task}', [TaskController::class, 'update'])->name('update');
                Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy');
                Route::post('/{task}/status', [TaskController::class, 'status'])->name('status');
                Route::post('/{task}/pause', [TaskController::class, 'pause'])->name('pause');
                Route::post('/{task}/resume', [TaskController::class, 'resume'])->name('resume');
                Route::post('/{task}/approve', [TaskController::class, 'approve'])->name('approve');
                Route::post('/{task}/reject', [TaskController::class, 'reject'])->name('reject');
                Route::put('/{task}/assignees', [TaskController::class, 'assignees'])->name('assignees');
                Route::post('/{task}/comments', [TaskController::class, 'comment'])->name('comments');
                Route::post('/{task}/archive', [TaskController::class, 'archive'])->name('archive');
                Route::post('/{task}/unarchive', [TaskController::class, 'unarchive'])->name('unarchive');
            });
        });

        // Personal AI agents: the same actions and rules as the web app.
        Route::get('/agents/{agent}/avatar', [AgentController::class, 'avatar'])->whereNumber('agent')->name('agents.avatar');
        Route::prefix('/w/{workspace:slug}/agents')->name('agents.')->group(function () {
            Route::get('/', [AgentController::class, 'index'])->name('index');
            Route::get('/options', [AgentController::class, 'options'])->name('options');
            Route::get('/usage', [AgentActivityController::class, 'usage'])->name('usage');
            Route::post('/', [AgentController::class, 'store'])->middleware('throttle:20,1')->name('store');

            Route::get('/{agent}', [AgentController::class, 'show'])->whereNumber('agent')->name('show');
            Route::post('/{agent}/analyze', [AgentChatController::class, 'analyze'])->whereNumber('agent')->middleware('throttle:5,1')->name('analyze');

            Route::whereNumber('agent')->middleware('throttle:60,1')->group(function () {
                Route::get('/{agent}/memories', [AgentMemoryController::class, 'index'])->name('memories.index');
                Route::post('/{agent}/memories', [AgentMemoryController::class, 'store'])->name('memories.store');
                Route::patch('/{agent}/memories/{memory}', [AgentMemoryController::class, 'update'])->whereNumber('memory')->name('memories.update');
                Route::delete('/{agent}/memories/{memory}', [AgentMemoryController::class, 'destroy'])->whereNumber('memory')->name('memories.destroy');
                Route::get('/{agent}/runs', [AgentActivityController::class, 'runs'])->name('runs.index');
                Route::get('/{agent}/runs/{run}', [AgentActivityController::class, 'run'])->whereNumber('run')->name('runs.show');
                Route::get('/{agent}/chat', [AgentChatController::class, 'index'])->name('chat.index');
                Route::post('/{agent}/chat', [AgentChatController::class, 'store'])->name('chat.store');
                Route::patch('/{agent}', [AgentController::class, 'update'])->name('update');
                Route::delete('/{agent}', [AgentController::class, 'destroy'])->name('destroy');
                Route::post('/{agent}/status', [AgentController::class, 'status'])->name('status');
                Route::put('/{agent}/sources', [AgentController::class, 'sources'])->name('sources');
                Route::put('/{agent}/permissions', [AgentController::class, 'permissions'])->name('permissions');
                Route::post('/{agent}/avatar', [AgentController::class, 'storeAvatar'])->name('avatar.store');
                Route::delete('/{agent}/avatar', [AgentController::class, 'destroyAvatar'])->name('avatar.destroy');
            });
        });
        Route::get('/w/{workspace:slug}/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/w/{workspace:slug}/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval')->middleware('throttle:60,1')->name('approvals.approve');
        Route::post('/w/{workspace:slug}/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->whereNumber('approval')->middleware('throttle:60,1')->name('approvals.reject');

        Route::scopeBindings()->prefix('/w/{workspace:slug}/c/{channel}')->group(function () {
            Route::post('/read', [ChannelController::class, 'read'])->name('read');
            Route::post('/huddle', [ChannelController::class, 'huddle'])->middleware('throttle:20,1')->name('huddle');
            Route::post('/join', [ChannelController::class, 'join'])->name('join');
            Route::post('/leave', [ChannelController::class, 'leave'])->name('leave');
            Route::post('/mute', [ChannelController::class, 'mute'])->name('mute');
            Route::put('/draft', [FeedController::class, 'saveDraft'])->name('draft');
            Route::post('/schedule', [WorkspaceToolsController::class, 'schedule'])->name('schedule');
            Route::post('/section', [WorkspaceToolsController::class, 'moveToSection'])->name('section');
            Route::post('/command', [SlashCommandController::class, 'run'])->middleware('throttle:60,1')->name('command');
            Route::get('/pins', [ChannelController::class, 'pins'])->name('pins');
            Route::get('/canvas', [CanvasController::class, 'show'])->name('canvas');
            Route::put('/canvas', [CanvasController::class, 'update'])->name('canvas.update');
            Route::get('/messages', [ChannelController::class, 'messages'])->name('messages');
            Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:120,1')->name('messages.store');
            Route::patch('/messages/{message}', [MessageController::class, 'update'])->name('messages.update');
            Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
            Route::get('/messages/{message}/thread', [ChannelController::class, 'thread'])->name('messages.thread');
            Route::post('/messages/{message}/reactions', [MessageController::class, 'react'])->middleware('throttle:120,1')->name('messages.react');
            Route::post('/messages/{message}/pin', [MessageController::class, 'pin'])->name('messages.pin');
            Route::post('/messages/{message}/save', [MessageController::class, 'save'])->name('messages.save');
            Route::post('/messages/{message}/forward', [MessageController::class, 'forward'])->middleware('throttle:120,1')->name('messages.forward');
        });
    });
});
