<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ConnectHandoffController;
use App\Http\Controllers\ConnectManageController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LlmsController;
use App\Http\Controllers\OAuthLandingController;
use App\Http\Controllers\PartnersController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportController;
use App\Integrations\Exact\Http\Dev\ExactOAuthTracerController;
use App\Integrations\Exact\Http\ExactDeprovisionController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/up', function () {
    $dependencies = ['database' => 'fail', 'redis' => 'fail'];

    try {
        $dependencies['database'] = DB::connection()->getPdo() !== null ? 'ok' : 'fail';
    } catch (Throwable) {
        $dependencies['database'] = 'fail';
    }

    try {
        $dependencies['redis'] = str_contains((string) Redis::ping(), 'PONG') ? 'ok' : 'fail';
    } catch (Throwable) {
        $dependencies['redis'] = 'fail';
    }

    $healthy = ! in_array('fail', $dependencies, true);

    return response()->json(
        ['status' => $healthy ? 'up' : 'degraded', ...$dependencies],
        $healthy ? 200 : 503,
    );
});

Route::middleware('signed')->group(function (): void {
    Route::get('/oauth/connected/{connection}', [OAuthLandingController::class, 'connected'])
        ->name('oauth.connected');
    Route::get('/oauth/failed', [OAuthLandingController::class, 'failed'])
        ->name('oauth.failed');
});

Route::middleware('signed')->group(function (): void {
    Route::get('/connect/{account}', [ConnectHandoffController::class, 'show'])
        ->name('connect.show');
    Route::post('/connect/{account}/{provider}', [ConnectHandoffController::class, 'start'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('throttle:12,1')
        ->name('connect.start');
    Route::delete('/connect/{account}/{provider}', [ConnectHandoffController::class, 'disconnect'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('throttle:12,1')
        ->name('connect.disconnect');

    Route::get('/connect/{account}/{provider}/manage', [ConnectManageController::class, 'show'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->name('connect.manage.show');
    Route::put('/connect/{account}/{provider}/manage/mapping', [ConnectManageController::class, 'updateMapping'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('throttle:12,1')
        ->name('connect.manage.mapping');
    Route::patch('/connect/{account}/{provider}/manage/relations/{ref}', [ConnectManageController::class, 'relinkRelation'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('throttle:12,1')
        ->name('connect.manage.relations.relink');
    Route::delete('/connect/{account}/{provider}/manage/relations/{ref}', [ConnectManageController::class, 'unlinkRelation'])
        ->where('provider', '[a-z][a-z0-9_-]*')
        ->middleware('throttle:12,1')
        ->name('connect.manage.relations.unlink');
});

Route::get('/connect/{account}/{provider}/manage/relations/search', [ConnectManageController::class, 'searchRelations'])
    ->where('provider', '[a-z][a-z0-9_-]*')
    ->middleware(['signed:q', 'throttle:20,1'])
    ->name('connect.manage.relations.search');

Route::get('/exact/stop', [ExactDeprovisionController::class, 'confirm'])->name('exact.stop');
Route::post('/exact/stop', [ExactDeprovisionController::class, 'destroy'])
    ->middleware('throttle:6,1')
    ->name('exact.stop.destroy');
Route::get('/exact/stop/klaar', [ExactDeprovisionController::class, 'done'])->name('exact.stop.done');

Route::get('/partners', [PartnersController::class, 'index'])->name('partners.index');
Route::get('/partners/{provider}', [PartnersController::class, 'show'])->name('partners.show');

Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/voorwaarden', [LegalController::class, 'terms'])->name('terms');
Route::get('/verwerkersovereenkomst', [LegalController::class, 'processorAgreement'])->name('processor-agreement');

Route::get('/support', SupportController::class)->name('support');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/llms.txt', LlmsController::class)->name('llms');

Route::get('/koppelen', [AccessRequestController::class, 'create'])->name('koppelen');
Route::post('/koppelen', [AccessRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('koppelen.store');

Route::get('/demo', [DemoRequestController::class, 'create'])->name('demo');
Route::post('/demo', [DemoRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('demo.store');

if (app()->environment('local', 'testing')) {
    Route::get('/admin/quick-login/{role?}', function (string $role = 'super-admin') {
        abort_unless(in_array($role, ['super-admin', 'staff'], true), 404);

        $user = User::role($role)->first();
        abort_unless($user !== null, 404, "Geen {$role}-user — draai EmeqStaffSeeder eerst.");

        Auth::login($user);

        return redirect('/admin');
    })->name('admin.quick-login');

    Route::get('/dev/exact/start', [ExactOAuthTracerController::class, 'start'])->name('dev.exact.start');
    Route::match(['get', 'post'], '/dev/exact/callback', [ExactOAuthTracerController::class, 'callback'])->name('dev.exact.callback');
    Route::get('/dev/exact/refresh', [ExactOAuthTracerController::class, 'refresh'])->name('dev.exact.refresh');
    Route::match(['get', 'post'], '/dev/exact/stop', [ExactOAuthTracerController::class, 'stop'])->name('dev.exact.stop');
    Route::match(['get', 'post'], '/dev/exact/info', [ExactOAuthTracerController::class, 'info'])->name('dev.exact.info');
}
