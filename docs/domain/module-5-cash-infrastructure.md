# Module 5 Cash Infrastructure Safe Slice

This document describes the implemented safe slice for stakeholder Module 5.

Implemented endpoints are setup/reference APIs only:

- `GET /api/v1/denominations`
- `POST /api/v1/denominations`
- `GET /api/v1/denominations/{denomination}`
- `PATCH /api/v1/denominations/{denomination}`
- `GET /api/v1/tills`
- `POST /api/v1/tills`
- `GET /api/v1/tills/{till}`
- `PATCH /api/v1/tills/{till}`

## Denominations

Denominations represent accepted cash note or coin references.

The API stores:

- public ID
- code
- label
- value in integer minor units
- currency
- type
- status

Denomination endpoints do not calculate cash count totals, reconciliation line totals, or accepted tender policies beyond active/inactive reference lifecycle.

## Tills

Tills represent minimal physical or logical cash drawer setup records inside an agency.

The implemented API intentionally accepts only fields already present in the safe schema:

- agency reference
- code
- name
- type
- status
- assigned user reference

Agency-scoped users can only manage tills in their active agency. Platform administrators must specify the target agency explicitly.

Assigned users must be active staff in the same agency as the till.

## Explicit Boundaries

The cash infrastructure API does not implement:

- teller session opening or closing
- deposits
- withdrawals
- cash receipts
- manual journal posting
- till theoretical balances
- opening balances
- closing balances
- actual counted balances
- reconciliation differences
- denomination count line totals
- cash limit enforcement
- ledger account linkage for tills

Those areas remain blocked until accounting posting design and the relevant stakeholder formula decisions are approved. See `docs/domain/formula-guardrails.md` and `docs/domain/stakeholder-formula-questions.md`.
