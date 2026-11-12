<?php

namespace Modules\WebPush\Services;

use App\Conversation;
use App\Thread;

/**
 * SLA alerts: a notification shortly before an open ticket misses its first-response or resolution target.
 *
 * The targets come from another module through the fs-interop contract (filter fsinterop.sla.targets, schema
 * sla-targets 1.0: first_response_hours, resolution_hours); the Refresh module answers it from its settings. Without
 * an answer, there are no targets and no alerts. Run every five minutes by FreeScout's scheduler (webpush:sla).
 *
 * - First response: due created_at + first_response_hours, while no agent has replied.
 * - Resolution: due created_at + resolution_hours, while the ticket is active.
 * Each is notified once, when it comes within "SLA alerts" minutes (Manage › Settings › Web Push, 30 by default, 0
 * turns them off), to the assignee, or to every agent of the mailbox with a device when nobody is assigned, outside
 * their quiet hours (urgent tickets excepted when they chose so). A target already missed is not notified.
 */
class Sla
{
    const STATE_FILE = 'sla_alerts.json';

    /** ['first_response_hours' => int, 'resolution_hours' => int], or null when no module gives targets. */
    public static function targets()
    {
        try {
            $t = \Eventy::filter('fsinterop.sla.targets', null, ['schema' => '1.0', 'module' => 'webpush']);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($t) || (int)($t['first_response_hours'] ?? 0) < 1 || (int)($t['resolution_hours'] ?? 0) < 1) {
            return null;
        }
        return ['first_response_hours' => (int)$t['first_response_hours'], 'resolution_hours' => (int)$t['resolution_hours']];
    }

    /** Minutes before a target when the alert is sent (0: no alerts). */
    public static function leadMinutes()
    {
        return max(0, min(1440, (int)Settings::get('sla_minutes', 30)));
    }

    /** Sends the alerts that are due; returns how many notifications were accepted by the push services. */
    public static function run(\Carbon\Carbon $now = null)
    {
        $targets = self::targets();
        $lead = self::leadMinutes();
        if (!$targets || !$lead || !WebPush::subscriptions()) {
            return 0;
        }
        $now = $now ?: \Carbon\Carbon::now();
        $sent = self::load();
        $items = [];
        foreach (['first' => $targets['first_response_hours'], 'resolution' => $targets['resolution_hours']] as $kind => $hours) {
            // due between now and now + lead: created between now - hours and now - hours + lead
            $query = Conversation::where('state', Conversation::STATE_PUBLISHED)
                ->where('status', Conversation::STATUS_ACTIVE)
                ->where('created_at', '>', $now->copy()->subHours($hours))
                ->where('created_at', '<=', $now->copy()->subHours($hours)->addMinutes($lead));
            if ($kind === 'first') {
                $query->whereNotExists(function ($q) {
                    $prefix = \DB::getTablePrefix();
                    $q->select(\DB::raw(1))->from('threads')
                        ->whereRaw($prefix.'threads.conversation_id = '.$prefix.'conversations.id')
                        ->where('threads.type', Thread::TYPE_MESSAGE)
                        ->where('threads.state', Thread::STATE_PUBLISHED)
                        ->whereNotNull('threads.created_by_user_id');
                });
            }
            foreach ($query->limit(200)->get() as $conv) {
                $key = $conv->id.':'.$kind;
                if (isset($sent[$key])) {
                    continue;
                }
                $sent[$key] = $now->getTimestamp();
                $due = $conv->created_at->copy()->addHours($hours);
                $minutes = max(1, (int)ceil(($due->getTimestamp() - $now->getTimestamp()) / 60));
                foreach (self::recipients($conv) as $user) {
                    if (QuietHours::silences($user, $conv)) {
                        continue;
                    }
                    $items[] = [$user->id, self::payload($conv, $kind, $minutes, $user)];
                }
            }
        }
        self::save($sent, $now);
        return $items ? WebPush::sendToUsers($items) : 0;
    }

    /** The assignee; or, when nobody is assigned, every agent of the mailbox who has a device. */
    public static function recipients($conv)
    {
        if ($conv->user_id) {
            $user = \App\User::find($conv->user_id);
            return $user ? [$user] : [];
        }
        $with_devices = array_unique(array_map(function ($s) {
            return (int)$s['user_id'];
        }, WebPush::subscriptions()));
        $users = [];
        foreach (\App\User::whereIn('id', $with_devices)->get() as $user) {
            if ($conv->mailbox && $conv->mailbox->userHasAccess($user->id)) {
                $users[] = $user;
            }
        }
        return $users;
    }

    public static function payload($conv, $kind, $minutes, $user)
    {
        $body = __('webpush::messages.sla_'.$kind, ['minutes' => $minutes]);
        $payload = [
            'title' => Settings::hideContent() ? '#'.$conv->number : '#'.$conv->number.' '.$conv->getSubject(),
            'body'  => $body,
            'url'   => $conv->url(),
            'tag'   => 'sla-'.$conv->id.'-'.$kind,
            'icon'  => Settings::notificationIcon(),
            'badge' => Settings::notificationBadge(),
        ];
        return $payload + \Modules\WebPush\Providers\WebPushServiceProvider::buttons($conv, $user);
    }

    protected static function load()
    {
        $path = WebPush::dir().'/'.self::STATE_FILE;
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        return is_array($data) ? $data : [];
    }

    /** Kept a week: long enough for any target, short enough to stay small. */
    protected static function save(array $sent, $now)
    {
        $limit = $now->getTimestamp() - 7 * 86400;
        $sent = array_filter($sent, function ($ts) use ($limit) {
            return (int)$ts >= $limit;
        });
        $path = WebPush::dir().'/'.self::STATE_FILE;
        file_put_contents($path.'.tmp', json_encode($sent));
        @chmod($path.'.tmp', 0600);
        rename($path.'.tmp', $path);
    }
}
