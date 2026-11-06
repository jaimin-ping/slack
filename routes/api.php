<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CanvasController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\IncomingWebhookController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\SlashCommandController;
use App\Http\Controllers\StatusController;
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
        Route::get('/avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show');
        Route::get('/emoji/{emoji}', [WorkspaceToolsController::class, 'showEmoji'])->name('emoji.show');

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

        Route::scopeBindings()->prefix('/w/{workspace:slug}/c/{channel}')->group(function () {
            Route::post('/read', [ChannelController::class, 'read'])->name('read');
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
