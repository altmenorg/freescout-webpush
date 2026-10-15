<?php

namespace Modules\WebPush\Services;

/**
 * Diagnostics file for bug reports (Manage > Settings > Web Push > Diagnostics): versions, which settings are set,
 * the health check, devices by push service, and the module's last log errors. It ends up attached to a public GitHub
 * issue: no app name, address, agent, device address or e-mail — only versions, counts and test results, and log
 * lines are redacted. Every section is computed on its own: one that fails is reported as such.
 */
class Diagnostics
{
    public static function report()
    {
        $report = ['format' => 1, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')];
        foreach (['webpush', 'health', 'freescout', 'php', 'devices', 'notification_settings', 'modules', 'log'] as $section) {
            try {
                $report[$section] = self::$section();
            } catch (\Throwable $e) {
                $report[$section] = ['error' => get_class($e).': '.self::redact($e->getMessage())];
            }
        }
        return $report;
    }

    protected static function webpush()
    {
        $json = json_decode((string)@file_get_contents(__DIR__.'/../module.json'), true) ?: [];
        $set = function ($key) {
            return \Option::get('webpush.'.$key, '') !== '' && \Option::get('webpush.'.$key, '') !== null;
        };
        return [
            'version' => $json['version'] ?? null,
            'app_name_set' => $set('app_name'),
            'short_name_set' => $set('app_short_name'),
            'custom_app_icon' => $set('app_icon_url'),
            'custom_status_bar_icon' => $set('badge_url'),
            'custom_contact' => $set('push_contact'),
            'hide_content' => Settings::hideContent(),
            'server_keys' => is_file(storage_path('app/webpush/vapid.json')),
        ];
    }

    protected static function health()
    {
        $health = Health::check();
        return ['ok' => $health['ok'], 'checks' => $health['checks']];
    }

    protected static function freescout()
    {
        return [
            'version' => config('app.version'),
            'locale' => config('app.locale'),
            'timezone' => config('app.timezone'),
            'https' => stripos((string)config('app.url'), 'https://') === 0,
            'subdirectory' => WebPush::basePath() !== '/',
            'debug' => (bool)config('app.debug'),
        ];
    }

    protected static function php()
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
            'curl' => function_exists('curl_version') ? (curl_version()['version'] ?? null) : null,
        ];
    }

    /** Subscribed devices: counts by push service and by browser/system, never their addresses. */
    protected static function devices()
    {
        $subs = WebPush::subscriptions();
        $services = [];
        $kinds = [];
        $users = [];
        $oldest = null;
        foreach ($subs as $s) {
            $host = (string)parse_url((string)($s['endpoint'] ?? ''), PHP_URL_HOST);
            $service = preg_match('/googleapis\.com$/', $host) ? 'google' : (preg_match('/mozilla\.com$/', $host) ? 'mozilla'
                : (preg_match('/apple\.com$/', $host) ? 'apple' : (preg_match('/windows\.com$|notify\.windows/', $host) ? 'microsoft' : 'other')));
            $services[$service] = ($services[$service] ?? 0) + 1;
            $kind = WebPush::describe($s['ua'] ?? '');
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            $users[(int)($s['user_id'] ?? 0)] = true;
            if (!empty($s['created']) && ($oldest === null || $s['created'] < $oldest)) {
                $oldest = $s['created'];
            }
        }
        return [
            'total' => count($subs),
            'agents' => count($users),
            'by_push_service' => $services,
            'by_browser' => $kinds,
            'oldest_days' => $oldest ? (int)floor((time() - strtotime($oldest)) / 86400) : null,
        ];
    }

    /** FreeScout's "Mobile" notification column, which this module sends: how many agents chose events in it. */
    protected static function notification_settings()
    {
        $medium = \App\Subscription::MEDIUM_MOBILE;
        return [
            'mobile_channel_available' => (bool)\Eventy::filter('notifications.mobile_available', false),
            'agents_with_mobile_events' => \App\Subscription::where('medium', $medium)->distinct()->count('user_id'),
        ];
    }

    protected static function modules()
    {
        $list = [];
        foreach (\Module::all() as $module) {
            $list[] = ['name' => $module->getName(), 'version' => $module->get('version'), 'active' => (bool)$module->active()];
        }
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $list;
    }

    /** The module's last error and warning lines of FreeScout's log ([WebPush]), redacted. */
    protected static function log()
    {
        $files = glob(storage_path('logs').'/laravel*.log') ?: [];
        if (!$files) {
            return ['file' => false];
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $fh = fopen($files[0], 'rb');
        if (!$fh) {
            return ['file' => true, 'readable' => false];
        }
        fseek($fh, max(0, filesize($files[0]) - 262144));
        $tail = (string)stream_get_contents($fh);
        fclose($fh);
        $entries = [];
        foreach (preg_split('/\R/', $tail) as $line) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.(ERROR|WARNING|CRITICAL): (.*)$/', $line, $m)
                && (stripos($m[3], 'webpush') !== false || stripos($m[3], 'Modules/WebPush') !== false)) {
                $entries[] = $m[1].' '.$m[2].': '.mb_substr(self::redact(preg_replace('/ \{".*$/s', '', $m[3])), 0, 400);
            }
        }
        return ['file' => true, 'webpush_lines_in_tail' => count($entries), 'last' => array_slice($entries, -15)];
    }

    /** Removes what could identify the installation or its people. */
    public static function redact($text)
    {
        $text = str_replace(base_path(), '<freescout>', (string)$text);
        $text = preg_replace('/\(SQL: (\w+).*$/s', '(SQL: $1 …)', $text);
        $text = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '<url>', $text);
        $text = preg_replace('/[^\s@<>()\'"]+@[^\s@<>()\'"]+\.[a-z]{2,}/i', '<email>', $text);
        $text = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '<ip>', $text);
        $text = preg_replace('/\'[^\']*\'|"[^"]*"/', '"…"', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
