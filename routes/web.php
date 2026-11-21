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
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SlashCommandController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceToolsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/sso/redirect', [SsoController::class, 'redirect'])->middleware('throttle:20,1')->name('sso.redirect');
Route::get('/sso/mobile', [SsoController::class, 'mobile'])->middleware('throttle:20,1')->name('sso.mobile');
Route::get('/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');

// Google OAuth - exclude mobile route from Inertia to prevent 409 conflicts
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1')->name('google.redirect');
Route::get('/auth/google/mobile', [GoogleAuthController::class, 'mobile'])
    ->middleware('throttle:20,1')
    ->withoutMiddleware(\App\Http\Middleware\HandleInertiaRequests::class)
    ->name('google.mobile');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('google.callback');

Route::middleware('guest')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [WorkspaceController::class, 'index'])->name('dashboard');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show');
    Route::post('/me/avatar', [AvatarController::class, 'store'])->name('avatar.store');
    Route::delete('/me/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');
    Route::post('/me/push', [PushSubscriptionController::class, 'store'])->name('push.store');
    Route::delete('/me/push', [PushSubscriptionController::class, 'destroy'])->name('push.destroy');
    Route::post('/me/sso/connect', [SsoController::class, 'connect'])->middleware('throttle:10,1')->name('sso.connect');
    Route::post('/me/two-factor/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:10,1')->name('two-factor.setup');
    Route::post('/me/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::delete('/me/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:10,1')->name('two-factor.disable');
    Route::post('/me/export', [ExportController::class, 'personal'])->middleware('throttle:5,1')->name('export.personal');
    Route::post('/w/{workspace:slug}/export', [ExportController::class, 'workspace'])->middleware('throttle:5,1')->name('export.workspace');
    Route::put('/me/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
    Route::post('/me/pause', [PreferencesController::class, 'pause'])->name('preferences.pause');
    Route::patch('/me/status', [StatusController::class, 'update'])->name('status.update');
    Route::post('/me/away', [StatusController::class, 'away'])->name('status.away');

    Route::get('/w/{workspace:slug}', [WorkspaceController::class, 'show'])->name('workspaces.show');
    Route::patch('/w/{workspace:slug}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::delete('/w/{workspace:slug}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    Route::get('/w/{workspace:slug}/search', [ChannelController::class, 'search'])->name('workspaces.search');
    Route::get('/w/{workspace:slug}/activity', [FeedController::class, 'activity'])->name('feed.activity');
    Route::post('/w/{workspace:slug}/activity/read', [FeedController::class, 'readActivity'])->name('feed.activity.read');
    Route::get('/w/{workspace:slug}/threads', [FeedController::class, 'threads'])->name('feed.threads');
    Route::get('/w/{workspace:slug}/drafts', [FeedController::class, 'drafts'])->name('feed.drafts');
    Route::get('/w/{workspace:slug}/files', [FeedController::class, 'files'])->name('feed.files');
    Route::get('/w/{workspace:slug}/members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('/w/{workspace:slug}/members/{user}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('/w/{workspace:slug}/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy');
    Route::get('/emoji/{emoji}', [WorkspaceToolsController::class, 'showEmoji'])->name('emoji.show');
    Route::get('/w/{workspace:slug}/reminders', [WorkspaceToolsController::class, 'reminders'])->name('reminders.index');
    Route::delete('/w/{workspace:slug}/reminders/{reminder}', [WorkspaceToolsController::class, 'cancelReminder'])->name('reminders.destroy');
    Route::get('/w/{workspace:slug}/scheduled', [WorkspaceToolsController::class, 'scheduled'])->name('feed.scheduled');
    Route::delete('/w/{workspace:slug}/scheduled/{item}', [WorkspaceToolsController::class, 'cancelScheduled'])->name('scheduled.destroy');
    Route::post('/w/{workspace:slug}/groups', [WorkspaceToolsController::class, 'storeGroup'])->name('groups.store');
    Route::patch('/w/{workspace:slug}/groups/{group}', [WorkspaceToolsController::class, 'updateGroup'])->name('groups.update');
    Route::delete('/w/{workspace:slug}/groups/{group}', [WorkspaceToolsController::class, 'destroyGroup'])->name('groups.destroy');
    Route::post('/w/{workspace:slug}/emoji', [WorkspaceToolsController::class, 'storeEmoji'])->name('emoji.store');
    Route::delete('/w/{workspace:slug}/emoji/{emoji}', [WorkspaceToolsController::class, 'destroyEmoji'])->name('emoji.destroy');
    Route::post('/w/{workspace:slug}/sections', [WorkspaceToolsController::class, 'storeSection'])->name('sections.store');
    Route::delete('/w/{workspace:slug}/sections/{section}', [WorkspaceToolsController::class, 'destroySection'])->name('sections.destroy');
    Route::get('/w/{workspace:slug}/invites', [WorkspaceToolsController::class, 'invites'])->name('invites.index');
    Route::post('/w/{workspace:slug}/invites', [WorkspaceToolsController::class, 'invite'])->middleware('throttle:10,1')->name('invites.store');
    Route::delete('/w/{workspace:slug}/invites/{invitation}', [WorkspaceToolsController::class, 'cancelInvite'])->name('invites.destroy');
    Route::get('/w/{workspace:slug}/workflows', [WorkspaceToolsController::class, 'workflows'])->name('workflows.index');
    Route::post('/w/{workspace:slug}/workflows', [WorkspaceToolsController::class, 'storeWorkflow'])->name('workflows.store');
    Route::post('/w/{workspace:slug}/workflows/{workflow}/toggle', [WorkspaceToolsController::class, 'toggleWorkflow'])->name('workflows.toggle');
    Route::delete('/w/{workspace:slug}/workflows/{workflow}', [WorkspaceToolsController::class, 'destroyWorkflow'])->name('workflows.destroy');
    Route::get('/w/{workspace:slug}/analytics', [InsightsController::class, 'analytics'])->name('insights.analytics');
    Route::get('/w/{workspace:slug}/audit', [InsightsController::class, 'audit'])->name('insights.audit');
    Route::get('/w/{workspace:slug}/saved', [FeedController::class, 'saved'])->name('feed.saved');
    Route::get('/w/{workspace:slug}/browse', [ChannelController::class, 'browse'])->name('feed.channels');
    Route::post('/w/{workspace:slug}/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::get('/w/{workspace:slug}/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/w/{workspace:slug}/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::delete('/w/{workspace:slug}/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/w/{workspace:slug}/dm/{user}', [ChannelController::class, 'direct'])->name('channels.direct');

    // Workspace tasks, shared with the mobile API.
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

    // Personal AI agents.
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

    Route::scopeBindings()->group(function () {
        Route::get('/w/{workspace:slug}/c/{channel}', [ChannelController::class, 'show'])->name('channels.show');
        Route::get('/w/{workspace:slug}/c/{channel}/messages', [ChannelController::class, 'messages'])->name('channels.messages');
        Route::get('/w/{workspace:slug}/c/{channel}/pins', [ChannelController::class, 'pins'])->name('channels.pins');
        Route::post('/w/{workspace:slug}/c/{channel}/messages/{message}/pin', [MessageController::class, 'pin'])->name('messages.pin');
        Route::post('/w/{workspace:slug}/c/{channel}/messages/{message}/save', [MessageController::class, 'save'])->name('messages.save');
        Route::post('/w/{workspace:slug}/c/{channel}/messages/{message}/forward', [MessageController::class, 'forward'])
            ->middleware('throttle:120,1')->name('messages.forward');
        Route::post('/w/{workspace:slug}/c/{channel}/schedule', [WorkspaceToolsController::class, 'schedule'])->name('channels.schedule');
        Route::post('/w/{workspace:slug}/c/{channel}/command', [SlashCommandController::class, 'run'])->middleware('throttle:60,1')->name('channels.command');
        Route::post('/w/{workspace:slug}/c/{channel}/section', [WorkspaceToolsController::class, 'moveToSection'])->name('channels.section');
        Route::post('/w/{workspace:slug}/c/{channel}/read', [ChannelController::class, 'read'])->name('channels.read');
        Route::post('/w/{workspace:slug}/c/{channel}/huddle', [ChannelController::class, 'huddle'])->middleware('throttle:20,1')->name('channels.huddle');
        Route::post('/w/{workspace:slug}/c/{channel}/huddle/reply', [ChannelController::class, 'huddleReply'])->middleware('throttle:20,1')->name('channels.huddle.reply');
        Route::post('/w/{workspace:slug}/c/{channel}/star', [ChannelController::class, 'star'])->name('channels.star');
        Route::post('/w/{workspace:slug}/c/{channel}/notify', [ChannelController::class, 'notify'])->name('channels.notify');
        Route::patch('/w/{workspace:slug}/c/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('/w/{workspace:slug}/c/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('/w/{workspace:slug}/c/{channel}/members', [ChannelController::class, 'addMembers'])->name('channels.members');
        Route::delete('/w/{workspace:slug}/c/{channel}/members/{user}', [ChannelController::class, 'removeMember'])->name('channels.members.remove')->withoutScopedBindings();
        Route::post('/w/{workspace:slug}/c/{channel}/leave', [ChannelController::class, 'leave'])->name('channels.leave');
        Route::post('/w/{workspace:slug}/c/{channel}/join', [ChannelController::class, 'join'])->name('channels.join');
        Route::post('/w/{workspace:slug}/c/{channel}/archive', [ChannelController::class, 'archive'])->name('channels.archive');
        Route::post('/w/{workspace:slug}/c/{channel}/unarchive', [ChannelController::class, 'unarchive'])->name('channels.unarchive');
        Route::post('/w/{workspace:slug}/c/{channel}/mute', [ChannelController::class, 'mute'])->name('channels.mute');
        Route::get('/w/{workspace:slug}/c/{channel}/canvas', [CanvasController::class, 'show'])->name('canvas.show');
        Route::put('/w/{workspace:slug}/c/{channel}/canvas', [CanvasController::class, 'update'])->name('canvas.update');
        Route::put('/w/{workspace:slug}/c/{channel}/draft', [FeedController::class, 'saveDraft'])->name('channels.draft');
        Route::patch('/w/{workspace:slug}/c/{channel}/messages/{message}', [MessageController::class, 'update'])->name('messages.update');
        Route::delete('/w/{workspace:slug}/c/{channel}/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
        Route::get('/w/{workspace:slug}/c/{channel}/messages/{message}/thread', [ChannelController::class, 'thread'])->name('messages.thread');
        Route::post('/w/{workspace:slug}/c/{channel}/messages/{message}/reactions', [MessageController::class, 'react'])
            ->middleware('throttle:120,1')->name('messages.react');
        Route::post('/w/{workspace:slug}/c/{channel}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:120,1')->name('messages.store');
        Route::post('/w/{workspace:slug}/c/{channel}/share-file', [MessageController::class, 'shareFile'])
            ->middleware('throttle:60,1')->name('messages.share-file');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Folders (web routes for session auth)
    Route::get('/folders', [FolderController::class, 'index'])->name('folders.index');
    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
    Route::get('/folders/{folder}/items', [FolderController::class, 'items'])->name('folders.items');
    Route::post('/folders/{folder}/items', [FolderController::class, 'addItem'])->name('folders.addItem');
    Route::delete('/folders/{folder}/items/{item}', [FolderController::class, 'removeItem'])->name('folders.removeItem');
});

require __DIR__.'/auth.php';
