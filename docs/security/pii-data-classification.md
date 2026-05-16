# PII Data Classification

This document records customer and representative personal data introduced by the foundation migrations. It is a security implementation input for API resources, authorization policies, masking, encryption review, exports, audit access, and operational support tooling.

## Restricted Fields

These fields must not be exposed in public API responses unless the endpoint is explicitly authorized for KYC, compliance, or operations use:

- `clients.first_name`
- `clients.middle_name`
- `clients.last_name`
- `clients.date_of_birth`
- `clients.place_of_birth`
- `clients.gender`
- `clients.phone_number`
- `clients.email`
- `clients.address_line_1`
- `clients.address_line_2`
- `clients.city`
- `clients.region`
- `clients.occupation`
- `clients.employer_name`
- `client_identity_documents.document_number`
- `client_identity_documents.issuing_authority`
- `client_identity_documents.issued_on`
- `client_identity_documents.expires_on`
- `client_guarantors.guarantor_full_name`
- `client_guarantors.guarantor_phone_number`
- `client_proxies.proxy_full_name`
- `client_proxies.proxy_phone_number`
- `client_proxies.proxy_email`
- `client_proxies.proxy_id_document_number`
- `collaterals.owner_full_name`

## Required Controls Before API Exposure

- Mask identity document numbers by default, exposing only the minimum suffix needed for staff recognition.
- Mask phone numbers and email addresses outside KYC, compliance, and authorized staff-management workflows.
- Store `client_identity_documents.document_number` and `client_identity_documents.issuing_authority` encrypted at the application layer.
- Use `client_identity_documents.document_number_hash` for normalized duplicate detection and search/uniqueness paths that must not query plaintext document numbers.
- Audit every read endpoint that exposes full identity document numbers or full customer contact details.
- Expose `public_id` and business references externally; never expose internal integer identifiers.
- Return generic duplicate/conflict responses for identity lookups to avoid identity-existence enumeration.

## Reviewer Checklist

- [x] PII fields introduced by foundation migrations are listed.
- [x] Masking, encrypted storage, and hash-based lookup requirements are explicitly documented.
- [x] Authorization and audit expectations are documented before feature APIs are built.
