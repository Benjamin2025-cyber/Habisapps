# KYC Media Security Policy

This policy defines the current baseline for KYC document media handling.

## Scope

- Applies to KYC documents stored through `App\Models\Document`.
- Applies to media in the `kyc_documents` collection.

## Storage And Exposure

- KYC media uses the private `local` disk by default (`storage/app/private`).
- Public URLs for KYC media are not exposed by API resources.
- Internal storage paths are never returned in API payloads.

## Conversions And Responsive Images

- KYC document media conversions are intentionally disabled in `Document::registerMediaConversions()`.
- Responsive image generation is not enabled for KYC upload flows.
- Any future KYC conversion use case requires explicit security review and approval before implementation.

## Temporary URL Policy

- No KYC media download endpoint is currently exposed.
- If a download endpoint is introduced later:
- It must authorize through the `Document` domain policy and agency scope checks.
- It must use short-lived temporary access (default max 5 minutes unless approved otherwise).
- It must be audit logged with actor, agency, and document public ID.
- It must never bypass authorization by direct media ID access.

## Upload Guardrails

- Allowed upload types for KYC API: `pdf`, `jpg`, `jpeg`, `png`.
- Maximum upload size for KYC API: 10 MB.
- File checksum (`sha256`) is computed and stored for integrity tracking.
