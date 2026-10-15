/* Web Push: diagnostics file for bug reports (Manage › Settings › Web Push, #webpush-diag; does nothing elsewhere).
   "Create the diagnostics file" joins the server part (/webpush/diagnostics, Services\Diagnostics) and what this
   browser sees: notification permission, service worker, this device's subscription (its push service only, never its
   address), app badge support. Shown, then downloaded or copied. ES5. */
(function () {
    if (!window.jQuery) {
        return;
    }
    var $ = window.jQuery;

    function path(url) {
        try { return new URL(url, location.href).pathname; } catch (e) { return ''; }
    }
    function service(endpoint) {
        var host = '';
        try { host = new URL(endpoint).hostname; } catch (e) {}
        if (/googleapis\.com$/.test(host)) { return 'google'; }
        if (/mozilla\.com$/.test(host)) { return 'mozilla'; }
        if (/apple\.com$/.test(host)) { return 'apple'; }
        if (/windows\.com$|notify\.windows/.test(host)) { return 'microsoft'; }
        return host ? 'other' : null;
    }

    function browser(done) {
        var mm = function (q) { return window.matchMedia ? window.matchMedia(q).matches : null; };
        var info = {
            user_agent: navigator.userAgent,
            language: navigator.language,
            installed_app: mm('(display-mode: standalone)'),
            touch: ('ontouchstart' in window) || (navigator.maxTouchPoints || 0) > 0,
            notification_permission: window.Notification ? Notification.permission : 'unsupported',
            service_worker: 'serviceWorker' in navigator,
            push_manager: !!window.PushManager,
            app_badge: !!navigator.setAppBadge,
            secure_context: !!window.isSecureContext
        };
        if (!info.service_worker) {
            done(info);
            return;
        }
        navigator.serviceWorker.getRegistration().then(function (reg) {
            info.registration = reg ? {
                scope: path(reg.scope),
                script: reg.active ? path(reg.active.scriptURL) : null
            } : null;
            if (!reg || !reg.pushManager) {
                done(info);
                return;
            }
            return reg.pushManager.getSubscription().then(function (sub) {
                info.subscription = sub ? { push_service: service(sub.endpoint) } : null;
                done(info);
            });
        }).catch(function (e) {
            info.registration_error = String(e && e.message || e).slice(0, 200);
            done(info);
        });
    }

    $(function () {
        var box = $('#webpush-diag');
        if (!box.length) {
            return;
        }
        var pre = box.find('.webpush-diag-pre');
        var text = '';

        box.on('click', '.webpush-diag-make', function () {
            var btn = $(this).prop('disabled', true);
            var server = null;
            var client = null;
            var finish = function () {
                if (!server || !client) {
                    return;
                }
                server.browser = client;
                text = JSON.stringify(server, null, 2);
                pre.text(text);
                box.find('.webpush-diag-out').show();
                btn.prop('disabled', false);
            };
            $.ajax({ url: box.data('url'), dataType: 'json', global: false, timeout: 60000 })
                .done(function (r) { server = r || {}; })
                .fail(function (xhr, status) { server = { server_part: { status: xhr.status, error: String(status) } }; })
                .always(finish);
            browser(function (info) { client = info; finish(); });
        });

        box.on('click', '.webpush-diag-download', function () {
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
            a.download = 'webpush-diagnostics-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + '.json';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        });

        box.on('click', '.webpush-diag-copy', function () {
            var btn = $(this);
            var label = btn.text();
            var ok = function () { btn.text(btn.data('copied')); setTimeout(function () { btn.text(label); }, 2000); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok);
            } else {
                var ta = $('<textarea></textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                ta.remove();
                ok();
            }
        });
    });
})();
