<?php

namespace Modules\WebPush\Services;

/**
 * Quiet hours of an agent: no notification on their devices during a time range, every day, and optionally all weekend,
 * in the agent's own time zone. Optionally, urgent tickets still notify (priority "Urgent" of the Refresh module's
 * properties panel, conversations.meta fd_priority = 4). Stored per agent in FreeScout's options
 * (webpush.quiet.<user id>). The "Send a test" button ignores them.
 */
class QuietHours
{
    const DEFAULTS = ['enabled' => false, 'from' => '20:00', 'to' => '08:00', 'weekend' => false, 'urgent' => true];

    public static function get($user_id)
    {
        $saved = json_decode((string)\Option::get('webpush.quiet.'.(int)$user_id, ''), true);
        return array_merge(self::DEFAULTS, is_array($saved) ? array_intersect_key($saved, self::DEFAULTS) : []);
    }

    public static function set($user_id, array $data)
    {
        $time = function ($v, $default) {
            return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $default;
        };
        $value = [
            'enabled' => !empty($data['enabled']),
            'from'    => $time($data['from'] ?? null, self::DEFAULTS['from']),
            'to'      => $time($data['to'] ?? null, self::DEFAULTS['to']),
            'weekend' => !empty($data['weekend']),
            'urgent'  => !empty($data['urgent']),
        ];
        $key = 'webpush.quiet.'.(int)$user_id;
        \Option::set($key, json_encode($value));
        // Option::set() leaves Option's cache as it was: a get() later in the same request would read the old value
        unset(\Option::$cache[$key]);
        return $value;
    }

    /** Whether the agent's devices stay silent for this ticket now. */
    public static function silences($user, $conversation = null, $now = null)
    {
        $q = self::get($user->id);
        if (!$q['enabled']) {
            return false;
        }
        if ($q['urgent'] && $conversation && self::isUrgent($conversation)) {
            return false;
        }
        try {
            $tz = new \DateTimeZone($user->timezone ?: config('app.timezone') ?: 'UTC');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $now = $now ? (clone $now)->setTimezone($tz) : new \DateTime('now', $tz);
        if ($q['weekend'] && (int)$now->format('N') >= 6) {
            return true;
        }
        $t = $now->format('H:i');
        if ($q['from'] === $q['to']) {
            return false;
        }
        // 20:00 → 08:00 crosses midnight; 12:00 → 14:00 does not
        return $q['from'] < $q['to']
            ? ($t >= $q['from'] && $t < $q['to'])
            : ($t >= $q['from'] || $t < $q['to']);
    }

    /** Priority "Urgent" set in the Refresh module's properties panel. */
    public static function isUrgent($conversation)
    {
        $meta = $conversation->meta;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        return is_array($meta) && (int)($meta['fd_priority'] ?? 0) >= 4;
    }
}
