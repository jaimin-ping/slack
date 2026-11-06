<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Webhook;
use App\Services\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Receives messages in the Slack incoming-webhook format, so an existing sender only
 * needs its URL changed. Supports text, username, attachments and simple blocks.
 * Replies use Slack's plain-text style: "ok", "no_service", "invalid_payload", "no_text".
 */
class IncomingWebhookController extends Controller
{
    private const MAX_TEXT = 4000;

    private const MAX_CARDS = 5;

    private const MAX_FIELDS = 20;

    private const MAX_CARDS_JSON = 60000;

    private const COLORS = ['good' => '#2eb67d', 'warning' => '#ecb22e', 'danger' => '#e01e5a'];

    public function __invoke(Request $request, ActivityRecorder $activity, string $token): Response
    {
        $webhook = Webhook::with('channel')->where('token_hash', Webhook::hashToken($token))->first();

        if (! $webhook) {
            return response('no_service', 404);
        }

        if ($webhook->channel->archived_at) {
            return response('channel_is_archived', 410);
        }

        $payload = $request->has('payload')
            ? json_decode((string) $request->input('payload'), true)
            : json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('invalid_payload', 400);
        }

        $text = $this->clean($payload['text'] ?? '');
        $blockText = $this->blocksToText($payload['blocks'] ?? []);
        $text = trim($text."\n".$blockText);

        $cards = $this->cards($payload['attachments'] ?? []);

        if ($text === '' && $cards === []) {
            return response('no_text', 400);
        }

        if (mb_strlen($text) > self::MAX_TEXT || strlen(json_encode($cards)) > self::MAX_CARDS_JSON) {
            return response('msg_too_long', 400);
        }

        $username = is_string($payload['username'] ?? null) ? trim($payload['username']) : '';

        $message = $webhook->channel->messages()->create([
            'webhook_id' => $webhook->id,
            'bot_name' => mb_substr($username ?: $webhook->name, 0, 80),
            'body' => $text,
            'cards' => $cards ?: null,
        ]);

        $webhook->update(['last_used_at' => now()]);

        try {
            event(new MessageSent($message));
        } catch (Throwable $e) {
            report($e); // The message is saved even if the WebSocket server is down.
        }

        $activity->record($message);

        return response('ok');
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $cards = [];

        foreach (array_slice($attachments, 0, self::MAX_CARDS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim(implode("\n", array_filter([
                $this->clean($item['pretext'] ?? ''),
                $this->clean($item['text'] ?? ''),
                $this->blocksToText($item['blocks'] ?? []),
            ])));

            $fields = [];
            foreach (array_slice(is_array($item['fields'] ?? null) ? $item['fields'] : [], 0, self::MAX_FIELDS) as $field) {
                if (is_array($field)) {
                    $fields[] = ['title' => $this->clean($field['title'] ?? ''), 'value' => $this->clean($field['value'] ?? '')];
                }
            }

            $title = $this->clean($item['title'] ?? '');

            if ($title === '' && $text === '' && $fields === []) {
                $text = $this->clean($item['fallback'] ?? '');
            }

            if ($title !== '' || $text !== '' || $fields !== []) {
                $cards[] = ['color' => $this->color($item['color'] ?? null), 'title' => $title, 'text' => $text, 'fields' => $fields];
            }
        }

        return $cards;
    }

    private function blocksToText(mixed $blocks): string
    {
        if (! is_array($blocks)) {
            return '';
        }

        $lines = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';

            if ($type === 'header') {
                $lines[] = '*'.$this->clean($block['text']['text'] ?? '').'*';
            } elseif ($type === 'section') {
                $lines[] = $this->clean($block['text']['text'] ?? '');
                foreach (is_array($block['fields'] ?? null) ? $block['fields'] : [] as $field) {
                    $lines[] = $this->clean(is_array($field) ? ($field['text'] ?? '') : '');
                }
            } elseif ($type === 'context') {
                foreach (is_array($block['elements'] ?? null) ? $block['elements'] : [] as $element) {
                    $lines[] = $this->clean(is_array($element) ? ($element['text'] ?? '') : '');
                }
            }
        }

        return trim(implode("\n", array_filter($lines, fn (string $line) => $line !== '')));
    }

    private function color(mixed $color): string
    {
        if (is_string($color) && isset(self::COLORS[$color])) {
            return self::COLORS[$color];
        }

        if (is_string($color) && preg_match('/^#?([0-9a-f]{6})$/i', $color, $match)) {
            return '#'.$match[1];
        }

        return '#94a3b8';
    }

    /** Turns Slack's escaped text and <url|label> links into plain text this app renders. */
    private function clean(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = preg_replace('/<(https?:\/\/[^|>\s]+)\|([^>]+)>/', '$2 ($1)', (string) $value);
        $text = preg_replace('/<(https?:\/\/[^>\s]+)>/', '$1', $text);

        return trim(str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $text));
    }
}
