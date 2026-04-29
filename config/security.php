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
        'activation' => [
            'max_attempts' => (int) env('AUTH_ACTIVATION_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('AUTH_ACTIVATION_DECAY_MINUTES', 1),
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
            'api/v1/activate',
            'api/v1/activation/resend',
            'api/v1/batch-runs',
        ],
    ],
    'otp' => [
        'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_decay_minutes' => (int) env('OTP_RESEND_DECAY_MINUTES', 1),
        'delivery_channels' => [
            'sms',
            'email',
        ],
        'delivery_provider' => env('OTP_DELIVERY_PROVIDER', 'log'),
    ],
    'permissions' => [
        'roles' => [
            'platform-admin' => [
                'system.view-health',
                'audit.view',
                'agencies.view',
                'agencies.manage',
                'users.view',
                'users.create',
                'users.manage',
                'users.update',
                'users.status.manage',
                'roles.manage',
                'roles.view',
                'users.roles.manage',
                'staff.assignments.view',
                'staff.assignments.manage',
                'batch.procedures.view',
                'batch.procedures.manage',
                'batch.runs.view',
                'batch.runs.manage',
                'documents.view',
                'documents.create',
                'documents.archive',
                'references.reserve',
            ],
            // Deprecated compatibility alias for agency-scoped staff administration.
            // New grants should use agency-manager.
            'user-admin' => [
                'users.view',
                'users.create',
                'users.update',
                'users.status.manage',
                'documents.view',
                'documents.create',
                'references.reserve',
            ],
            'agency-manager' => [
                'agencies.view',
                'users.view',
                'users.create',
                'users.update',
                'users.status.manage',
                'staff.assignments.view',
                'staff.assignments.manage',
                'documents.view',
                'documents.create',
                'references.reserve',
            ],
            'regional-manager' => [
                'users.view',
                'agencies.view',
                'documents.view',
                'references.reserve',
            ],
            'teller' => [
                'system.view-health',
                'agencies.view',
                'documents.view',
                'documents.create',
                'references.reserve',
            ],
            'loan-officer' => [
                'system.view-health',
                'agencies.view',
                'documents.view',
                'documents.create',
                'references.reserve',
            ],
            'accountant' => [
                'system.view-health',
                'audit.view',
                'agencies.view',
                'documents.view',
                'references.reserve',
            ],
            'auditor' => [
                'system.view-health',
                'audit.view',
                'agencies.view',
                'users.view',
                'documents.view',
            ],
            'staff' => [
                'system.view-health',
                'agencies.view',
            ],
        ],
    ],
    'audit' => [
        'log_name' => 'default',
        'actor_fallback' => 'system',
    ],
    'documents' => [
        'backfill' => [
            'allowed_source_disks' => array_values(array_filter(array_map(
                static fn (string $disk): string => trim($disk),
                explode(',', (string) env('DOCUMENT_BACKFILL_ALLOWED_SOURCE_DISKS', 'local'))
            ))),
        ],
    ],
];
