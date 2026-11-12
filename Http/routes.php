<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\WebPush\Http\Controllers'], function () {
    // Installable app: public manifest and service worker (read before login)
    Route::get('/webpush/manifest', 'WebPushController@manifest')->name('webpush.manifest');
    Route::get('/webpush/service-worker', 'WebPushController@serviceWorker')->name('webpush.sw');
    // Push subscriptions of the agents' devices
    Route::get('/webpush/key', ['uses' => 'WebPushController@key', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.key');
    Route::post('/webpush/subscribe', ['uses' => 'WebPushController@subscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.subscribe');
    Route::post('/webpush/unsubscribe', ['uses' => 'WebPushController@unsubscribe', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.unsubscribe');
    Route::get('/webpush/devices', ['uses' => 'WebPushController@devices', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.devices');
    Route::get('/webpush/badge', ['uses' => 'WebPushController@badge', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.badge');
    // Health check and diagnostics file (admins only; also: php artisan webpush:health)
    Route::get('/webpush/health', ['uses' => 'WebPushController@health', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('webpush.health');
    Route::get('/webpush/diagnostics', ['uses' => 'WebPushController@diagnostics', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('webpush.diagnostics');
    Route::post('/webpush/test', ['uses' => 'WebPushController@test', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.test');
    // Shortcuts of the installed app (long press on its icon): resolved for the signed-in agent
    Route::get('/webpush/go/{target}', ['uses' => 'WebPushController@go', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.go');
    // "Share" menu of the phone: the page that sends what was shared to a ticket or to a new one
    Route::get('/webpush/share', ['uses' => 'WebPushController@share', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.share');
    Route::post('/webpush/share/note', ['uses' => 'WebPushController@shareNote', 'middleware' => ['auth', 'roles'], 'roles' => ['admin', 'user']])->name('webpush.share.note');

    // Former URLs of the Refresh module (1.2 and older), where this feature came from: devices subscribed back then
    // keep their service worker and installed app until they pick up the new URLs.
    Route::get('/refresh/service-worker', 'WebPushController@serviceWorker')->name('webpush.legacy.sw');
    Route::get('/refresh/pwa-manifest', 'WebPushController@manifest')->name('webpush.legacy.manifest');
});

// Called by the service worker, which has neither the session's CSRF token nor, in the background, a page: no "web"
// middleware. Each request proves itself instead: a notification button carries a token signed for that agent, that
// ticket and that action; a renewed subscription is accepted only with the secret of the one it replaces.
Route::group(['prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\WebPush\Http\Controllers'], function () {
    Route::post('/webpush/action', 'WebPushController@action')->name('webpush.action');
    Route::post('/webpush/resubscribe', 'WebPushController@resubscribe')->name('webpush.resubscribe');
    // What was shared normally never gets here: the service worker keeps it on the phone. Without the worker (not
    // installed yet), the share page opens, empty.
    Route::post('/webpush/share', 'WebPushController@shareFallback')->name('webpush.share.fallback');
});
