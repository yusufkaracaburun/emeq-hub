<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'version' => '0.1.0-dev',
    'status' => 'ok',
]));

Route::get('/up', function () {
    return response()->json([
        'status' => 'up',
        'database' => DB::connection()->getPdo() !== null ? 'ok' : 'fail',
        'redis' => str_contains((string) Redis::ping(), 'PONG') ? 'ok' : 'fail',
    ]);
});

if (! app()->isProduction()) {
    Route::get('/admin/quick-login/{role?}', function (string $role = 'super-admin') {
        abort_unless(in_array($role, ['super-admin', 'staff'], true), 404);

        $user = User::role($role)->first();
        abort_unless($user !== null, 404, "Geen {$role}-user — draai EmeqStaffSeeder eerst.");

        Auth::login($user);

        return redirect('/admin');
    })->name('admin.quick-login');

    // Provider-preview pagina's voor certificering / partnership-aanvragen.
    // Provider-set komt uit config/hub-providers.php — automatisch up-to-date
    // zodra een nieuwe SDK een entry krijgt (D-04 discovery-pattern).
    Route::get('/dev/partners', function () {
        $providers = array_keys(config('hub-providers', []));

        return response()->view('partners.index', ['providers' => $providers]);
    })->name('dev.partners.index');

    Route::get('/dev/partners/{provider}', function (string $provider) {
        abort_unless(array_key_exists($provider, config('hub-providers', [])), 404);

        $view = "partners.{$provider}.example";
        abort_unless(view()->exists($view), 404, "Geen voorbeeldpagina voor `{$provider}` — maak `resources/views/{$view}.blade.php`.");

        return response()->view($view);
    })->name('dev.partners.preview');
}
