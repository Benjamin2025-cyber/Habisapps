# API Resource Contract Audit

Date: 2026-05-04

Reviewed:

- `app/Http/Resources/*Resource.php`
- `app/Http/Resources/*Collection.php`

Findings:

- `AuditEventResource` exposed internal activity-log integer identifiers: `id`, `subject_id`, and `causer_id`.

Actions:

- Removed those internal integer identifiers from audit event API responses.
- Kept public identifiers in other resources.
- Kept existing conditional PII masking for CRM client, identity document, guarantor, and proxy resources.

Verification:

- `PolicyAuthorizationHardeningTest::test_audit_event_resource_does_not_expose_internal_integer_ids`
- `php artisan scramble:export`

Notes:

- Relationship output remains public-ID based.
- Audit event `properties` are still returned because audit viewers need event details; new audit properties must continue to avoid raw tokens, OTPs, passwords, raw phone numbers, and internal integer IDs.
