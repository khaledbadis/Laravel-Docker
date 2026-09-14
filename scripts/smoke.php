<?php

// HTTP and persistence checks for scripts/verify.sh's disposable stack only.
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Standalone checks must return a failing process status, including with Laravel booted.
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
});

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

check($app->environment('local') && str_starts_with((string) env('COMPOSE_PROJECT_NAME'), 'phase7-'), 'Smoke checks require the disposable phase7 project.');
check(in_array($argv[1] ?? '', ['before', 'after'], true), 'Expected before or after.');

function httpRequest(string $path, ?array $form = null): array
{
    $handle = curl_init('http://web'.$path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => storage_path('framework/phase7-cookies.txt'),
        CURLOPT_COOKIEJAR => storage_path('framework/phase7-cookies.txt'),
    ]);
    if ($form !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($handle);
    check($body !== false, 'HTTP request failed: '.curl_error($handle));

    return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body];
}

function token(string $html): string
{
    check(preg_match('/name="_token" value="([^"]+)"/', $html, $match) === 1, 'CSRF token missing.');

    return html_entity_decode($match[1]);
}

[$status, $body] = httpRequest('/healthz');
check($status === 200 && trim($body) === 'nginx ok', 'Nginx health failed.');
check(httpRequest('/up')[0] === 200, 'Laravel health failed.');
check(httpRequest('/.env')[0] === 403, 'Environment file was not protected.');
check(httpRequest('/composer.json')[0] === 404, 'Private source was not protected.');
check(httpRequest('/arbitrary.php')[0] === 404, 'Arbitrary PHP was not protected.');

$email = 'verification@example.test';
$password = 'disposable-verification-password';
if ($argv[1] === 'before') {
    check(httpRequest('/')[0] === 302, 'Guests must be redirected.');
    [$status, $html] = httpRequest('/register');
    check($status === 200, 'Registration page missing.');
    check(httpRequest('/register', [
        '_token' => token($html), 'name' => 'Verification User', 'email' => $email,
        'password' => $password, 'password_confirmation' => $password,
    ])[0] === 302, 'Registration failed.');
    $user = User::where('email', $email)->sole();
    Auth::login($user);
    app(TaskService::class)->create(['title' => 'Survives container recreation', 'notes' => 'Saved in PostgreSQL.']);
    Cache::put('phase7-persistence', 'still here', 3600);
} else {
    check(Cache::get('phase7-persistence') === 'still here', 'Database cache did not persist.');
    check(User::where('email', $email)->sole()->tasks()->sole()->title === 'Survives container recreation', 'User/task did not persist.');
}

[$status, $html] = httpRequest('/');
check($status === 200 && str_contains($html, 'Survives container recreation'), 'Authenticated session or task did not persist.');
check(str_contains($html, 'wire:snapshot='), 'Livewire component missing.');
check(! file_exists(public_path('hot')), 'Compiled mode must not use a Vite hot file.');
check(preg_match_all('~(?:href|src)="[^"]*(/build/assets/[^"?]+)~', $html, $assets) >= 1, 'Compiled assets missing.');
foreach (array_unique($assets[1]) as $asset) {
    check(httpRequest($asset)[0] === 200, 'Compiled asset request failed.');
}
check(preg_match('~<script src="([^"]+)"[^>]*data-update-uri=~', $html, $livewire) === 1, 'Livewire script tag missing.');
$scriptPath = parse_url(html_entity_decode($livewire[1]), PHP_URL_PATH);
check(httpRequest($scriptPath)[0] === 200, 'Livewire JavaScript missing.');

if ($argv[1] === 'after') {
    check(httpRequest('/logout', [])[0] === 419, 'Logout must enforce CSRF.');
    check(httpRequest('/logout', ['_token' => token($html)])[0] === 302, 'Logout failed.');
    check(httpRequest('/')[0] === 302, 'Logout did not protect the home page.');
    [$status, $login] = httpRequest('/login');
    check($status === 200, 'Login page missing.');
    check(httpRequest('/login', ['_token' => token($login), 'email' => $email, 'password' => $password])[0] === 302, 'Login failed.');
    check(httpRequest('/')[0] === 200, 'Login session failed.');
    unlink(storage_path('framework/phase7-cookies.txt'));
}
echo "HTTP and persistence checks passed ({$argv[1]}).\n";
