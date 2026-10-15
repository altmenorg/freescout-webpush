<?php

return [
    // settings page
    'section'              => 'Web Push',
    'settings_title'       => 'Appli installable et notifications push',
    'settings_intro'       => 'Sur un téléphone, ouvrez FreeScout dans Chrome (ou Safari) et choisissez « Installer l\'application » / « Sur l\'écran d\'accueil » : FreeScout s\'ouvre en plein écran comme une appli. Chaque agent active ensuite les notifications dans Profil › Notifications (colonne Mobile). Sur un ordinateur, les notifications s\'activent depuis la même page.',
    'app_name'             => 'Nom de l\'appli',
    'app_short_name'       => 'Nom court',
    'app_short_name_help'  => 'Affiché sous l\'icône sur l\'écran d\'accueil (12 caractères au plus pour un affichage optimal).',
    'app_icon'             => 'Icône de l\'appli',
    'app_icon_help'        => 'Facultatif. PNG carré, 512 × 512 px, avec une marge autour du dessin. Vide : l\'icône de FreeScout. Les téléphones prennent en compte un changement sous 24 h.',
    'badge'                => 'Icône de la barre d\'état',
    'badge_help'           => 'Facultatif. Petite icône de la barre d\'état d\'Android : forme blanche sur fond transparent, PNG, 96 × 96 px (seule la forme compte). Vide : le logo de FreeScout.',
    'hide_content'         => 'Masquer le contenu',
    'hide_content_help'    => 'Les notifications n\'affichent que le numéro du ticket (« #1234 — Nouvelle activité ») : ni sujet, ni nom, ni message, lisibles sur un écran verrouillé.',
    'contact'              => 'Contact e-mail',
    'contact_help'         => 'Transmis aux services de notification (Google, Apple, Mozilla) comme expéditeur des notifications. Vide : le premier administrateur.',
    'save'                 => 'Enregistrer',

    // profile box and "install" prompt
    'this_device'          => 'Notifications sur cet appareil',
    'turn_on'              => 'Activer',
    'turn_off'             => 'Désactiver',
    'send_test'            => 'Envoyer un test',
    'profile_help'         => 'Les événements notifiés sont ceux cochés dans la colonne Mobile ci-dessous. Sur téléphone, installez d\'abord l\'appli (menu ⋮ de Chrome → Installer l\'application, ou Partager → Sur l\'écran d\'accueil dans Safari).',
    'blocked'              => 'Notifications bloquées pour ce site : autorisez-les dans les réglages du navigateur (cadenas de la barre d\'adresse).',
    'state_on'             => 'Activées sur cet appareil.',
    'state_off'            => 'Désactivées sur cet appareil.',
    'turned_on'            => 'Notifications activées',
    'turn_on_failed'       => 'Activation impossible',
    'ask'                  => 'Recevoir les notifications sur cet appareil ?',
    'later'                => 'Plus tard',
    'your_devices'         => 'Vos appareils',
    'this_device_tag'      => 'cet appareil',
    'remove'               => 'Retirer',
    'added_on'             => 'ajouté le :date',
    'no_devices'           => 'Aucun appareil ne reçoit encore vos notifications.',
    'device_removed'       => 'Appareil retiré',
    'unknown_device'       => 'Appareil inconnu',

    // notifications
    'push_body'            => ':name : :text',
    'new_activity'         => 'Nouvelle activité',
    'test_body'            => 'Notification de test : tout fonctionne.',
    'test_sent'            => ':count appareil(s) notifié(s)',
    'test_none'            => 'Aucun appareil abonné n\'a accepté la notification',
    'invalid_subscription' => 'Abonnement invalide',

    // diagnostics (settings page)
    'diagnostics'          => 'Diagnostic',
    'diagnostics_help'     => 'Pour signaler un bug sur GitHub : versions, réglages renseignés ou non, contrôle de santé, appareils par service de notification, et ce que voit ce navigateur (autorisation, service worker, abonnement). Aucun nom, adresse, agent ni adresse d\'appareil. Relisez-le avant de le joindre à une issue : les issues sont publiques.',
    'diagnostics_make'     => 'Créer le fichier de diagnostic',
    'download'             => 'Télécharger',
    'copy'                 => 'Copier',
    'copied'               => 'Copié',
];
