/* Web Push for FreeScout: service worker of the installable app (PWA).
   Served by the module at /webpush/service-worker with "Service-Worker-Allowed: /", so it covers the whole site
   without copying a file into FreeScout's public folder. It caches nothing: it receives Web Push notifications, even
   when the app and the browser are closed (the push service wakes it up), runs their buttons, and renews the
   subscription when the browser replaces it. */

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

/* "Share" menu of the phone (share_target of the manifest): the shared files and text arrive as a POST, which has no
   CSRF token and must not leave the phone before the agent chooses where they go. They are kept in this browser's
   cache ("webpush-share") and the share page (GET) is opened; it sends them to a ticket, or to the new-ticket form.
   Every other request goes to the network as before: nothing else is cached. */
var SHARE_CACHE = 'webpush-share';

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'POST' || !/\/webpush\/share$/.test(new URL(req.url).pathname)) {
        return;
    }
    event.respondWith(req.formData().then(function (form) {
        return caches.delete(SHARE_CACHE).then(function () {
            return caches.open(SHARE_CACHE);
        }).then(function (cache) {
            var files = form.getAll('files').filter(function (f) { return f && typeof f !== 'string' && f.size; });
            var meta = {
                title: String(form.get('title') || ''),
                text: String(form.get('text') || ''),
                url: String(form.get('url') || ''),
                files: files.length,
                at: Date.now()
            };
            var puts = files.map(function (file, i) {
                return cache.put('/webpush-share/file/' + i, new Response(file, { headers: {
                    'Content-Type': file.type || 'application/octet-stream',
                    'X-File-Name': encodeURIComponent(file.name || ('file-' + (i + 1)))
                } }));
            });
            puts.push(cache.put('/webpush-share/meta', new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } })));
            return Promise.all(puts);
        });
    }).then(function () {
        return Response.redirect(req.url, 303);
    }, function () {
        return Response.redirect(req.url, 303);
    }));
});

/* The module's URLs, next to this worker: /webpush/service-worker -> /webpush/action, /webpush/resubscribe
   (FreeScout may live in a subfolder). */
function moduleUrl(name) {
    return self.location.href.replace(/\/(webpush|refresh)\/service-worker.*$/, '/webpush/' + name);
}

function setBadge(count) {
    if (typeof count === 'number' && self.navigator && self.navigator.setAppBadge) {
        (count > 0 ? self.navigator.setAppBadge(count) : self.navigator.clearAppBadge()).catch(function () {});
    }
}

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    // number on the installed app's icon: the agent's open assigned tickets, sent with each notification
    setBadge(data.badge_count);
    // buttons: "Assign to me" (with a token signed for this agent, this ticket and this action) and "Reply"
    var actions = (data.actions || []).map(function (a) { return { action: a.action, title: a.title }; });
    event.waitUntil(self.registration.showNotification(data.title || 'FreeScout', {
        body: data.body || '',
        icon: data.icon || undefined,
        badge: data.badge || undefined,
        tag: data.tag || undefined,       // one notification per ticket: the next one replaces it
        renotify: !!data.tag,
        image: data.image || undefined,   // first picture of the message, shown large (Android, Windows)
        timestamp: data.timestamp || undefined,
        actions: actions,
        data: { url: data.url || self.registration.scope, actions: data.actions || [], failed: data.failed || '' }
    }));
});

function openUrl(url) {
    return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
                return c.focus().then(function (w) { return w.navigate ? w.navigate(url) : w; });
            }
        }
        return self.clients.openWindow(url);
    });
}

self.addEventListener('notificationclick', function (event) {
    var n = event.notification;
    var data = n.data || {};
    var url = data.url || self.registration.scope;
    n.close();
    var button = null;
    (data.actions || []).forEach(function (a) { if (a.action === event.action) { button = a; } });
    if (!button) {
        event.waitUntil(openUrl(url));
        return;
    }
    if (button.url) {
        event.waitUntil(openUrl(button.url));   // "Reply": the ticket, with the reply editor open
        return;
    }
    // A button: done in the background, the app is not opened. When it fails (rights changed, token too old, no
    // network), a notification says so and opens the ticket.
    event.waitUntil(fetch(moduleUrl('action'), {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ token: button.token })
    }).then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
    }).then(function (r) {
        setBadge(r.badge_count);
    }).catch(function () {
        return self.registration.showNotification(n.title, {
            body: data.failed || n.body,
            icon: n.icon || undefined,
            badge: n.badge || undefined,
            tag: n.tag || undefined,
            data: { url: url }
        });
    }));
});

/* The browser replaced the push subscription (Firefox does it now and then, Chrome rarely): without this, the device
   would silently stop receiving notifications until the agent turned them on again. The new subscription is sent with
   the old one's secret, which proves it comes from this browser (the worker has no session). When the browser gives no
   old subscription, the page re-sends the current one the next time FreeScout is opened. */
self.addEventListener('pushsubscriptionchange', function (event) {
    var old = event.oldSubscription;
    var oldJson = old && old.toJSON ? old.toJSON() : null;
    var key = old && old.options ? old.options.applicationServerKey : null;
    event.waitUntil((event.newSubscription
        ? Promise.resolve(event.newSubscription)
        : (key ? self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }) : Promise.reject())
    ).then(function (sub) {
        if (!oldJson || !oldJson.keys) { return; }
        return fetch(moduleUrl('resubscribe'), {
            method: 'POST',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ old_endpoint: oldJson.endpoint, old_auth: oldJson.keys.auth, subscription: sub.toJSON() })
        });
    }).catch(function () {}));
});
