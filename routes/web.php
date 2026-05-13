<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'version' => '0.1.0-dev',
    'status' => 'ok',
]));

Route::get('/up', function () {
    return response()->json([
        'status' => 'up',
        'database' => \Illuminate\Support\Facades\DB::connection()->getPdo() !== null ? 'ok' : 'fail',
        'redis' => str_contains((string) \Illuminate\Support\Facades\Redis::ping(), 'PONG') ? 'ok' : 'fail',
    ]);
});
