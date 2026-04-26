# Foundation Operations

These capabilities are safe to implement before stakeholder formula sign-off because they do not decide financial amounts, balances, interest, penalties, or reports.

## Documents

The document foundation stores private file metadata for future KYC and operational attachments.

- Files are stored on the private `local` disk by default.
- API responses expose metadata and checksum, not storage paths.
- Supported upload types are PDF, JPG, JPEG, and PNG.
- Documents can be archived instead of deleted.
- Upload and archive actions emit security audit events.

Current endpoints:

- `GET /api/v1/documents`
- `POST /api/v1/documents`
- `GET /api/v1/documents/{document}`
- `PATCH /api/v1/documents/{document}/archive`

## Reference Numbers

Reference numbers are reserved through configured sequences in `config/reference_numbers.php`.

- Reservation is database-transaction protected.
- Sequences are generic and do not create domain records.
- Current configured keys are `staff`, `client`, `account`, `loan`, and `receipt`.
- Later modules should reserve references through `ReferenceNumberGenerator`, not hardcode formatting.

Current endpoint:

- `POST /api/v1/reference-numbers`

## Audit Browsing

Security and model activity events can be browsed by users with `audit.view`.

Current endpoint:

- `GET /api/v1/audit-events`

This is intentionally read-only. Audit records must not be edited by application workflows.
