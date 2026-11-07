<?php

return [
    'state_dir' => '/var/lib/sesame-portal',
    'db_path' => '/var/lib/sesame-portal/portal.sqlite',
    'db_dsn' => null,
    'db_user' => null,
    'db_password' => null,
    'app_secret' => 'replace-with-random-secret',
    'timezone' => 'UTC',
    'locale' => 'ru',
    'base_url' => 'https://portal.example.com',
    // Remember trusted browsers/PWAs until logout, password change or revocation.
    'remember_login_enabled' => true,
    'auth_backend_path' => '/api/sesamedvr/auth',
    // The RBT management_token is the same value as streamer.authbackend.management_token.
    'rbt_authbackend' => [
        'clients' => [
            // 'rbt-main' => ['management_token' => '<RBT-management-token>'],
        ],
    ],
    'portal_update_enabled' => true,
    'portal_update_github_repo' => 'rosteleset/SesamePortal',
    'portal_update_github_ref' => 'main',
    'portal_update_github_token' => '',
    'portal_update_check_ttl_seconds' => 600,
    'portal_update_auto_check' => true,
    'portal_update_command' => 'sudo -n /usr/local/sbin/sesame-portal-update',
    'portal_update_pass_args' => false,
    'crypto_primary_key' => 'primary',
    'crypto_keys' => [
        'primary' => 'replace-with-random-secret',
    ],
];
