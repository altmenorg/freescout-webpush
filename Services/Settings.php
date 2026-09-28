<?php

namespace Modules\WebPush\Services;

/**
 * Web Push settings (Manage > Settings > Web Push), stored as FreeScout options "webpush.*", with neutral defaults.
 */
class Settings
{
    public static function get($key, $default = null)
    {
        $v = \Option::get('webpush.'.$key, null);
        return ($v === null || $v === '') ? $default : $v;
    }

    /** Name of the installable app. */
    public static function appName()
    {
        return (string)self::get('app_name', config('app.name') ?: 'FreeScout');
    }

    /** Name shown under the icon on the home screen. */
    public static function appShortName()
    {
        return mb_substr((string)self::get('app_short_name', self::appName()), 0, 20);
    }

    /**
     * Icons of the installable app: a square PNG (512 px recommended) from the settings, else FreeScout's own icons.
     * @return array manifest "icons" entries
     */
    public static function appIcons()
    {
        $custom = self::get('app_icon_url');
        if ($custom) {
            return [
                ['src' => $custom, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $custom, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];
        }
        return [
            ['src' => asset('android-chrome-192x192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('android-chrome-256x256.png'), 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'any'],
        ];
    }

    /** Icon shown in the notifications. */
    public static function notificationIcon()
    {
        return self::get('app_icon_url') ?: asset('android-chrome-192x192.png');
    }

    /** Contact given to the push services (Google, Mozilla, Apple) in the VAPID claims: setting, else the first admin. */
    public static function pushContact()
    {
        $email = self::get('push_contact');
        if (!$email) {
            $email = \App\User::where('role', \App\User::ROLE_ADMIN)->where('status', \App\User::STATUS_ACTIVE)->orderBy('id')->value('email');
        }
        return 'mailto:'.($email ?: 'admin@localhost');
    }

    /**
     * This feature used to be part of the Refresh module (1.2 and older). Its settings, server keys and device
     * subscriptions are taken over once (copied, not moved), so subscribed devices keep receiving notifications.
     */
    public static function migrateFromRefresh()
    {
        try {
            if (\Option::get('webpush.migrated', '')) {
                return;
            }
            foreach (['app_name', 'app_short_name', 'app_icon_url', 'push_contact'] as $key) {
                $old = \Option::get('refresh.'.$key, '');
                if ($old !== '' && $old !== null && \Option::get('webpush.'.$key, '') === '') {
                    \Option::set('webpush.'.$key, $old);
                }
            }
            $old_dir = storage_path('app/refresh');
            foreach (['vapid.json', 'push_subscriptions.json'] as $file) {
                if (is_file($old_dir.'/'.$file) && !is_file(WebPush::dir().'/'.$file)) {
                    copy($old_dir.'/'.$file, WebPush::dir().'/'.$file);
                    @chmod(WebPush::dir().'/'.$file, 0600);
                }
            }
            \Option::set('webpush.migrated', 1);
        } catch (\Throwable $e) {
            // not fatal: new keys are created on first use
            \Log::error('[WebPush] migration from Refresh: '.$e->getMessage());
        }
    }
}
