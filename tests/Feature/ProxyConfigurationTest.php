<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyConfigurationTest extends TestCase
{
    public function test_only_the_configured_proxy_can_supply_client_ip_and_scheme(): void
    {
        config(['app.trusted_proxies' => ['192.0.2.10']]);
        Route::get('/proxy-check', fn (Request $request) => [
            'ip' => $request->ip(), 'secure' => $request->isSecure(), 'host' => $request->getHost(),
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.20', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
            ->get('http://localhost/proxy-check')->assertJson(['ip' => '198.51.100.20', 'secure' => true, 'host' => 'localhost']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->get('http://localhost/proxy-check')->assertJson(['ip' => '192.0.2.11', 'secure' => false, 'host' => 'localhost']);
    }
}
