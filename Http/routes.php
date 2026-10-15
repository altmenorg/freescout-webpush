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

    // Former URLs of the Refresh module (1.2 and older), where this feature came from: devices subscribed back then
    // keep their service worker and installed app until they pick up the new URLs.
    Route::get('/refresh/service-worker', 'WebPushController@serviceWorker')->name('webpush.legacy.sw');
    Route::get('/refresh/pwa-manifest', 'WebPushController@manifest')->name('webpush.legacy.manifest');
});
