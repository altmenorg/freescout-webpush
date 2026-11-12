<?php

namespace Modules\WebPush\Http\Controllers;

use App\Subscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WebPush\Services\Diagnostics;
use Modules\WebPush\Services\Health;
use Modules\WebPush\Services\Settings;
use Modules\WebPush\Services\WebPush;

/**
 * Installable app (PWA): manifest, service worker, and Web Push subscriptions of the agents' devices.
 * Notifications are sent by WebPushServiceProvider (hook subscription.process_events, "Mobile" channel).
 */
class WebPushController extends Controller
{
    /** Manifest served by a route (FreeScout's own site.webmanifest has no name, so the site is not installable). */
    public function manifest()
    {
        $base = WebPush::basePath(); // "/", or "/helpdesk/" when FreeScout is installed in a subfolder
        return response()->json([
            'id'               => $base,
            'name'             => Settings::appName(),
            'short_name'       => Settings::appShortName(),
            'lang'             => app()->getLocale(),
            'start_url'        => $base,
            'scope'            => $base,
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#ffffff',
            'theme_color'      => '#ffffff', // white status bar: Android draws dark, readable icons on it
            'icons'            => Settings::appIcons(),
            // long press on the app's icon (Android, Windows, macOS): each one is resolved for the agent by go()
            'shortcuts'        => array_map(function ($target) {
                return [
                    'name' => __('webpush::messages.shortcut_'.$target),
                    'url'  => route('webpush.go', ['target' => $target], false),
                ];
            }, self::SHORTCUTS),
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    const SHORTCUTS = ['mine', 'unassigned', 'new', 'search'];

    /**
     * Where a shortcut of the installed app leads, for the signed-in agent: their "Mine" folder, the "Unassigned"
     * folder, a new ticket, the search. With several mailboxes, the first one that has something in that folder (the
     * first one otherwise); for a new ticket, the first mailbox.
     */
    public function go($target)
    {
        $user = auth()->user();
        if ($target === 'search') {
            return redirect()->route('conversations.search');
        }
        $mailboxes = $user->mailboxesCanView();
        if (!in_array($target, self::SHORTCUTS) || !count($mailboxes)) {
            return redirect('/');
        }
        if ($target === 'new') {
            return redirect()->route('conversations.create', ['mailbox_id' => $mailboxes->first()->id]);
        }
        $first = null;
        foreach ($mailboxes as $mailbox) {
            $query = \App\Folder::where('mailbox_id', $mailbox->id);
            $query = $target === 'mine'
                ? $query->where('type', \App\Folder::TYPE_MINE)->where('user_id', $user->id)
                : $query->where('type', \App\Folder::TYPE_UNASSIGNED);
            $folder = $query->first();
            if (!$folder) {
                continue;
            }
            $first = $first ?: $folder;
            if ((int)$folder->active_count > 0) {
                return $this->folderRedirect($target, $folder);
            }
        }
        return $first ? $this->folderRedirect($target, $first) : redirect('/');
    }

    /**
     * The folder, or its view in the Refresh interface when that module is on: Refresh sends FreeScout's folders back
     * to the last view the agent used, which would make "My tickets" and "Unassigned" land on the same page.
     */
    protected function folderRedirect($target, $folder)
    {
        if (\Route::has('refresh.tickets')) {
            return redirect()->route('refresh.tickets', ['mailbox_id' => $folder->mailbox_id,
                'view' => $target === 'mine' ? 'my-open' : 'unassigned']);
        }
        return redirect()->route('mailboxes.view.folder', ['id' => $folder->mailbox_id, 'folder_id' => $folder->id]);
    }

    /**
     * Button of a notification (service worker, no session): "Assign to me" or "Close" on that ticket, for the agent
     * the token was signed for, with the same rights as in the ticket itself. Answers with the agent's new badge count.
     */
    public function action(Request $request)
    {
        $data = WebPush::readActionToken($request->input('token'));
        $user = $data ? \App\User::find($data['u']) : null;
        $conversation = $data ? \App\Conversation::find($data['c']) : null;
        if (!$user || !$conversation || (method_exists($user, 'isActive') && !$user->isActive())) {
            return response()->json(['status' => 'error'], 403);
        }
        if (!$user->can('update', $conversation)) {
            return response()->json(['status' => 'error'], 403);
        }
        // the agent for this request only (no session): what reacts to the change (history, notifications, other
        // modules) sees who did it, as when it is done from the ticket
        \Auth::setUser($user);
        if ($data['a'] === 'assign') {
            if (!$conversation->mailbox->userHasAccess($user->id)) {
                return response()->json(['status' => 'error'], 403);
            }
            if ((int)$conversation->user_id !== (int)$user->id) {
                $conversation->changeUser($user->id, $user);
            }
        } elseif ($data['a'] === 'close') {
            if ((int)$conversation->status !== \App\Conversation::STATUS_CLOSED) {
                $conversation->changeStatus(\App\Conversation::STATUS_CLOSED, $user);
            }
        } else {
            return response()->json(['status' => 'error'], 400);
        }
        return response()->json(['status' => 'success', 'badge_count' => WebPush::badgeCount($user->id)]);
    }

    /** A subscription the browser renewed by itself (service worker, no session): see WebPush::renew(). */
    public function resubscribe(Request $request)
    {
        $ok = WebPush::renew($request->input('old_endpoint'), $request->input('old_auth'), $request->input('subscription'),
            $request->userAgent());
        return response()->json(['status' => $ok ? 'success' : 'error'], $ok ? 200 : 403);
    }

    /**
     * Service worker, served from the module rather than copied into FreeScout's public folder. The URL has no ".js"
     * extension on purpose: web servers often route such paths to static files only.
     */
    public function serviceWorker()
    {
        return response(file_get_contents(__DIR__.'/../../Resources/js/service-worker.js'), 200, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => WebPush::basePath(),  // allows FreeScout's root as scope although the script is under /webpush/
            'Cache-Control'          => 'no-cache',
        ]);
    }

    public function key()
    {
        return response()->json(['key' => WebPush::publicKey()]);
    }

    public function subscribe(Request $request)
    {
        $user = auth()->user();
        $sub = $request->input('subscription');
        if (!is_array($sub) || !WebPush::subscribe($user->id, $sub, $request->userAgent())) {
            return response()->json(['status' => 'error', 'msg' => __('webpush::messages.invalid_subscription')]);
        }
        // First device of this agent: the "Mobile" column of the profile was never used (it is only enabled by this
        // module), so the agent's "Email" choices are copied; they can be changed in Profile › Notifications.
        if (!Subscription::where('user_id', $user->id)->where('medium', Subscription::MEDIUM_MOBILE)->exists()) {
            $events = Subscription::where('user_id', $user->id)->where('medium', Subscription::MEDIUM_EMAIL)->pluck('event');
            foreach ($events as $event) {
                Subscription::create(['user_id' => $user->id, 'medium' => Subscription::MEDIUM_MOBILE, 'event' => $event]);
            }
        }
        return response()->json(['status' => 'success']);
    }

    /** Removes one of the agent's own devices: by its endpoint (this device) or its id (the device list). */
    public function unsubscribe(Request $request)
    {
        $user_id = auth()->user()->id;
        if ($request->input('device')) {
            WebPush::removeDevice($user_id, (string)$request->input('device'));
        } elseif ($request->input('endpoint')) {
            WebPush::unsubscribe((string)$request->input('endpoint'), $user_id);
        }
        return response()->json(['status' => 'success']);
    }

    /** Number shown on the installed app's icon: the agent's open assigned tickets. */
    public function badge()
    {
        return response()->json(['count' => WebPush::badgeCount(auth()->user()->id)])->header('Cache-Control', 'no-store');
    }

    /** The agent's devices (Profile › Notifications). */
    public function devices()
    {
        return response()->json(['devices' => WebPush::devices(auth()->user()->id)]);
    }

    public function test()
    {
        $n = WebPush::sendToUser(auth()->user()->id, [
            'title' => Settings::appName(),
            'body'  => __('webpush::messages.test_body'),
            'url'   => url('/'),
            'tag'   => 'test',
            'icon'  => Settings::notificationIcon(),
            'badge' => Settings::notificationBadge(),
        ]);
        return response()->json([
            'status' => $n ? 'success' : 'error',
            'sent'   => $n,
            'msg'    => $n ? __('webpush::messages.test_sent', ['count' => $n]) : __('webpush::messages.test_none'),
        ]);
    }

    /** Health check (Services\Health): 200 when healthy, 503 otherwise. Admins only. */
    public function health()
    {
        $report = Health::check();
        return response()->json($report, $report['ok'] ? 200 : 503, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }

    /** Server part of the diagnostics file (Manage › Settings › Web Push); the page adds what the browser sees. Admins only. */
    public function diagnostics()
    {
        return response()->json(Diagnostics::report(), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'no-store');
    }
}
