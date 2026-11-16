<?php

/**
 * Where the server may connect when the admin settings point it somewhere (SECURITY_AUDIT ٩):
 * the AI provider, and the agent mailbox (IMAP and SMTP). Set on the server, in .env, and not in the
 * admin settings: a list the admin could edit would not limit the admin.
 *
 *   OUTBOUND_HOSTS="ollama.com,imap.gmail.com,smtp.gmail.com,*.example.com"
 *
 * Besides the name, every address the name resolves to must be public: a listed name that points at
 * 127.0.0.1, 10.x or 169.254.x (the cloud metadata service) is refused all the same.
 *
 * api.hr.dev.fyntralink.com is the course's Ollama gateway: a trainee pastes the key the course gave them,
 * with that address as the provider, instead of opening an Ollama account (README «مفتاح الدورة»).
 */
return [
    'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('OUTBOUND_HOSTS',
        'ollama.com,api.hr.dev.fyntralink.com,imap.gmail.com,smtp.gmail.com,outlook.office365.com,smtp.office365.com,imap-mail.outlook.com,smtp-mail.outlook.com'))))),

    'ports' => [
        'ai' => [443],
        'imap' => [993],
        'smtp' => [465, 587],
    ],
];
