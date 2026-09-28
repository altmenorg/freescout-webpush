<?php

return [
    // settings page
    'section'              => 'Web Push',
    'settings_title'       => 'Installable app and push notifications',
    'settings_intro'       => 'On a phone, open FreeScout in Chrome (or Safari) and choose "Install app" / "Add to Home Screen": FreeScout opens full screen like an app. Each agent then turns on notifications in Profile › Notifications (Mobile column). On a computer, notifications can be turned on from the same page.',
    'app_name'             => 'App name',
    'app_short_name'       => 'Short name',
    'app_short_name_help'  => 'Shown under the icon on the home screen (12 characters or less displays best).',
    'app_icon'             => 'App icon',
    'app_icon_help'        => 'Optional. Square PNG, 512 × 512 px, with a margin around the drawing. Empty: FreeScout\'s icon. Phones pick up a change within a day.',
    'contact'              => 'Contact e-mail',
    'contact_help'         => 'Given to the push services (Google, Apple, Mozilla) as the sender of the notifications. Empty: the first administrator.',
    'save'                 => 'Save',

    // profile box and "install" prompt
    'this_device'          => 'Notifications on this device',
    'turn_on'              => 'Turn on',
    'turn_off'             => 'Turn off',
    'send_test'            => 'Send a test',
    'profile_help'         => 'Notified events are the ones checked in the Mobile column below. On a phone, install the app first (Chrome\'s ⋮ menu → Install app, or Safari\'s Share → Add to Home Screen).',
    'blocked'              => 'Notifications are blocked for this site: allow them in the browser settings (padlock in the address bar).',
    'state_on'             => 'Turned on on this device.',
    'state_off'            => 'Turned off on this device.',
    'turned_on'            => 'Notifications turned on',
    'turn_on_failed'       => 'Could not turn on notifications',
    'ask'                  => 'Get notifications on this device?',
    'later'                => 'Later',

    // notifications
    'push_body'            => ':name: :text',
    'new_activity'         => 'New activity',
    'test_body'            => 'Test notification: everything works.',
    'test_sent'            => ':count device(s) notified',
    'test_none'            => 'No subscribed device accepted the notification',
    'invalid_subscription' => 'Invalid subscription',
];
