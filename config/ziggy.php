<?php

/*
 * Which route names the page ships to the browser (the @routes directive in app.blade.php).
 * A visitor who is not signed in gets only the «guest» group: signing in and the language switch.
 * Without this, the login page listed the names and paths of every admin route.
 */
return [
    'groups' => [
        'guest' => [
            'home', 'locale.update', 'login', 'logout',
            'password.request', 'password.email', 'password.reset', 'password.store',
        ],
    ],
];
