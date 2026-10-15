<?php

namespace Modules\WebPush\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WebPush\Services\Settings;
use Modules\WebPush\Services\WebPush;

/**
 * Installable app (PWA) and Web Push notifications for FreeScout.
 *
 * Hooks into FreeScout's own "Mobile" notification channel: each agent chooses their events in
 * Profile › Notifications (Mobile column, enabled by this module), FreeScout works out who to notify,
 * this module sends the notification to the agent's subscribed devices (Services/WebPush).
 * Everything the module adds is prefixed "webpush" (routes, options, CSS classes, JS, storage, translations).
 */
class WebPushServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'webpush');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'webpush');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        Settings::migrateFromRefresh();
        $this->registerNotifications();
        $this->registerSettings();
        $this->registerAssets();
    }

    /** "Mobile" column of Profile › Notifications, and sending of the notifications FreeScout decides on. */
    protected function registerNotifications()
    {
        \Eventy::addFilter('notifications.mobile_available', function () {
            return true;
        });
        \Eventy::addAction('subscription.process_events', function ($notify) {
            if (empty($notify[\App\Subscription::MEDIUM_MOBILE])) {
                return;
            }
            // every device of every agent of this event, sent in parallel (WebPush::sendToUsers)
            $items = [];
            foreach ($notify[\App\Subscription::MEDIUM_MOBILE] as $info) {
                try {
                    $conv = $info['conversation'];
                    $thread = \App\Subscription::chooseThread($info['threads']);
                    $who = '';
                    $by = $thread ? $thread->getCreatedBy() : null;
                    if ($by) {
                        $who = ($by instanceof \App\Customer) ? $by->getFullName(true) : $by->getFullName();
                    }
                    $text = $thread ? \Helper::textPreview($thread->body, 140) : '';
                    if ($who && $text) {
                        $body = __('webpush::messages.push_body', ['name' => $who, 'text' => $text]);
                    } else {
                        $body = trim($who.$text) ?: __('webpush::messages.new_activity');
                    }
                    $payload = [
                        'title' => '#'.$conv->number.' '.$conv->getSubject(),
                        'body'  => $body,
                        'url'   => $conv->url(),
                        'tag'   => 'conv-'.$conv->id,
                        'icon'  => Settings::notificationIcon(),
                        'badge' => Settings::notificationBadge(),
                    ];
                    // "Hide the content" (settings): only the ticket number, readable on a locked screen
                    if (Settings::hideContent()) {
                        $payload['title'] = '#'.$conv->number;
                        $payload['body'] = __('webpush::messages.new_activity');
                    }
                    foreach ($info['users'] as $user) {
                        $items[] = [$user->id, $payload];
                    }
                } catch (\Throwable $e) {
                    \Log::error('[WebPush] '.$e->getMessage());
                }
            }
            if ($items) {
                try {
                    WebPush::sendToUsers($items);
                } catch (\Throwable $e) {
                    \Log::error('[WebPush] '.$e->getMessage());
                }
            }
        });
    }

    /** Manage › Settings › Web Push: name and icon of the app, contact given to the push services. */
    protected function registerSettings()
    {
        $keys = ['app_name', 'app_short_name', 'app_icon_url', 'badge_url', 'push_contact', 'hide_content'];
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['webpush'] = ['title' => __('webpush::messages.section'), 'icon' => 'phone', 'order' => 160];
            return $sections;
        }, 40);
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) use ($keys) {
            if ($section != 'webpush') {
                return $settings;
            }
            foreach ($keys as $k) {
                $settings['webpush.'.$k] = \Option::get('webpush.'.$k, '');
            }
            return $settings;
        }, 20, 2);
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != 'webpush') {
                return $params;
            }
            return ['template_vars' => ['default_contact' => preg_replace('/^mailto:/', '', Settings::pushContact())]];
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == 'webpush' ? 'webpush::settings' : $view;
        }, 20, 2);
    }

    /** Stylesheet (bundled by FreeScout) and the page script (CSP: never inline in the views, see the javascript hook). */
    protected function registerAssets()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath('webpush').'/css/webpush.css';
            return $styles;
        });
        // Diagnostics file (Manage › Settings › Web Push): only acts on that page
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath('webpush').'/js/diagnostics.js';
            return $scripts;
        });

        \Eventy::addAction('javascript', function () {
            if (!auth()->check()) {
                return;
            }
            $own_notifications = \Route::is('users.notifications') && (int)request()->route('id') === (int)auth()->user()->id;
            $t = function ($key) {
                return json_encode(__('webpush::messages.'.$key), JSON_UNESCAPED_UNICODE);
            };
            ?>
            // ------------------------------------------------------------ Web Push module: installable app + notifications
            // Module manifest (the core's has no name: site not installable), white status bar, service worker
            // (route webpush.sw, Resources/js/service-worker.js) which receives notifications even with the app closed.
            (function () {
                var mf = document.querySelector('link[rel="manifest"]');
                if (mf) { mf.setAttribute('href', <?php echo json_encode(route('webpush.manifest')); ?>); }
                if (!document.querySelector('meta[name="theme-color"]')) { $('head').append('<meta name="theme-color" content="#ffffff">'); }
                if (!('serviceWorker' in navigator) || !window.PushManager || !window.Notification) { return; }
                var csrf = function () { return $('meta[name="csrf-token"]').attr('content'); };
                var keyBytes = function (b64) {
                    var pad = '='.repeat((4 - b64.length % 4) % 4);
                    var raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                    var out = new Uint8Array(raw.length);
                    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
                    return out;
                };
                // scope: FreeScout's root ("/" as before, so an existing registration and its push subscription are
                // kept; "/helpdesk/" when FreeScout is installed in a subfolder)
                var regP = navigator.serviceWorker.register(<?php echo json_encode(route('webpush.sw')); ?>, { scope: <?php echo json_encode(WebPush::basePath()); ?> });
                var current = function () {
                    return regP.then(function (reg) { return reg.pushManager.getSubscription(); });
                };
                var enable = function () {
                    return Notification.requestPermission().then(function (perm) {
                        if (perm !== 'granted') { throw new Error('denied'); }
                        return $.getJSON(<?php echo json_encode(route('webpush.key')); ?>);
                    }).then(function (r) {
                        return regP.then(function (reg) {
                            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(r.key) });
                        });
                    }).then(function (sub) {
                        return $.ajax({ url: <?php echo json_encode(route('webpush.subscribe')); ?>, type: 'POST', contentType: 'application/json',
                            headers: { 'X-CSRF-TOKEN': csrf() }, data: JSON.stringify({ subscription: sub.toJSON() }) });
                    });
                };
                var disable = function () {
                    return current().then(function (sub) {
                        if (!sub) { return; }
                        var ep = sub.endpoint;
                        return sub.unsubscribe().then(function () {
                            return $.post(<?php echo json_encode(route('webpush.unsubscribe')); ?>, { _token: csrf(), endpoint: ep });
                        });
                    });
                };
                // for other modules (a mobile interface, for instance): current() / enable() / disable() promises
                window.webpush = { current: current, enable: enable, disable: disable };
                var toast = function (type, msg) { if (window.showFloatingAlert) { showFloatingAlert(type, msg); } };

                <?php if ($own_notifications) { ?>
                // Profile → Notifications (one's own): "Notifications on this device" box above the events table
                var form = $('form.user-subscriptions').first();
                if (form.length) {
                    var box = $('<div class="webpush-box"><div class="webpush-head"><i class="glyphicon glyphicon-bell"></i> <strong></strong></div>'
                        + '<p class="webpush-state"></p><div class="webpush-btns">'
                        + '<button type="button" class="btn btn-primary webpush-on"></button> '
                        + '<button type="button" class="btn btn-default webpush-off"></button> '
                        + '<button type="button" class="btn btn-default webpush-test"></button></div>'
                        + '<p class="webpush-help"></p></div>');
                    box.find('.webpush-head strong').text(<?php echo $t('this_device'); ?>);
                    box.find('.webpush-on').text(<?php echo $t('turn_on'); ?>);
                    box.find('.webpush-off').text(<?php echo $t('turn_off'); ?>);
                    box.find('.webpush-test').text(<?php echo $t('send_test'); ?>);
                    box.find('.webpush-help').text(<?php echo $t('profile_help'); ?>);
                    form.before(box);
                    // "Your devices": every device of the agent that receives notifications, the others removable
                    // from here (a lost or replaced phone keeps its subscription until it is removed)
                    var devices = $('<div class="webpush-devices"><div class="webpush-devices-title"></div><ul></ul></div>');
                    devices.find('.webpush-devices-title').text(<?php echo $t('your_devices'); ?>);
                    box.find('.webpush-help').before(devices);
                    var lang = document.documentElement.lang || undefined;
                    var renderDevices = function (sub) {
                        $.getJSON(<?php echo json_encode(route('webpush.devices')); ?>).done(function (r) {
                            var ul = devices.find('ul').empty();
                            var list = (r && r.devices) || [];
                            if (!list.length) {
                                ul.append($('<li class="webpush-dev-none"></li>').text(<?php echo $t('no_devices'); ?>));
                                return;
                            }
                            $.each(list, function (i, d) {
                                var li = $('<li></li>').append($('<span class="webpush-dev-name"></span>').text(d.label));
                                if (d.created) {
                                    var day = new Date(d.created).toLocaleDateString(lang, { day: 'numeric', month: 'short', year: 'numeric' });
                                    li.append($('<span class="webpush-dev-date"></span>').text(<?php echo $t('added_on'); ?>.replace(':date', day)));
                                }
                                if (sub && d.endpoint === sub.endpoint) {
                                    li.addClass('is-this').append($('<span class="webpush-dev-this"></span>').text(<?php echo $t('this_device_tag'); ?>));
                                } else {
                                    li.append($('<button type="button" class="btn btn-link webpush-dev-remove"></button>').attr('data-device', d.id).text(<?php echo $t('remove'); ?>));
                                }
                                ul.append(li);
                            });
                        });
                    };
                    var refresh = function () {
                        current().then(function (sub) {
                            var denied = Notification.permission === 'denied';
                            box.find('.webpush-state').text(denied ? <?php echo $t('blocked'); ?> : (sub ? <?php echo $t('state_on'); ?> : <?php echo $t('state_off'); ?>));
                            box.toggleClass('is-on', !!sub);
                            box.find('.webpush-on').toggle(!sub && !denied);
                            box.find('.webpush-off, .webpush-test').toggle(!!sub);
                            renderDevices(sub);
                        });
                    };
                    refresh();
                    box.on('click', '.webpush-on', function () {
                        // reload: the Mobile column of the table below has just been filled in (first device)
                        enable().then(function () { toast('success', <?php echo $t('turned_on'); ?>); setTimeout(function () { window.location.reload(); }, 800); },
                            function () { toast('error', <?php echo $t('turn_on_failed'); ?>); refresh(); });
                    });
                    box.on('click', '.webpush-off', function () { disable().then(refresh, refresh); });
                    box.on('click', '.webpush-dev-remove', function () {
                        $(this).prop('disabled', true);
                        $.post(<?php echo json_encode(route('webpush.unsubscribe')); ?>, { _token: csrf(), device: $(this).attr('data-device') })
                            .done(function () { toast('success', <?php echo $t('device_removed'); ?>); })
                            .always(refresh);
                    });
                    box.on('click', '.webpush-test', function () {
                        $.post(<?php echo json_encode(route('webpush.test')); ?>, { _token: csrf() }, function (r) {
                            toast(r.status === 'success' ? 'success' : 'error', r.msg);
                        }, 'json');
                    });
                }
                <?php } ?>

                // App installed (full screen) without notifications: offer once to turn them on
                var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;

                // Installed app: its icon shows the agent's open assigned tickets (also updated by each notification)
                if (standalone && navigator.setAppBadge) {
                    var badge = function () {
                        $.getJSON(<?php echo json_encode(route('webpush.badge')); ?>).done(function (r) {
                            var n = parseInt(r && r.count, 10) || 0;
                            (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(function () {});
                        });
                    };
                    badge();
                    document.addEventListener('visibilitychange', function () { if (!document.hidden) { badge(); } });
                }
                var asked = false;
                try { asked = window.localStorage.getItem('webpush_asked') === '1' || window.localStorage.getItem('rf_push_asked') === '1'; } catch (e) {}
                if (standalone && !asked && Notification.permission === 'default') {
                    current().then(function (sub) {
                        if (sub) { return; }
                        var bar = $('<div class="webpush-ask"><span></span><button type="button" class="webpush-ask-no"></button><button type="button" class="webpush-ask-yes"></button></div>');
                        bar.find('span').text(<?php echo $t('ask'); ?>);
                        bar.find('.webpush-ask-no').text(<?php echo $t('later'); ?>);
                        bar.find('.webpush-ask-yes').text(<?php echo $t('turn_on'); ?>);
                        var done = function () { try { window.localStorage.setItem('webpush_asked', '1'); } catch (e) {} bar.remove(); };
                        bar.on('click', '.webpush-ask-no', done);
                        bar.on('click', '.webpush-ask-yes', function () {
                            enable().then(function () { toast('success', <?php echo $t('turned_on'); ?>); }, function () {});
                            done();
                        });
                        $('body').append(bar);
                    });
                }
            })();
            <?php
        });
    }

    public function register()
    {
        // php artisan webpush:health (Services\Health)
        $this->commands([\Modules\WebPush\Console\Health::class]);
    }

    public function provides()
    {
        return [];
    }
}
