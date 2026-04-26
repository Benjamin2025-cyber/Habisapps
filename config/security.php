<?php

declare(strict_types=1);

return [
    'auth' => [
        'login' => [
            'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('AUTH_LOGIN_DECAY_MINUTES', 1),
        ],
        'register' => [
            'max_attempts' => (int) env('AUTH_REGISTER_MAX_ATTEMPTS', 3),
            'decay_minutes' => (int) env('AUTH_REGISTER_DECAY_MINUTES', 1),
        ],
        'registration' => [
            'enabled' => (bool) env('AUTH_REGISTRATION_ENABLED', false),
        ],
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 60),
        'token_abilities' => [
            'access-api',
        ],
    ],
    'idempotency' => [
        'ttl_minutes' => (int) env('IDEMPOTENCY_TTL_MINUTES', 1440),
        'bypass_persistence_paths' => [
            'api/v1/login',
            'api/v1/register',
        ],
    ],
    'permissions' => [
        'roles' => [
            'platform-admin' => [
                'system.view-health',
                'users.manage',
                'roles.manage',
            ],
        ],
    ],
    'audit' => [
        'log_name' => 'default',
        'actor_fallback' => 'system',
    ],
];
