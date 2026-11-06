<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Base;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Uses its own cookie name. Sister sites on the parent domain can set a cookie called
 * XSRF-TOKEN that reaches this site too, and the browser would then send their token here.
 * The name must match xsrfCookieName in resources/js/bootstrap.ts.
 */
class ValidateCsrfToken extends Base
{
    public const COOKIE = 'PING-CHAT-XSRF';

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::COOKIE,
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }
}
