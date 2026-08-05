# Guided test — consolidated chart of accounts & institution profile

Companion to [consolidated-chart-of-accounts.md](consolidated-chart-of-accounts.md), which
explains *why* the feature is built the way it is. This document is the **click-through
script**: follow it top to bottom and you will have exercised every part of the change.

Written for a non-accountant. Each step says what to do, what you should see, and — where
it matters — *why* accounting works that way, so you can tell a bug from correct behaviour.

Branches: API `feat-entreprise`, frontend `feat-institution-level`.

---

## 0. The idea in ninety seconds

An EMF has several agencies. Each agency has its own cash account, and head office wants
one figure for "all the cash in the institution". So the chart of accounts is a **tree**:

```
571000  Caisse Globale          ← institution level. A TOTAL. Holds no entries itself.
├── 571001  Caisse HABIS Test   ← agency TEST-HABIS. Real entries land here.
└── 571002  Caisse Cookbook     ← agency AG-COOK-01. Real entries land here.
```

Three vocabulary items are all you need:

| Term | Meaning |
|---|---|
| **Detail account** (*compte imputable*) | A real account. Entries land on it. `571001`, `571002`. |
| **Grouping account** (*compte de regroupement*) | A subtotal. Entries are **refused** on it. Its balance is the sum of its children. `571000`. |
| **Consolidated** | Reading a grouping account's total instead of its own (always zero) movements. |

The single rule that explains most of what you will see: **a total cannot itself receive
money.** If it could, you would count the same 100 F twice — once on `571001` and again on
`571000`. So the system refuses it, in several places. Those refusals are the feature
working, not breaking.

**What you are proving:** (1) the tree can be built through the UI, (2) the wrong things
are refused, (3) the numbers add up.

---

## 1. Before you start

### 1.1 Log out and log back in — do not skip this

Permissions are cached in your browser session at login. The new permissions
(`institution.profile.view`, `ledger.scope.institution.manage`, …) were added to the
database by the seeder, but **your existing session still carries the old list**. Nothing
new appears until you log out and in again.

> If *Paramétrage › Institution* is missing from the sidebar, this is why. It is the single
> most likely cause of "the feature isn't there".

### 1.2 Your starting data

Already present, no setup needed:

| | |
|---|---|
| Agencies | `TEST-HABIS` — HABIS Test Agency · `AG-COOK-01` — Cookbook Test Agency |
| Open accounting days | TEST-HABIS · AG-COOK-01 · INSTITUTION |
| Platform admin | `admin@example.com` |
| Agency accountant | `test.accountant@example.test` (agency **AG-COOK-01**) |

### 1.3 Create the chief accountant

The role now exists but nobody holds it. *Administration › Gestion des utilisateurs* → create
a user, assign the role **chief-accountant**, and **leave the agency empty**.

Leaving it empty is deliberate and is itself part of the test: head office is not attached
to a branch. If the UI forces you to pick an agency, that is a finding — report it.

### 1.4 Amounts

You type **major units**; the system stores minor (×100). Typing `100` is 100,00 XAF. This
guide always shows what you type.

---

## 2. The target, on one page

| Code | Name | Scope | Nature | Parent | Agency |
|---|---|---|---|---|---|
| `571000` | Caisse Globale | Institution | Grouping | — | — |
| `571001` | Caisse HABIS Test | Agency | Detail | `571000` | TEST-HABIS |
| `571002` | Caisse Cookbook | Agency | Detail | `571000` | AG-COOK-01 |
| `571901` | Contrepartie HABIS | Agency | Detail | — | TEST-HABIS |
| `571902` | Contrepartie Cookbook | Agency | Detail | — | AG-COOK-01 |

Then post `100` in TEST-HABIS and `40` in AG-COOK-01, and check that `571000` reads **140**.

The two `5719xx` accounts exist only because every entry needs two sides — debit one
account, credit another. They are the "where the money came from" side. Ignore them
otherwise.

---

## 3. Build the structure

### Step 1 — Identify the institution

**Log in as `admin@example.com`.** Go to **Paramétrage › Institution**.

You should see an amber banner, *"Institution non encore identifiée"*. That is correct on a
fresh install: the row exists but is empty. Nothing is invented — the legal name on a COBAC
filing has to be typed by a human.

Fill in at least:

- **Raison sociale**: `HABIS MICROFINANCE SA` — the legal person, as on the agrément/RCCM.
- **Nom commercial**: `HabisLoan` — the public brand. *(Leave empty if identical; most
  small EMFs have no separate trade name.)*
- **Autorité de supervision**: `COBAC`
- **Numéro d'agrément**: any test value.

Save.

✅ **Expect**: success toast, the amber banner disappears, values survive a page reload.

### Step 2 — Create the institution total `571000`

**Comptabilité › Comptes généraux** → *Créer*.

| Field | Value |
|---|---|
| Périmètre | **Institution** |
| Code | `571000` |
| Intitulé | `Caisse Globale` |
| Classe | Actif |
| Sens normal | Débit |

Choosing **Institution** makes the *Agence* field disappear and replaces the *Nature* field
with a note that an institution account is always a grouping account. That is the schema
speaking: an institution account has no agency and cannot be postable.

✅ **Expect**: created. In the list, the **Structure** column shows an *Institution* badge
and a *Compte de regroupement* badge.

> If the **Périmètre** selector is not there, you lack `ledger.scope.institution.manage` —
> re-check §1.1.

### Step 3 — Create `571001` and watch the parent change

*Créer* again:

| Field | Value |
|---|---|
| Périmètre | Agence |
| Agence | `TEST-HABIS` |
| Code | `571001` |
| Intitulé | `Caisse HABIS Test` |
| Nature | **Compte imputable (détail)** |
| Compte parent | `571000 — Caisse Globale` |
| Classe / Sens | Actif / Débit |

✅ **Expect two things:**

1. `571001` is created as a detail account.
2. **Look back at `571000`.** It was already a grouping account, so nothing visibly changes
   here — but this is the mechanism to remember: *the first time any account gains a child,
   the system silently turns that parent into a grouping account.* You will see it in
   Step 5b. It is why the list refreshes from the server after every create instead of
   patching the row locally.

### Step 4 — Create `571002` under the other agency

Same as Step 3 with **Agence = `AG-COOK-01`**, code `571002`, name `Caisse Cookbook`,
parent `571000`.

✅ **Expect**: created. Two agencies now share one institution parent — this is exactly the
structure the testers asked for, and the reason cross-agency parenting is refused (Step 7b)
while sharing an institution parent is allowed.

### Step 5 — Create the two counterpart accounts

Two more, both **Nature = Compte imputable**, **no parent**:

- `571901` `Contrepartie HABIS` — agency TEST-HABIS — Classe Passif — Sens Crédit
- `571902` `Contrepartie Cookbook` — agency AG-COOK-01 — Classe Passif — Sens Crédit

#### Step 5b — See the auto-conversion for yourself *(optional, 1 minute)*

Create a throwaway detail account `571999` in TEST-HABIS with **parent `571901`**. Now look
at `571901` in the list: it has gained a *Compte de regroupement* badge and is no longer
postable — **you never asked for that.** It happened because it became a total.

This is the one behaviour most likely to look like a bug. It isn't: an account with
children must not also carry entries of its own. Archive `571999` afterwards if you like;
the badge on `571901` stays, so use `571902` for the entry in Step 6 if `571901` is now a
grouping account. *(Simpler: skip 5b until the end.)*

---

## 4. Post real money

This is the fiddly part, and one rule explains the fiddliness: **the person who writes an
entry may not approve it.** That is *maker-checker* (four-eyes) — a bank control, not an
inconvenience to work around. So you need **two users**.

Also note the agency **accountant cannot create entries at all** — it holds
`journal.entries.view` only. Entry creation belongs to head office. So:

| Role in the test | User |
|---|---|
| **Maker** (writes, submits) | your **chief-accountant** |
| **Checker** (approves, posts) | `admin@example.com` |

### Step 6 — Entry 1: 100 in TEST-HABIS

**As the chief accountant**, go to **Comptabilité › Opérations diverses** → create an entry:

- Agence: `TEST-HABIS`
- Référence: `TEST-CONSO-A`
- Date: today

Open it and add two lines:

| Compte | Sens | Montant |
|---|---|---|
| `571001 — Caisse HABIS Test` | Débit | `100` |
| `571901 — Contrepartie HABIS` | Crédit | `100` |

✅ **Expect — and this is a headline check:** the account dropdown **does not offer
`571000`**. The total is not selectable, so you cannot make the double-counting mistake.
Before this change it *was* offered and produced a confusing error on save.

Then **Soumettre**.

**Log in as `admin@example.com`**, open the same entry, **Approuver**, then **Comptabiliser**
(post). Only a posted entry affects balances.

> Trying to approve as the chief accountant gives *"Journal approval requires a reviewer
> different from the maker"* — correct behaviour.

### Step 7 — Entry 2: 40 in AG-COOK-01

Repeat exactly, as the chief accountant then the admin:

- Agence `AG-COOK-01`, référence `TEST-CONSO-B`
- `571002` Débit `40` · `571902` Crédit `40`
- Submit → approve → post.

---

## 5. Read the numbers — the payoff

### Step 8 — The consolidated balance of `571000`

**Comptabilité › Comptes généraux** → open `571000`.

✅ **Expect `140`** — not zero.

`571000` has never received a single entry. 140 is `100` (TEST-HABIS) + `40` (AG-COOK-01),
computed by walking the tree. A grey note says the amounts are consolidated. **This one
number is the whole feature.**

Also check `571001` alone reads `100` and `571002` reads `40`.

### Step 9 — The consolidated trial balance

A *balance des comptes* (trial balance) lists every account with its debit and credit
totals. **Édition › Balance des comptes** → *Générer*:

- Définition: **trial_balance**
- **Consolidation: `Consolidé (cumulé)`**
- **Agence: leave EMPTY** → *"Toutes les agences (institution)"*
- Devise: `XAF`

Leaving the agency empty is what makes the run institution-wide. Pick an agency and you get
that agency's tree only — valid, but not the consolidated institution figure.

✅ **Expect**, in the preview:

| Check | Expected |
|---|---|
| Row `571000` | debit **140**, scope `institution`, `is_postable` = *Non* |
| Row `571001` | debit **100**, agency = TEST-HABIS |
| Row `571002` | debit **40**, parent = `571000` |
| **Grand total debit** | **140**, *not* 280 |

The grand total is the subtle one. `571000` shows 140 and `571001`+`571002` show 140
between them, so naively summing the rows gives 280. The totals are computed from the
**posted** accounts only, so each movement is counted once however deep the tree is. **If
you see 280, that is a real bug — report it.**

Now regenerate with **Consolidation: `Comptes de détail uniquement`**: `571000` disappears
(it has no movements of its own) and the total stays 140.

---

## 6. The refusals — failures that prove it works

Each of these **must fail**. Copy the message you get if any of them succeeds.

| # | Do this | Must fail with |
|---|---|---|
| 7a | Create an account with **Périmètre = Institution** *and* **Nature = imputable** | The Nature field isn't offered for institution scope — the UI prevents it. Via API: *"Institution-level ledger accounts group agency accounts and cannot receive entries."* |
| 7b | Create an account in **TEST-HABIS** with parent **`571002`** (an AG-COOK-01 account) | `571002` is **not in the parent dropdown**. Cross-agency parenting is refused: two agencies may share an *institution* parent, never each other's accounts. |
| 7c | Add a journal line on **`571000`** | `571000` is **not in the account dropdown** (Step 6). |
| 7d | Make `571000` the debit/credit target of an operation mapping — *Comptabilité › Codes opération & imputations* | `571000` is **not in the account dropdown**. Automatic postings must not target a total either. |
| 7e | As **`test.accountant@example.test`**, open `571000`'s balance | Account visible (**200**), balance/movements **403** with an explanation. An agency accountant may file accounts under the institution total but not read a figure that spans every agency. **A 403 here is the correct answer, not an error.** |
| 7f | As `test.accountant@example.test`, try to create an institution account | No **Périmètre** selector — it only maintains its own agency's chart. |
| 7g | As the chief accountant, try to **reopen** a closed accounting day | Refused. Reopening a closed period stays with platform admins — whoever closed it must not be able to reopen it. |

---

## 7. Role boundaries

### Step 10 — The chief accountant runs the institution period

**As the chief accountant**, go to **Administration › Journée Comptable**.

✅ **Expect:**
- The page loads and lists days **even though this user has no agency**. It used to return a
  blanket 403 to any head-office user.
- *Ouvrir* offers a **Périmètre** choice including **Institution**. Before this change that
  choice was reserved for `platform-admin` by a hard-coded role check, so the new role could
  not do the job it was created for.
- Opening and starting the close of the **institution** day works.

> **Known dependency, not a bug:** starting a close requires the close-control batch
> procedures to be configured, which is deliberately platform-admin-only
> (`batch.procedures.manage`). If start-close reports *"No active batch procedure is
> configured for one or more close controls"*, that is the documented behaviour: the chief
> accountant holds every permission start-close checks and still needs a platform admin to
> have set up the controls.

### Step 11 — The agency accountant stays in its lane

As `test.accountant@example.test`: it can maintain **AG-COOK-01**'s chart (that is new —
the chart of accounts is an accounting job, previously platform-admin only), but is refused
the institution chart, another agency's chart, consolidated figures, and the institution
period.

---

## 8. If something looks wrong

| Symptom | Almost certainly |
|---|---|
| *Paramétrage › Institution* missing from the sidebar | Old session. **Log out and back in** (§1.1). |
| No **Périmètre** selector on the account form | Same — or the user genuinely lacks `ledger.scope.institution.manage`. |
| Cannot create a journal entry | The agency has no **open accounting day**. Open one in *Administration › Journée Comptable*. |
| *"Journal approval requires a reviewer different from the maker"* | Working as designed. Approve with the **other** user (§4). |
| The account I want is missing from a dropdown | Almost always correct: it is a grouping account, or belongs to another agency. Check the **Structure** column. |
| An account became "Compte de regroupement" on its own | Correct — it gained a child (§5b). |
| A balance reads 0 where you expected a figure | The entry is not **posted**. Submitted and approved are not enough. |
| Grand total is double the expected figure | **Real bug.** Report it (§ Step 9). |
| Only the first ~100 accounts appear in a dropdown | Known limitation: the chart is loaded one page at a time and filtered in the browser. Harmless at this size, must be fixed before a full PCEMF chart is loaded. |

---

## 9. Coverage checklist

- [ ] Institution profile read, updated, persisted; unconfigured banner behaves
- [ ] Institution grouping account created (`571000`)
- [ ] Two agencies' detail accounts filed under one institution parent
- [ ] Auto-conversion of a parent into a grouping account observed
- [ ] Entries posted in two agencies through maker-checker
- [ ] Consolidated balance of `571000` = 140
- [ ] Consolidated trial balance: rows correct **and grand total not double-counted**
- [ ] Non-consolidated run drops the grouping account and keeps the total
- [ ] All seven refusals in §6 refused
- [ ] Chief accountant runs the institution period with no agency assignment
- [ ] Agency accountant confined to its own agency

Anything unchecked, or any refusal that did not refuse, is worth reporting with the exact
message and the user you were logged in as.
