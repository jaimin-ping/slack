<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/** Sends notifications to the mobile app through Expo's push service. */
class PhonePushSender
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private const BATCH = 100;

    private const TIMEOUT_SECONDS = 8;

    /**
     * @param  Collection<int, DeviceToken>  $devices
     * @param  array<string, mixed>  $payload
     */
    public function send(Collection $devices, array $payload): void
    {
        foreach ($devices->chunk(self::BATCH) as $batch) {
            $batch = $batch->values();

            $messages = $batch->map(fn (DeviceToken $device) => [
                'to' => $device->token,
                'title' => $payload['title'] ?? 'Ping Chat',
                'body' => $payload['body'] ?? '',
                'sound' => 'default',
                'data' => [
                    'slug' => $payload['slug'] ?? null,
                    'channel_id' => $payload['channel_id'] ?? null,
                    'thread' => $payload['thread'] ?? null,
                ],
            ])->all();

            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->post(self::ENDPOINT, $messages);

            // Tickets come back in the same order as the messages.
            foreach ($response->json('data', []) as $index => $ticket) {
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($batch[$index])) {
                    $batch[$index]->delete();
                }
            }
        }
    }
}
