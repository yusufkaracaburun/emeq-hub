<?php

use App\Http\Controllers\Dev\ExactOAuthTracerController;
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
    //
    // CR-04: bouw de authorize-URL VÓÓR we de pending Connection inserten.
    // Voorheen liet een fout in getAuthorizationUrl() (Pennant kill-switch,
    // missing config, network) een orphan pending-Connection achter op elke
    // retry. 30-min oauth_state TTL betekent dat ze 30+ min lang in de DB
    // bleven plakken en de partner-status-widget vervuilden.
    Route::get('/dev/partners/mollie/start-oauth', function () {
        $account = Account::query()
            ->whereHas('consumer', fn ($q) => $q->where('slug', 'naschool'))
            ->first();
        abort_unless($account !== null, 404, 'Geen demo-Account — draai EmeqStaffSeeder + Naschool-seed eerst.');

        $state = Str::random(48);
        $scopes = config('services.mollie.connect.scopes');

        try {
            $flow = app(OAuthFlowRegistry::class)->for('mollie');
            $url = $flow->getAuthorizationUrl($account, $scopes, $state);
        } catch (Throwable $e) {
            abort(503, 'Mollie OAuth-flow niet beschikbaar: '.$e->getMessage());
        }

        $account->connections()->create([
            'provider' => 'mollie',
            'status' => 'pending',
            'oauth_state' => $state,
            'oauth_state_expires_at' => now()->addMinutes(30),
        ]);

        return redirect()->away($url);
    })->name('dev.partners.mollie.start-oauth');

    // Exact Online OAuth + Seamless-connection TRACER (wegwerp-harnas).
    // Draait een echte OAuth-round-trip met de test-app-creds en legt vast wat
    // Exact naar de Seamless-lifecycle-URIs stuurt. Aparte `/dev/exact/*`-paden
    // zodat ze niet botsen met de echte `/v1/oauth/exact/*`-endpoints uit de
    // Hub-wiring-slice. Registreer deze als de tunnel-URI's in het Exact App
    // Center; captures → storage/logs/exact-tracer.log.
    Route::get('/dev/exact/start', [ExactOAuthTracerController::class, 'start'])->name('dev.exact.start');
    Route::match(['get', 'post'], '/dev/exact/callback', [ExactOAuthTracerController::class, 'callback'])->name('dev.exact.callback');
    Route::get('/dev/exact/refresh', [ExactOAuthTracerController::class, 'refresh'])->name('dev.exact.refresh');
    Route::match(['get', 'post'], '/dev/exact/stop', [ExactOAuthTracerController::class, 'stop'])->name('dev.exact.stop');
    Route::match(['get', 'post'], '/dev/exact/info', [ExactOAuthTracerController::class, 'info'])->name('dev.exact.info');
}
