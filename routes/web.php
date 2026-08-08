<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\Dev\ExactOAuthTracerController;
use App\Http\Controllers\ExactDeprovisionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LlmsController;
use App\Http\Controllers\OAuthLandingController;
use App\Http\Controllers\PartnersController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Publieke marketing-homepage (indexeerbaar; zie SetNoIndexHeaders). De
// health-check leeft op /up.
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/up', function () {
    return response()->json([
        'status' => 'up',
        'database' => DB::connection()->getPdo() !== null ? 'ok' : 'fail',
        'redis' => str_contains((string) Redis::ping(), 'PONG') ? 'ok' : 'fail',
    ]);
});

// OAuth-landing — branded HTML na de partner-callback (PRG). De callbacks in
// routes/api.php draaien stateless (`api`-middleware, geen sessie) en redirecten
// hierheen met een tijdelijk-getekende URL; `signed` blokkeert tampering/enumeratie.
Route::middleware('signed')->group(function (): void {
    Route::get('/oauth/connected/{connection}', [OAuthLandingController::class, 'connected'])
        ->name('oauth.connected');
    Route::get('/oauth/failed', [OAuthLandingController::class, 'failed'])
        ->name('oauth.failed');
});

// Exact App Center Seamless-deprovisioning ("Niet meer gebruiken"): Exact
// redirect de gebruiker naar deze URL met ?Country=&Language=&UserId=. Publiek
// en noindex (niet in PublicPages); de POST komt van onze eigen confirm-pagina
// (sessie + CSRF), niet van Exact. Registreer /exact/stop als Deprovisioning
// URL in het App Center.
Route::get('/exact/stop', [ExactDeprovisionController::class, 'confirm'])->name('exact.stop');
Route::post('/exact/stop', [ExactDeprovisionController::class, 'destroy'])
    ->middleware('throttle:6,1')
    ->name('exact.stop.destroy');
Route::get('/exact/stop/klaar', [ExactDeprovisionController::class, 'done'])->name('exact.stop.done');

// Publieke integraties-showcase. Indexeerbaar (uitgezonderd van SetNoIndexHeaders
// via routeIs('partners.*')); toont alleen statische provider-content, geen tenant-data.
Route::get('/partners', [PartnersController::class, 'index'])->name('partners.index');
Route::get('/partners/{provider}', [PartnersController::class, 'show'])->name('partners.show');

// Publieke juridische pagina's. Teksten beheerd in de admin (ManageLegalPages),
// server-side gerenderd. Indexeerbaar (zie SetNoIndexHeaders).
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/voorwaarden', [LegalController::class, 'terms'])->name('terms');
Route::get('/verwerkersovereenkomst', [LegalController::class, 'processorAgreement'])->name('processor-agreement');

// Publieke support-pagina — statische content (e-mail, FAQ) uit het
// landingspage-design; geen tenant-data. Controller i.p.v. Route::inertia
// omdat de pagina server-side SEO-meta + FAQ-structured-data meekrijgt.
Route::get('/support', SupportController::class)->name('support');

// Crawler-bestanden als route, niet als statisch bestand in public/: ze worden
// afgeleid van PublicPages + de showcase-config en groeien zo mee met nieuwe
// providers. Een statische public/robots.txt zou hier bovendien vóór komen.
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/llms.txt', LlmsController::class)->name('llms');

// Publieke koppel-intake — eigen pagina (landingspage-design "Start met
// koppelen") + hetzelfde formulier op elke partner-pagina, preselect op die
// provider. Geen auth → POST achter throttle + honeypot (zie AccessRequestController).
Route::get('/koppelen', [AccessRequestController::class, 'create'])->name('koppelen');
Route::post('/koppelen', [AccessRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('koppelen.store');

// Publieke demo-aanvraag (landingspage-design "Demo aanvragen") — mail-only,
// geen persistentie. Zelfde honeypot + throttle-opzet als de koppel-intake.
Route::get('/demo', [DemoRequestController::class, 'create'])->name('demo');
Route::post('/demo', [DemoRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('demo.store');

// Dev-only routes — STRICT guard. `! app()->isProduction()` is te breed: laat
// `/admin/quick-login` open op preview/staging-deploys. Whitelist alleen
// `local` + `testing`; preview/staging/UAT moeten echte login
// gebruiken (zie REVIEW.md CR-01).
if (app()->environment('local', 'testing')) {
    Route::get('/admin/quick-login/{role?}', function (string $role = 'super-admin') {
        abort_unless(in_array($role, ['super-admin', 'staff'], true), 404);

        $user = User::role($role)->first();
        abort_unless($user !== null, 404, "Geen {$role}-user — draai EmeqStaffSeeder eerst.");

        Auth::login($user);

        return redirect('/admin');
    })->name('admin.quick-login');

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
