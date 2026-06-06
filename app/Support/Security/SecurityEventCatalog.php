<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Maps machine event codes (the stable, filterable `event` key) to
 * human-readable operator-facing descriptions for the security/domain audit log.
 *
 * Labels here are derived from the event code alone. They never incorporate
 * actor, subject, request, or caller property values, so a description can never
 * leak PII, phone numbers, OTPs, tokens, raw IPs, or internal numeric IDs.
 *
 * Unmapped events fall back to a deterministic title-cased rendering of the
 * dotted code (see {@see self::humanize()}), so the audit log never crashes on
 * a new or unexpected event and the raw machine key remains recoverable.
 */
final class SecurityEventCatalog
{
    /**
     * @var array<string, string>
     */
    private const array LABELS = [
        // CRM / PII
        'crm.client.pii_viewed' => 'Client PII record viewed',
        'crm.client.pii_list_viewed' => 'Client PII list viewed',
        'crm.client.created' => 'Client created',
        'crm.client.updated' => 'Client updated',
        'crm.client.kyc_status_changed' => 'Client KYC status changed',

        // Batch procedures / runs
        'batch.procedure.created' => 'Batch procedure created',
        'batch.procedure.updated' => 'Batch procedure updated',
        'batch.procedure.status_changed' => 'Batch procedure status changed',
        'batch.run.created' => 'Batch run created',
        'batch.run.executed' => 'Batch run executed',
        'batch.run.status_changed' => 'Batch run status changed',
        'batch.run.retry_requested' => 'Batch run retry requested',
        'batch.run.cancelled' => 'Batch run cancelled',

        // Database management
        'database.backup.requested' => 'Database backup requested',
        'database.backup.started' => 'Database backup started',
        'database.backup.completed' => 'Database backup completed',
        'database.backup.failed' => 'Database backup failed',
        'database.backup.verified' => 'Database backup verified',
        'database.backup.verification_failed' => 'Database backup verification failed',
        'database.backup.downloaded' => 'Database backup download authorized',
        'database.backup.deleted' => 'Database backup deleted',
        'database.restore.planned' => 'Database restore planned',
        'database.restore.requested' => 'Database restore requested',
        'database.restore.started' => 'Database restore started',
        'database.restore.completed' => 'Database restore completed',
        'database.restore.failed' => 'Database restore failed',
        'database.restore.cancelled' => 'Database restore cancelled',
        'database.retention.run' => 'Database backup retention run',
        'database.maintenance.locked' => 'Database maintenance lock engaged',
        'database.maintenance.unlocked' => 'Database maintenance lock released',

        // Authentication
        'auth.login_succeeded' => 'Login succeeded',
        'auth.login_failed' => 'Login failed',
        'auth.logout_succeeded' => 'Logout succeeded',
        'auth.account_activated' => 'Account activated',
        'auth.password_reset_requested' => 'Password reset requested',
        'auth.password_reset_succeeded' => 'Password reset succeeded',
        'auth.password_reset_failed' => 'Password reset failed',
    ];

    /**
     * Human-readable description for an event code. Mapped labels take
     * precedence; unmapped codes fall back to a deterministic title-casing.
     */
    public static function describe(string $event): string
    {
        return self::LABELS[$event] ?? self::humanize($event);
    }

    /**
     * Whether the event has an explicitly curated label.
     */
    public static function isMapped(string $event): bool
    {
        return array_key_exists($event, self::LABELS);
    }

    /**
     * Deterministic fallback for unmapped codes: replace dotted/underscored
     * separators with spaces and title-case each word. Never throws.
     */
    public static function humanize(string $event): string
    {
        $normalized = trim($event);

        if ($normalized === '') {
            return 'Security event';
        }

        return ucwords(str_replace(['.', '_', '-'], ' ', $normalized));
    }
}
