<?php

namespace App\Services;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushSender
{
    private const TIMEOUT_SECONDS = 8;

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, failed: int}
     */
    public function send(Collection $subscriptions, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $vapid = config('services.webpush');

        if ($subscriptions->isEmpty() || empty($vapid['public_key']) || empty($vapid['private_key'])) {
            return $result;
        }

        $push = new WebPush(['VAPID' => [
            'subject' => $vapid['subject'],
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
        ]], [], new Client(['timeout' => self::TIMEOUT_SECONDS]));

        $json = json_encode($payload);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => 'aes128gcm',
            ]), $json);
        }

        foreach ($push->flush() as $report) {
            $result[$report->isSuccess() ? 'sent' : 'failed']++;

            // The browser unsubscribed, so this address will never work again.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($report->getEndpoint()))->delete();
            }
        }

        return $result;
    }
}
