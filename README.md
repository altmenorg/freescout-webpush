# Web Push for FreeScout

> Built as part of [Refresh](https://github.com/altmenorg/freescout-refresh), a new interface for FreeScout inspired by Freshdesk. Works with or without it.

Install [FreeScout](https://freescout.net) as an app on phones and desktops, and get **Web Push notifications**, even
when FreeScout is closed. No mobile app to install from a store, no third-party relay: notifications are encrypted end
to end and only the subscribed device can read them.

## Features

- **Installable app (PWA)**: on a phone, *Install app* (Chrome) or *Add to Home Screen* (Safari) opens FreeScout full
  screen, with its own name and icon. Desktop browsers can install it too.
- **Web Push notifications** on phones and desktops, delivered by the browser's own push service (Google, Mozilla,
  Apple) even when the app and the browser are closed.
- **FreeScout's own notification settings**: each agent picks the events in the *Mobile* column of
  **Profile › Notifications** (the column is enabled by the module). The first device of an agent starts with the same
  events as their *Email* column.
- **End-to-end encryption**: pure PHP implementation of VAPID (RFC 8292) and aes128gcm content encryption
  (RFC 8291 / 8188), with OpenSSL. No Composer dependency, no external service.
- One notification per ticket: a new event replaces the previous notification of the same ticket. Tapping it opens the
  ticket.
- **"Notifications on this device"** box in Profile › Notifications: turn on, turn off, send a test.
- In the installed app, a one-time prompt offers to turn notifications on.
- English and French.

![Notifications on this device, in Profile › Notifications](screenshots/profile.png)

![Settings](screenshots/settings.png)

## Requirements

- FreeScout 1.8 or newer, PHP 7.3+ with the `openssl` and `curl` extensions.
- **HTTPS** (browsers require it for installable apps and push notifications).
- iPhone / iPad: iOS 16.4 or newer, and the app must be added to the Home Screen (Apple only allows Web Push there).

## Installation

1. Download the latest release and unzip it into the `Modules` folder of FreeScout: you get `Modules/WebPush`
   (the folder **must** have this name).
2. In FreeScout, **Manage › Modules**: activate **Web Push**.
3. Optional: **Manage › Settings › Web Push** to set the app name and icon.
4. Each agent: open FreeScout on their phone, install the app, then **Profile › Notifications** › *Turn on*, and tick
   the events of the *Mobile* column.

**Coming from Refresh 1.2 or older** (this feature used to be part of it): update Refresh to 1.3 or newer and install
this module. Its settings, server keys and device subscriptions are taken over automatically: subscribed devices keep
receiving notifications without doing anything.

## Settings

**Manage › Settings › Web Push**

| Setting | |
|---|---|
| **App name / Short name** | Name of the installable app, and the one shown under its icon. |
| **App icon** | Square PNG, 512 px recommended. Empty: FreeScout's icons. |
| **Contact e-mail** | Given to the push services as the sender of the notifications. Empty: the first administrator. |

The server keys are created on first use in `storage/app/webpush/`, next to the list of subscribed devices. Keep this
folder when moving servers: new keys would silently cut every existing subscription.

## For module developers

Everything the module adds is prefixed `webpush` (routes, options, CSS classes, storage, translations). On every page
of a logged-in agent, `window.webpush` exposes three promise-returning functions: `current()` (the device's push
subscription, or `null`), `enable()` and `disable()`. The profile box and the prompt use the `webpush-*` classes and can
be restyled.

## Other modules from the Refresh project

This module was built as part of [Refresh](https://github.com/altmenorg/freescout-refresh), a new interface for FreeScout. The modules of the project, all usable on their own:

- **[Refresh](https://github.com/altmenorg/freescout-refresh)**: a new, Freshdesk-inspired interface for FreeScout: views, SLA badges, dashboard, properties panel, phone version.
- **[Cobrowse](https://github.com/altmenorg/freescout-cobrowse)**: co-browse with your customers (Cobrowse.io) from the ticket sidebar, to guide them on your website or app.
- **[Freshdesk Import](https://github.com/altmenorg/freescout-freshdesk-import)**: import your Freshdesk tickets into FreeScout and keep them in sync until you switch over.
- **[Claude Assistant](https://github.com/altmenorg/freescout-claude-assistant)**: draft and improve replies with Claude, from the reply editor.

## License

[AGPL-3.0](LICENSE), like FreeScout.
