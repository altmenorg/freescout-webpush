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
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
