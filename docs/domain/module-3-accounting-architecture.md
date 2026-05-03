# Module 3: Accounting & Financial Architecture

This module is intentionally structural only.

Safe in-scope behavior:

- chart-of-accounts CRUD
- customer account containers
- account-hold lifecycle facts
- journal entry and journal line storage
- sector and sub-sector reference data
- role and permission catalog for Module 3

Explicitly out of scope until stakeholder formula questions are answered:

- account balances
- available balances
- movement totals
- cash posting
- teller workflows
- repayment allocation
- fees, interest, penalties, and rounding formulas
- reporting metrics

Operational note:

- opening a customer account does not move money
- placing a hold does not compute availability
- creating a journal draft does not create an authoritative balance
- module routes and docs are public-reference first and internal-ID free

Unresolved stakeholder items remain tracked in `docs/domain/stakeholder-formula-questions.md`.
