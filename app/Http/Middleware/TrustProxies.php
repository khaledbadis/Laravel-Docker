<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

class TrustProxies extends \Illuminate\Http\Middleware\TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    public function __construct()
    {
        $this->proxies = config('app.trusted_proxies', []);
    }
}
