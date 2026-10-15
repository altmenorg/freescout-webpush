<?php

namespace Modules\WebPush\Services;

/**
 * Health check: is Web Push complete and able to send? For an updater that has just replaced the module (decide on a
 * rollback), or for an admin. Read-only (server keys are only read, never created here), no network.
 * - php artisan webpush:health [--json]   exit code 0 when healthy, 1 otherwise
 * - GET /webpush/health                   same report in JSON, admins only, HTTP 200 or 503
 */
class Health
{
    const CLASSES = [
        Settings::class, WebPush::class, Diagnostics::class,
        \Modules\WebPush\Http\Controllers\WebPushController::class,
    ];

    const VIEWS = ['webpush::settings'];

    /** Served through FreeScout's public/modules/webpush link. */
    const ASSETS = ['css/webpush.css', 'js/diagnostics.js', 'img/badge.png'];

    const ROUTES = ['webpush.manifest', 'webpush.sw', 'webpush.key', 'webpush.subscribe', 'webpush.unsubscribe',
        'webpush.devices', 'webpush.badge', 'webpush.test', 'webpush.health', 'webpush.diagnostics'];

    public static function check()
    {
        $checks = [];
        $run = function ($name, callable $test) use (&$checks) {
            try {
                $error = $test();
                $checks[$name] = $error ? ['ok' => false, 'error' => $error] : ['ok' => true];
            } catch (\Throwable $e) {
                $checks[$name] = ['ok' => false, 'error' => get_class($e).': '.Diagnostics::redact($e->getMessage())];
            }
        };

        $run('module_json', function () {
            $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true);
            return !empty($json['version']) ? null : 'module.json missing or unreadable';
        });
        $run('php', function () {
            $missing = array_values(array_filter(['openssl', 'curl', 'json', 'mbstring'], function ($e) {
                return !extension_loaded($e);
            }));
            if (!function_exists('openssl_pkey_derive')) {
                $missing[] = 'openssl_pkey_derive (PHP 7.3+)';
            }
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('classes', function () {
            $missing = array_values(array_filter(static::CLASSES, function ($c) {
                return !class_exists($c);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('views', function () {
            $missing = array_values(array_filter(static::VIEWS, function ($v) {
                return !\View::exists($v);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('routes', function () {
            $routes = \Route::getRoutes();
            $missing = array_values(array_filter(static::ROUTES, function ($n) use ($routes) {
                return !$routes->getByName($n);
            }));
            return $missing ? 'missing: '.implode(', ', $missing) : null;
        });
        $run('assets', function () {
            $missing = array_values(array_filter(static::ASSETS, function ($a) {
                return !is_file(public_path('modules/webpush/'.$a));
            }));
            return $missing ? 'not served (public/modules/webpush): '.implode(', ', $missing) : null;
        });
        $run('storage', function () {
            $dir = storage_path('app/webpush');
            if (!is_dir($dir)) {
                return is_writable(storage_path('app')) ? null : 'storage/app is not writable: server keys cannot be created';
            }
            if (!is_writable($dir)) {
                return 'storage/app/webpush is not writable';
            }
            $file = $dir.'/push_subscriptions.json';
            if (is_file($file) && !is_array(json_decode((string)file_get_contents($file), true))) {
                return 'push_subscriptions.json is unreadable';
            }
            return null;
        });
        $run('server_keys', function () {
            $file = storage_path('app/webpush/vapid.json');
            if (!is_file($file)) {
                return null; // created on first use (an agent opening FreeScout)
            }
            $k = json_decode((string)file_get_contents($file), true);
            if (empty($k['private']) || !openssl_pkey_get_private($k['private'])) {
                return 'vapid.json: private key unreadable';
            }
            return strlen(base64_decode(strtr($k['public'] ?? '', '-_', '+/'))) === 65 ? null : 'vapid.json: public key invalid';
        });
        $run('encryption', function () {
            return WebPush::selfTest();
        });

        $ok = !in_array(false, array_column($checks, 'ok'), true);
        $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true) ?: [];
        return [
            'module' => 'WebPush',
            'version' => $json['version'] ?? null,
            'ok' => $ok,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
        ];
    }
}
