Database Schema & Entity-Relationship (ER) Mapping

Based on a careful, page-by-page analysis of the 36-page HabisLoan UI design, I have extracted the data structures required to build the backend.
I have organized the tables into logical modules, translated the UI fields into standard database column names (using snake_case), and inferred the appropriate data types and relationships.

1. Core & Administration Module (Exhaustive Schema)
Table 1.1: users (Utilisateurs / Gestionnaires) - Source: Pages 3, 5, 6, 9
Handles authentication, roles, and staff profiles (including Loan Officers/Gestionnaires).
Authentication & Basic Info (Pages 3, 5)
id (UUID, Primary Key)
full_name (String) - Nom (Entrez votre nom intégrale)
phone_number (String, Unique) - Phone number (+237...)
password_hash (String) - Mot de passe
job_title (String) - Poste (Sélectionnez votre poste)
is_phone_verified (Boolean) - Implied by Page 4 (Vérification OTP)


Professional Profile (Page 9 - Gestionnaire)
matricule (String, Unique) - Matricule (Auto-généré)
gender (Enum: M, F) - Sexe
last_name (String) - Nom
first_name (String) - Prénom(s)
birth_date (Date) - Né(e) le
birth_place (String) - Né(e) à
title_function (String) - Titre / Fonction (Gestionnaire / Chef agence...)
agency_id (UUID, Foreign Key -> agencies.id) - Agence
portfolio_name (String) - Porte-feuille (Portefeuille assigné)
status (Enum: ACTIVE, INACTIVE, SUSPENDED) - Statut (Actif)
assignment_date (Date) - Date du
supervisor_id (UUID, Foreign Key -> users.id, Nullable) - Implied by "Gestionnaire sans superviseur" table


Table 1.2: otp_codes - Source: Page 4
Required for the API to handle the 6-digit SMS verification flow.
id (UUID, Primary Key)
phone_number (String)
code (String) - Un code à 6 chiffres
expires_at (Timestamp) - Implied by "0:45 remaining"
Table 1.3: agencies (Agences) - Source: Page 8
id (UUID, Primary Key)
region (String) - Région
branch_type (String) - Type démembrement
code (String, Unique) - Code agence (Ex: AG001)
city (String) - Ville
name (String) - Nom de l'agence (Dénomination officielle)
po_box (String) - B.P. (Boîte postale)
phone (String) - Téléphone
fax (String, Nullable) - Fax
address_description (Text) - Situation géographique
creation_date (Date) - Date de création
status (Enum: ACTIVE, MAINTENANCE) - Statut agence
manager_id (UUID, Foreign Key -> users.id) - Responsable (from the table at the bottom)
Table 1.4: batch_procedures (Procédure Batch) - Source: Page 30
id (UUID, Primary Key)
code (String, Unique) - Code procédure
name (String) - Intitulé procédure
execution_priority (Integer) - Priorité d'exécution (e.g., 0)
execution_timing (String) - Exécution lors du batch
is_active (Boolean) - Exécuter (O/N from the table)

2. CRM & Client Management Module (Exhaustive Schema)
Table 2.1: clients (Fiche client) - Source: Page 10
Header Info
id (UUID, Primary Key)
photo_url (String, Nullable) - Photo client
agency_id (UUID, Foreign Key -> agencies.id) - Agence
prospector_id (UUID, Foreign Key -> users.id) - Prospecteur
status (Enum: ACTIVE, INACTIVE) - Statut client
title (Enum: M, MME, DR) - Civilité
matricule (String, Unique) - Matricule (Auto-généré)


Personal Identity
last_name (String) - Nom (Nom de famille)
first_name (String) - Prénom (Prénom(s))
business_start_date (Date) - Activité date
creation_date (Date) - Client créé le
birth_date (Date) - Né(e) le
birth_place (String) - À (Lieu de naissance)
id_type (Enum: CNI, PASSPORT) - CNI (N° CNI / Passeport)
id_number (String, Unique) - CNI (Input field)
id_issue_date (Date) - Délivré le
id_issue_place (String) - À (Lieu délivrance)
mobile_phone (String) - Tél. portable
home_phone (String, Nullable) - Tél. domicile
father_name (String, Nullable) - Nom père
mother_name (String, Nullable) - Nom mère


Addresses & Collection
home_address (Text) - Adresse client (Quartier, rue...)
business_address (Text, Nullable) - Adresse entreprise
collection_type (String) - Collecte (Type de collecte)
collection_frequency (Enum: DAILY, WEEKLY, MONTHLY) - Fréquence (Hebdomadaire...)
collection_amount (Decimal) - Montant (0 Fcfa)
collection_agent_id (UUID, Foreign Key -> users.id) - Agent collecte


Table 2.2: guarantors (Fiche garant) - Source: Page 12
id (UUID, Primary Key)
agency_id (UUID, Foreign Key -> agencies.id) - Agence
code (String, Unique) - Code garant (Auto-généré)
title (Enum: M, MME, DR) - Civilité
last_name (String) - Nom garant (Nom de famille)
first_name (String) - Prénom (Prénom(s))
id_type (Enum: CNI, PASSPORT) - N° pièce identité (Dropdown)
id_number (String) - N° pièce identité (Input)
id_issue_date (Date) - Délivré le
id_issue_place (String) - À (Lieu de délivrance)
birth_date (Date) - Date naissance
birth_place (String) - Lieu de naissance
mother_name (String) - Nom mère
father_name (String) - Nom père
mobile_phone (String) - Tél. portable
home_phone (String, Nullable) - Tél. domicile
profession (String) - Profession
home_address (Text) - Adresse garant
business_address (Text, Nullable) - Adresse entreprise
Table 2.3: proxies (Fiche mandataire) - Source: Page 13
id (UUID, Primary Key)
status (Enum: ACTIVE, INACTIVE) - Status mandataire
account_id (UUID, Foreign Key -> accounts.id) - N° Compte (N° compte lié)
last_name (String) - Nom (Nom de famille)
first_name (String) - Prénom (Prénom(s))
birth_date (Date) - Né(e) le
birth_place (String) - Lieu naissance
id_document_type (String) - Type pièce
id_number (String) - N° pièce identité
id_issue_place (String) - Délivré à
id_issue_date (Date) - Délivré le
mobile_phone (String) - Téléphone (1st input)
home_phone (String, Nullable) - Téléphone (2nd input)
mother_name (String) - Nom mère
father_name (String) - Nom père
proxy_type (String) - Type (Type mandat...)
start_date (Date) - Date début
end_date (Date) - Date fin
home_address (Text) - Domicile
business_address (Text, Nullable) - Entreprise
signature_url (String) - Zone de signature

3. Accounting & Accounts Module (Exhaustive Schema)
Table 3.1: accounts (Identification du compte) - Source: Page 11
Identification
id (UUID, Primary Key)
client_id (UUID, Foreign Key -> clients.id) - Type client (Dropdown implies linking to client type/profile)
client_matricule (String) - Matricule (N° matricule client)
status (Enum: ACTIVE, INACTIVE) - Status compte
manager_id (UUID, Foreign Key -> users.id) - Nom gestionnaire
account_type (Enum: SAVINGS, CURRENT) - Type compte (Épargne / Courant...)
gl_chapter_code (String) - Chap. Cptabl. (Code chapitre)
account_number (String, Unique) - N° Compte (Auto-généré)
account_title (String) - Intitulé compte (Libellé du compte)


Balances & Movements (Soldes & Mouvements)
accounting_balance (Decimal) - Solde comptable
currency (String) - Devise / note
daily_movement (Decimal) - Mouvement du jour
available_balance (Decimal) - Solde disponible
cumulative_credit_movement (Decimal) - Cumul mvt CR
cumulative_debit_movement (Decimal) - Cumul mvt DB
unavailable_amount (Decimal) - Montant indisponible


Dates & Signatures
creation_date (Date) - Créé le
opening_date (Date) - Date ouverture
closing_date (Date, Nullable) - Date fermeture
created_by_user_id (UUID, Foreign Key -> users.id) - Code utilisateur / Utilisateur (ayant saisi)
signature_url (String) - Zone de signature


Table 3.2: general_ledger_accounts (Comptes généraux) - Source: Page 28
id (UUID, Primary Key)
account_class (String) - Classe (e.g., 1, 2, 3)
code (String, Unique) - Code
name (String) - Libellé compte
Table 3.3: sectors (Secteur) - Source: Page 29
id (UUID, Primary Key)
name (String, Unique) - Libellé (e.g., AGRICULTURE ET ELEVAGE)
Table 3.4: sub_sectors (Sous-secteur) - Source: Page 29
id (UUID, Primary Key)
sector_id (UUID, Foreign Key -> sectors.id) - Secteur activité (from the table)
name (String) - Libellé (Libellé sous-secteur)

4. Credit & Loan Module
Table 4.1: loan_products (Type prêt) - Source: Page 31
This table stores the configuration and rules for different loan offerings. The API must validate new loans against these rules.
id (UUID, Primary Key)
code (String, Unique) - Code type prêt
name (String) - Libellé prêt
gl_account_id (UUID, Foreign Key -> general_ledger_accounts.id) - N° cpte général rattaché
min_amount (Decimal) - Montant minimum
max_amount (Decimal) - Montant maximum
due_date_day (Integer) - Date tombée éché. (Jour)
penalty_grace_days (Integer) - Date pénalisation (jour)
min_duration_months (Integer) - Durée min du prêt (mois)
max_duration_months (Integer) - Durée max du prêt (mois)
min_grace_period_days (Integer) - Nbre jours min différés (jours)
max_grace_period_days (Integer) - Nbre jours max différés (jours)
status (Enum: ACTIVE, INACTIVE) - Statut type prêt
interest_rate (Decimal) - Taux Intérêt
fee_amount (Decimal) - Montant frais
insurance_rate (Decimal) - Assurance
floor_amount (Decimal) - Planchée
guarantee_deposit_type (Enum: PERCENTAGE, FIXED) - Dépôt garantie (1st dropdown)
guarantee_deposit_value (Decimal) - Dépôt garantie (input field)
penalty_formula_type (String) - Formule de la pénalité (1st dropdown)
penalty_formula_base (String) - Formule de la pénalité (2nd dropdown)
penalty_value_type (Enum: PERCENTAGE, FIXED) - Valeur (dropdown)
penalty_value (Decimal) - Valeur (input field)
operation_type (String) - Opération (dropdown)
constant_value (Decimal) - Constance
Table 4.2: loans (Mise en place) - Source: Pages 14, 15, 16
This is the core table. When the frontend hits POST /api/v1/loans, the payload will contain data mapping to all these fields.
Identification & Actors (Page 14)
id (UUID, Primary Key)
loan_number (String, Unique) - N° Prêt (Auto-généré)
application_date (Date) - Date mise en place
processing_level (String) - Niveau traitement
client_id (UUID, Foreign Key -> clients.id) - Matricule client
credit_agent_id (UUID, Foreign Key -> users.id) - Agent de crédit
agency_id (UUID, Foreign Key -> agencies.id) - Agence
loan_product_id (UUID, Foreign Key -> loan_products.id) - Type prêt
Amounts & Activity (Page 14)
granted_amount (Decimal) - Montant accordé
financed_activity_code (String) - Activité financée
sub_sector_id (UUID, Foreign Key -> sub_sectors.id) - Sous secteur
activity_address (Text) - Adresse activité
entrepreneur_address (Text) - Adresse entrepreneur
Rates & Linked Accounts (Pages 14 & 15)
applied_interest_rate (Decimal) - Taux intérêt appliqué
applied_tax_rate (Decimal) - Taux taxe appliqué
amortization_account_id (UUID, Foreign Key -> accounts.id) - Compte amortissement
unpaid_account_id (UUID, Foreign Key -> accounts.id) - Compte impayé
recovery_account_id (UUID, Foreign Key -> accounts.id) - Compte récup (Page 15)
transfer_account_id (UUID, Foreign Key -> accounts.id) - N° Compte virement (Page 15)
Scheduling Parameters (Pages 15 & 16)
first_installment_date (Date) - Première échéance (Page 15)
number_of_installments (Integer) - Nbre échéance (Page 15)
grace_period_duration (Integer) - Durée différé (Page 16)
tranche_duration (Integer) - Durée tranche prêt (Page 16)
total_loan_duration (Integer) - Durée prêt (Page 16)


Fees & Assurances (Page 15)
dossier_fees (Decimal) - Frais dossier
dossier_fees_vat (Decimal) - TVA frais dossier
guarantee_deposit_amount (Decimal) - Montant Dépôt garantie
insurance_amount (Decimal) - Mont. assurance
Live Tracking & Balances (Pages 14, 15, 16) (Note: The API should update these automatically as payments occur)
outstanding_principal (Decimal) - Montant encours prêt (Page 14)
installment_amount (Decimal) - Montant échéance (Page 14)
total_unpaid_amount (Decimal) - Cumul impayé (Page 14)
due_amount (Decimal) - Exigible (Page 14)
total_interest_repaid (Decimal) - Cumul intérêt remboursé (Page 15)
total_penalties_paid (Decimal) - Cumul pénalité (Page 15)
total_principal_repaid (Decimal) - Cumul capital remboursé (Page 15)
installments_repaid_count (Integer) - Nbre échéance remboursée (Page 15)
last_repayment_date (Date) - Dernier remb. (Page 15)
next_repayment_date (Date) - Date prochaine remb. (Page 15)
global_outstanding_amount (Decimal) - Encours global prêt (Page 15)
capitalized_interest (Decimal) - Intérêt capitalisé (Page 16)
cumulative_capitalized_interest (Decimal) - Intérêt cumulé cap. (Page 16)

Table 4.3: loan_approvals (Visas & Validations) - Source: Page 15
Instead of 4 separate tables, use one table to track the workflow state of a loan.
id (UUID, Primary Key)
loan_id (UUID, Foreign Key -> loans.id)
step (Enum: MONTAGE, COMPTABLE, CONTROLE, DIRECTION)
user_id (UUID, Foreign Key -> users.id) - Créé par / Débloqué par / Approuvé par / Validé par
action_date (Date) - Date MEP / Date débl / Date / Date Val.
Table 4.4: loan_schedules (Échéancier prêt) - Source: Page 16
This table holds the generated amortization schedule. The API will generate multiple rows here for a single loan.
id (UUID, Primary Key)
loan_id (UUID, Foreign Key -> loans.id)
installment_number (Integer) - N°
due_date (Date) - Date Ech.
total_installment (Decimal) - Mont. Ech.
principal_amount (Decimal) - Mont. Ech. Cap.
interest_amount (Decimal) - Mont. Ech. Int.
capitalized_interest_amount (Decimal) - Mont. Int. Capitalisé
vat_amount (Decimal) - Montant TVA
penalty_amount (Decimal) - Pénalité
remaining_principal (Decimal) - Capital restant dû
status (Enum: PENDING, PAID, LATE) - Statut
Table 4.5: loan_collaterals (Garanties) - Source: Page 17
id (UUID, Primary Key)
loan_id (UUID, Foreign Key -> loans.id) - N° Prêt
code (String, Unique) - Code garantie (Auto-généré)
status (Enum: ACTIVE, SOLDÉ) - Statut garantie
type (Enum: IMMOBILIÈRE, MOBILIÈRE, CAUTIONNEMENT) - Type garantie
estimated_value (Decimal) - Valeur garantie
guarantor_id (UUID, Foreign Key -> guarantors.id, Nullable) - Code garant
Table 4.6: loan_collateral_items (Objets en garantie) - Source: Page 17
A single collateral record can have multiple physical items attached to it.
id (UUID, Primary Key)
collateral_id (UUID, Foreign Key -> loan_collaterals.id)
quantity (Integer) - Quantité
description (String) - Libellé objet
reference (String) - Référence
chassis_number (String, Nullable) - N° Chassis
registration_number (String, Nullable) - Immatriculation
amount (Decimal) - Montant
Table 4.7: loan_transfers (Mutation de prêt) - Source: Page 18
Tracks the audit history when a portfolio of loans is moved from one manager to another.
id (UUID, Primary Key)
agency_id (UUID, Foreign Key -> agencies.id) - Agence
initial_manager_id (UUID, Foreign Key -> users.id) - Gestionnaire initial
new_manager_id (UUID, Foreign Key -> users.id) - Nouveau gestionnaire
transfer_reason (String) - Motif mutation
transfer_date (Date) - Date mutation
Table 4.8: delinquency_trackings (Suivi exigible) - Source: Page 19
Used by loan officers to log interactions with clients who have overdue loans.
id (UUID, Primary Key)
client_id (UUID, Foreign Key -> clients.id) - Matricule client
loan_id (UUID, Foreign Key -> loans.id) - N° Prêt
tracking_date (Date) - Date du
reason_code (String) - Motif
appointment_type (String) - Type RDV
appointment_date (Date) - Date RD
promised_amount (Decimal) - Montant
comments (Text) - Libellé (Commentaire ou observation)

5. Teller & Cash Operations Module (Exhaustive Schema)
Table 5.1: currency_denominations (Type de monnaie) - Source: Page 32
This table stores the physical bills and coins accepted by the institution. It is used for "Billetage" (cash counting).
id (UUID, Primary Key)
code (String, Unique) - Code monnaie (e.g., "10", "20")
name (String) - Intitulé monnaie (e.g., "BILLET 10 000 CFA")
value (Decimal) - Valeur monnaie (e.g., 10000)
Table 5.2: tills (Caisses) - Source: Page 33
Represents the physical or logical cash registers assigned to tellers.
Identification
id (UUID, Primary Key)
agency_id (UUID, Foreign Key -> agencies.id) - Code agence
code (String, Unique) - Code unique caisse
name (String) - Libellé caisse (Dénomination de la caisse)
assigned_user_id (UUID, Foreign Key -> users.id) - Utilisateur à affecter (Code utilisateur)
gl_account_id (UUID, Foreign Key -> general_ledger_accounts.id) - N° compte rattaché (N° compte comptable)


State & Balances
status (Enum: OPEN, CLOSED) - Status caisse (Ouvert / Fermé)
daily_state (Enum: OPEN, CLOSED) - État caisse (Ouvert / Fermé)
opening_balance (Decimal) - Solde ouverture
last_closing_balance (Decimal) - Solde dernière clôture
last_closing_date (Timestamp) - Date dernière clôture


Parameters & Limits
requires_denominations (Boolean) - Billetage caisse (Oui / Non)
till_type (String) - Type caisse (e.g., Caisse back office, Caisse clientèle)
nature (String) - Nature
is_central_till (Enum: NON, PRINCIPALE) - Caisse centrale (from the table at the bottom)
max_balance_limit (Decimal) - Encaisse maximum (Encaisse maxi)
max_withdrawal_limit (Decimal) - Montant maximum retrait (Maxi retrait)


Table 5.3: teller_transactions (Retrait / Versement) - Source: Page 34
Records front-desk cash movements (Deposits and Withdrawals) linked to a specific till and client account.
Transaction Header
id (UUID, Primary Key)
transaction_date (Date) - Date opération
agency_id (UUID, Foreign Key -> agencies.id) - Agence
till_id (UUID, Foreign Key -> tills.id) - Code caisse
event_number (String, Unique) - N° Evènement (Auto-generated receipt number)
client_account_id (UUID, Foreign Key -> accounts.id) - N° Compte
offset_account_id (UUID, Foreign Key -> general_ledger_accounts.id) - Compte contre partie


Transaction Details
depositor_name (String) - Nom (Remettant / Tireur)
depositor_address (String) - Adresse
direction (Enum: DEPOSIT, WITHDRAWAL) - Sens opération
amount (Decimal) - Montant versement
operation_code (String) - Code oper. (from the table at the bottom)
description (String) - Libellé opération
status (Enum: PENDING, VALIDATED, CANCELLED) - Statut Opération


Table 5.4: manual_journal_entries (Opérations diverses - OD) - Source: Page 35
API Developer Note: Page 35 shows a table where multiple accounts can be debited/credited in a single operation. This requires a Parent-Child database structure to ensure double-entry accounting rules (Total Debits MUST equal Total Credits).
Parent Table: journal_entries (Header)
id (UUID, Primary Key)
agency_id (UUID, Foreign Key -> agencies.id) - Agence
till_id (UUID, Foreign Key -> tills.id, Nullable) - Code caisse
transaction_date (Date) - Date opération
reference_number (String, Unique) - Ref. Pièce
sequence_number (String) - Numéro d'ordre
total_debit (Decimal) - Montant Débit (Calculated)
total_credit (Decimal) - Montant Crédit (Calculated)
balance (Decimal) - Solde (Must be 0 for the API to validate)


Child Table: journal_entry_lines (Détail de l'OD)
id (UUID, Primary Key)
journal_entry_id (UUID, Foreign Key -> journal_entries.id)
account_id (UUID, Foreign Key -> accounts.id OR general_ledger_accounts.id) - N° Compte
direction (Enum: DEBIT, CREDIT) - Sens Ecr.
amount (Decimal) - Montant opération


Table 5.5: till_reconciliations (Consultation caisse) - Source: Page 36
Used at the end of the day when the teller counts the physical cash in their drawer to match the system's theoretical balance.
Parent Table: till_reconciliations
id (UUID, Primary Key)
till_id (UUID, Foreign Key -> tills.id) - Code caisse
reconciliation_date (Timestamp)
theoretical_balance (Decimal) - Solde Théorique (Calculated by system)
actual_balance (Decimal) - Total versement (Calculated from physical count)
difference (Decimal) - (actual_balance - theoretical_balance)


Child Table: till_reconciliation_lines (Billetage)
id (UUID, Primary Key)
reconciliation_id (UUID, Foreign Key -> till_reconciliations.id)
currency_denomination_id (UUID, Foreign Key -> currency_denominations.id) - Code monnaie
quantity (Integer) - Nombre
line_total (Decimal) - (quantity * denomination value)
