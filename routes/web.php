<?php

use App\Models\Account;
use App\Models\User;
use App\OAuth\OAuthFlowRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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

// Dev-only routes — STRICT guard. `! app()->isProduction()` is te breed: laat
// `/admin/quick-login` open op preview/staging-deploys (Laravel Cloud, etc.).
// Whitelist alleen `local` + `testing`; preview/staging/UAT moeten echte login
// gebruiken (zie REVIEW.md CR-01).
if (app()->environment('local', 'testing')) {
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

        return response()->view($view, ['provider' => $provider]);
    })->name('dev.partners.preview');

    // Plan 08-05 — Dev-only Mollie OAuth-init trigger (D-06 §3, UI-SPEC §S3 regel 191).
    // Hergebruikt Phase-4 InitController-pattern: 48-char state + 30-min TTL pending
    // Connection + Mollie authorize-redirect. Pre-selected demo-Account = eerste
    // Account van de Naschool-Consumer (geseed via DatabaseSeeder).
    Route::get('/dev/partners/mollie/start-oauth', function () {
        $account = Account::query()
            ->whereHas('consumer', fn ($q) => $q->where('slug', 'naschool'))
            ->first();
        abort_unless($account !== null, 404, 'Geen demo-Account — draai EmeqStaffSeeder + Naschool-seed eerst.');

        $state = Str::random(48);
        $account->connections()->create([
            'provider' => 'mollie',
            'status' => 'pending',
            'oauth_state' => $state,
            'oauth_state_expires_at' => now()->addMinutes(30),
        ]);

        $scopes = config('services.mollie.connect.scopes');
        $url = app(OAuthFlowRegistry::class)->for('mollie')->getAuthorizationUrl($account, $scopes, $state);

        return redirect()->away($url);
    })->name('dev.partners.mollie.start-oauth');
}
