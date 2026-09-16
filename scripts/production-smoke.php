<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Copied into /tmp only by the isolated production rehearsal; never baked into images.
try {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (getenv('APP_URL') !== 'https://edge' || getenv('DB_DATABASE') !== 'phase8_test') {
        throw new RuntimeException('Production smoke checks require the isolated rehearsal.');
    }
    function verify(bool $value, string $message): void
    {
        if (! $value) {
            throw new RuntimeException($message);
        }
    }
    function http(string $path, ?array $form = null, bool $json = false): array
    {
        $curl = curl_init('https://edge'.$path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CAINFO => '/tmp/rehearsal.crt',
            CURLOPT_COOKIEFILE => '/var/www/html/storage/app/private/phase8-cookies',
            CURLOPT_COOKIEJAR => '/var/www/html/storage/app/private/phase8-cookies',
        ]);
        if ($form !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($form) : http_build_query($form));
            if ($json) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Livewire: true']);
            }
        }
        $body = curl_exec($curl);
        verify($body !== false, 'HTTPS request failed: '.curl_error($curl));

        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
    }
    verify($app->environment('production') && ! config('app.debug'), 'Production configuration missing.');
    verify($app->configurationIsCached() && $app->routesAreCached(), 'Runtime caches missing.');
    verify(config('session.secure') === true, 'Secure cookies required.');
    verify(http('/up')[0] === 200, 'HTTPS health failed.');
    verify(config('auth.registration_enabled') === false, 'Production registration must be closed.');
    verify(http('/.env')[0] === 403 && http('/composer.json')[0] === 404, 'Private files exposed.');
    if (($argv[1] ?? '') === 'before') {
        verify(http('/register')[0] === 404, 'Guest registration must be closed.');
        User::create(['name' => 'Production Check', 'email' => 'phase8@example.test', 'password' => 'temporary-production-check']);
        [$status, $login] = http('/login');
        verify($status === 200 && preg_match('/name="_token" value="([^"]+)"/', $login, $csrf) === 1, 'Login form missing.');
        verify(http('/login', ['_token' => $csrf[1], 'email' => 'phase8@example.test', 'password' => 'temporary-production-check'])[0] === 302, 'HTTPS login failed.');
        [$status, $html] = http('/');
        verify($status === 200, 'Secure session failed.');
        verify(preg_match('/wire:snapshot="([^"]+)"/', $html, $snapshot) === 1, 'Livewire snapshot missing.');
        verify(preg_match('/data-update-uri="([^"]+)"/', $html, $uri) === 1, 'Livewire endpoint missing.');
        verify(preg_match('/name="csrf-token" content="([^"]+)"/', $html, $csrf) === 1, 'CSRF token missing.');
        [$status, $response] = http(parse_url(html_entity_decode($uri[1]), PHP_URL_PATH), [
            '_token' => $csrf[1],
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1]),
                'updates' => ['title' => 'Production task survives releases', 'notes' => 'Created over HTTPS by Livewire.'],
                'calls' => [['method' => 'save', 'params' => []]],
            ]],
        ], true);
        verify($status === 200 && str_contains($response, 'Task added.'), 'Production Livewire action failed.');
    }
    [$status, $html] = http('/');
    verify($status === 200 && str_contains($html, 'Production task survives releases'), 'Task or session did not survive deployment.');
    verify(Task::where('title', 'Production task survives releases')->count() === 1, 'Task persistence failed.');
    preg_match_all('~(?:href|src)="[^"]*(/build/assets/[^"?]+)~', $html, $assets);
    verify(count($assets[1]) > 0, 'Compiled assets missing.');
    foreach (array_unique($assets[1]) as $asset) {
        verify(http($asset)[0] === 200, 'Asset response failed.');
    }
    echo "Production HTTPS, assets, Livewire, and persistence passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
