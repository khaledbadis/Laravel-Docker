<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::select('select 1');
    $socket = @fsockopen('127.0.0.1', 9000, $error, $message, 2);
    if (! $socket) {
        exit(1);
    }
    fclose($socket);
} catch (Throwable $exception) {
    exit(1);
}
