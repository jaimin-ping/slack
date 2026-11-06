<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PhonePushSender;
use App\Services\PushSender;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/** Runs after the response is sent, so a slow push service never delays a message. */
class SendPushNotification
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $userIds, public array $payload) {}

    public function handle(PushSender $sender, ?PhonePushSender $phones = null): void
    {
        try {
            $awake = User::whereIn('id', $this->userIds)->get()->reject(fn (User $user) => $user->isDnd())->modelKeys();

            $sender->send(PushSubscription::whereIn('user_id', $awake)->get(), $this->payload);
            ($phones ?? app(PhonePushSender::class))->send(DeviceToken::whereIn('user_id', $awake)->get(), $this->payload);
        } catch (Throwable $e) {
            report($e); // A failed push must never affect the message itself.
        }
    }
}
