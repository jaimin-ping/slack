<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a page's title for a link preview.
 *
 * Anyone can paste any URL, so this must never reach the server's own network:
 * every hop is checked, and the connection is pinned to the checked address so
 * DNS cannot change between the check and the request.
 */
class LinkPreviewer
{
    private const MAX_BYTES = 524288;

    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 4;

    private const PORTS = ['http' => 80, 'https' => 443];

    public function firstUrl(string $text): ?string
    {
        if (! preg_match('~https?://[^\s<>]+~i', $text, $match)) {
            return null;
        }

        return rtrim($match[0], '.,;:!?)]}\'"*_~`');
    }

    /** @return array{url: string, title: string, description: string, site: string}|null */
    public function fetch(string $url): ?array
    {
        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $target = $this->target($url);

                if (! $target) {
                    return null;
                }

                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::TIMEOUT_SECONDS)
                    ->withoutRedirecting()
                    ->withUserAgent('PingChatLinkPreview/1.0')
                    ->withOptions([
                        'stream' => true,
                        'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
                    ])
                    ->get($url);

                if ($response->redirect()) {
                    $url = $this->absolute($url, (string) $response->header('Location'));

                    continue;
                }

                if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'text/html')) {
                    return null;
                }

                return $this->parse($this->readLimited($response->toPsrResponse()->getBody()), $url);
            }
        } catch (Throwable) {
            return null; // A preview is optional; a bad page must not affect the message.
        }

        return null;
    }

    /** @return array{host: string, port: int, ip: string}|null */
    public function target(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! isset(self::PORTS[$scheme]) || $host === '' || isset($parts['user']) || str_contains($host, ':') || str_starts_with($host, '[')) {
            return null;
        }

        $port = $parts['port'] ?? self::PORTS[$scheme];

        if (! in_array($port, self::PORTS, true)) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        // One private answer is enough to refuse: the name may point inside.
        if ($ips === [] || collect($ips)->contains(fn (string $ip) => ! $this->isPublic($ip))) {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $long = ip2long($ip);

        // Carrier-grade NAT, which filter_var does not treat as private.
        return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim($location, '/');
    }

    private function readLimited($stream): string
    {
        $html = '';

        while (! $stream->eof() && strlen($html) < self::MAX_BYTES) {
            $html .= $stream->read(8192);
        }

        return $html;
    }

    /** @return array{url: string, title: string, description: string, site: string}|null */
    private function parse(string $html, string $url): ?array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $meta = fn (string $name) => trim((string) $xpath->evaluate("string(//meta[@property='{$name}' or @name='{$name}']/@content)"));

        $title = $meta('og:title') ?: trim((string) $xpath->evaluate('string(//title)'));

        if ($title === '') {
            return null;
        }

        return [
            'url' => $url,
            'title' => Str::limit(strip_tags($title), 200),
            'description' => Str::limit(strip_tags($meta('og:description') ?: $meta('description')), 300),
            'site' => Str::limit(strip_tags($meta('og:site_name') ?: (string) parse_url($url, PHP_URL_HOST)), 80),
        ];
    }
}
