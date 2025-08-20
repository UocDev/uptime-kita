<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PinnedMonitorController;
use App\Http\Controllers\PrivateMonitorController;
use App\Http\Controllers\PublicMonitorController;
use App\Http\Controllers\PublicStatusPageController;
use App\Http\Controllers\StatisticMonitorController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\SubscribeMonitorController;
use App\Http\Controllers\TestFlashController;
use App\Http\Controllers\UnsubscribeMonitorController;
use App\Http\Controllers\UptimeMonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicMonitorController::class, 'index'])->name('home');

Route::get('/public-monitors', [PublicMonitorController::class, 'index'])->name('monitor.public');
Route::get('/statistic-monitor', StatisticMonitorController::class)->name('monitor.statistic');
Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

// Public monitor show route (using clean domain as unique key)
Route::get('/m/{domain}', [App\Http\Controllers\PublicMonitorShowController::class, 'show'])
    ->where('domain', '[a-zA-Z0-9.-]+')
    ->name('monitor.public.show');

// Public status page route
Route::get('/status/{path}', [PublicStatusPageController::class, 'show'])->name('status-page.public');
Route::get('/status/{path}/monitors', [PublicStatusPageController::class, 'monitors'])->name('status-page.public.monitors');
Route::get('/monitor/{monitor}/latest-history', \App\Http\Controllers\LatestHistoryController::class)->name('monitor.latest-history');

// AJAX route for pinned monitors data (returns JSON)
Route::middleware(['auth'])->group(function () {
    Route::get('/pinned-monitors', [PinnedMonitorController::class, 'index'])->name('monitor.pinned');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Inertia route for toggle pin action
    Route::post('/monitor/{monitorId}/toggle-pin', [PinnedMonitorController::class, 'toggle'])->name('monitor.toggle-pin');
    // Route untuk private monitor
    Route::get('/private-monitors', PrivateMonitorController::class)->name('monitor.private');

    // Resource route untuk CRUD monitor
    Route::resource('monitor', UptimeMonitorController::class);
    // Route untuk subscribe monitor
    Route::post('/monitor/{monitorId}/subscribe', SubscribeMonitorController::class)->name('monitor.subscribe');
    // Route untuk unsubscribe monitor
    Route::delete('/monitor/{monitorId}/unsubscribe', UnsubscribeMonitorController::class)->name('monitor.unsubscribe');

    // Tag routes
    Route::get('/tags', [\App\Http\Controllers\TagController::class, 'index'])->name('tags.index');
    Route::get('/tags/search', [\App\Http\Controllers\TagController::class, 'search'])->name('tags.search');

    // Route untuk toggle monitor active status
    Route::post('/monitor/{monitorId}/toggle-active', \App\Http\Controllers\ToggleMonitorActiveController::class)->name('monitor.toggle-active');

    // Get monitor history
    Route::get('/monitor/{monitor}/history', [UptimeMonitorController::class, 'getHistory'])->name('monitor.history');
    Route::get('/monitor/{monitor}/uptimes-daily', \App\Http\Controllers\UptimesDailyController::class)->name('monitor.uptimes-daily');

    // Status page management routes
    Route::resource('status-pages', StatusPageController::class);

    // Status page monitor association routes
    Route::post('/status-pages/{statusPage}/monitors', \App\Http\Controllers\StatusPageAssociateMonitorController::class)->name('status-pages.monitors.associate');
    Route::delete('/status-pages/{statusPage}/monitors/{monitor}', \App\Http\Controllers\StatusPageDisassociateMonitorController::class)->name('status-pages.monitors.disassociate');
    Route::get('/status-pages/{statusPage}/available-monitors', \App\Http\Controllers\StatusPageAvailableMonitorsController::class)->name('status-pages.monitors.available');
    Route::post('/status-page-monitor/reorder/{statusPage}', \App\Http\Controllers\StatusPageOrderController::class)->name('status-page-monitor.reorder');

    // Custom domain routes
    Route::post('/status-pages/{statusPage}/custom-domain', [\App\Http\Controllers\CustomDomainController::class, 'update'])->name('status-pages.custom-domain.update');
    Route::post('/status-pages/{statusPage}/verify-domain', [\App\Http\Controllers\CustomDomainController::class, 'verify'])->name('status-pages.custom-domain.verify');
    Route::get('/status-pages/{statusPage}/dns-instructions', [\App\Http\Controllers\CustomDomainController::class, 'dnsInstructions'])->name('status-pages.custom-domain.dns');

    // User management routes
    Route::resource('users', \App\Http\Controllers\UserController::class);
});

// Test route for flash messages
Route::get('/test-flash', TestFlashController::class)->name('test.flash');
// route group for health check
Route::get('/health', \Spatie\Health\Http\Controllers\SimpleHealthCheckController::class)->name('health.index');
Route::middleware('auth')->prefix('health')->as('health.')->group(function () {
    Route::get('/json', \Spatie\Health\Http\Controllers\HealthCheckJsonResultsController::class)->name('json');
    Route::get('/results', \Spatie\Health\Http\Controllers\HealthCheckResultsController::class)->name('results');
});

Route::prefix('webhook')->as('webhook.')->group(function () {
    Route::post('/telegram', [\App\Http\Controllers\TelegramWebhookController::class, 'handle'])->name('telegram');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
