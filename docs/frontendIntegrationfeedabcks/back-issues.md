1. Referentiel > Agence: how do we search agencies ? need a query param on the get endpoint to make a search

2. When we create a user and assign a role, the get endpoints crashed, i had to relaunch the backend before it worked

3. For the document types, API accept a pure string, i think the api should expose an endpoint to fetch accepted identity document types

4. When we create a mandataire for a client, it throws an error
   Bug: POST /clients/{id}/proxies returns 500 — encrypted column too small

ClientProxy casts proxy_id_document_number as 'encrypted' (app/Models/ClientProxy.php:116), but the column is still varchar(128)
(database/migrations/2026_04_28_045231_create_client_proxies_table.php:22). Laravel's encrypted envelope (~200+ chars) overflows it:

SQLSTATE[22001]: value too long for type character varying(128)

Any non-null proxy_id_document_number fails on insert.

5. Issues with permission assignment to some roles. I keep having the error: Protected permissions can only be granted to platform administrators.
   I even think the admin can assign any permission to any role, he should have the control on that

6. Super admin has the permission to approve kyc documents, but when i try to approve, i get : Accès refusé (403 Forbidden).

7. Admin has permissions to view and manage clients, but when we go to client page, api returns incomplete data for client, and present information are encoded (Ti\***\*\*\*\*\*\*\***\*\***\*\*\*\*\*\*\***aure, etc) meanwhile super admin can view everything

8. Question: Should we have a formular to auto generate account numbers when creating an account ?

9. Staff users should have a search query param

10. It's like the backend doesn't serve images. When i upload a clients profile photo for example, how do i display it back ?

11. Why does super admin has permission create client but on upload of image client it returns a 403 ?

12. Question: La gestions des garants et des mandataires en dehors du scope clients n'existe pas cote back, je trouve ca OK de gerer directement sur les clients mais ca existe sur les pages du pdf, on fait comment ?

13. (Suite du 12 — bloque P8 "Référentiel Garants" et P9 "Référentiel Mandataires" vue institution)
    Besoin de deux endpoints d'index TRANSVERSAUX (scope agence/institution), pour lister
    garants et mandataires de tous les clients d'un coup, avec filtres + pagination serveur :

    GET /api/v1/guarantors (institution-wide)
    GET /api/v1/proxies (institution-wide)

    Aujourd'hui seuls les index imbriqués existent :
    GET /clients/{client}/guarantors → where('client_id', $client->id)->where('agency_id', $client->agency_id)
    GET /clients/{client}/proxies → idem

    Donc impossible de construire une liste institution-wide sans faire du N+1
    (lister tous les clients puis appeler l'index par client). Filtres souhaités :
    scope=all (comme /clients), filter[status], filter[verification_status],
    recherche texte (nom garant/mandataire, téléphone), filter[agency_id].
    Le scoping/permissions peut réutiliser la même logique que l'index /clients
    (crm.scope.institution.read pour scope=all).

14. Produits de prêt — champs de pénalité morts + non validés
    Sur loan_products, 4 champs décrivent la formule de pénalité :
    - penalty_formula_type (string, max 64)
    - penalty_formula_base (string, max 64)
    - penalty_value_type (string, max 32)
    - penalty_value (numeric)

    Problèmes constatés :
    a) AUCUNE valeur autorisée n'est définie. La validation (Store/UpdateLoanProductRequest)
    n'accepte qu'un string/numeric libre — donc une faute de frappe ("flate_rate",
    "principel", …) passe sans erreur. L'utilisateur n'a aucun moyen de savoir quoi saisir.
    b) Le moteur de pénalité ne les lit PAS. AssessLoanArrearsAndPenalties calcule la pénalité
    à partir de penalty_grace_days (produit) + config('formulas.policies.penalties_and_arrears
    .rules.monthly_arrears_penalty') (fixed_amount_minor=5000, variable_rate_percent='2',
    minimum_unpaid_amount_minor=1000). Même penalty_value est ignoré.
    c) Ces 4 champs ne sont que « snapshottés » verbatim dans loans.formula_policy_snapshot
    (LoanProductFormulaPolicySnapshotter), jamais interprétés.

    Demande : soit (1) câbler ces champs dans le moteur de pénalité ET exposer des enums
    (Rule::in) pour formula_type / formula_base / value_type, soit (2) les retirer si la
    pénalité reste pilotée par la config globale. En attendant, ils sont DÉSACTIVÉS côté FE
    (drawer produit de prêt) ; seul penalty_grace_days reste éditable (réellement utilisé).

15. Prêts (P11 Mise en place) — pas de GET pour les visas ni pour le tableau d'amortissement
    LoanResource n'expose NI les approbations (visas) NI le tableau d'amortissement, et il
    n'existe aucune route GET pour les récupérer :
    - approbations : seul POST /loans/{loan}/approvals/{step} existe (renvoie l'approbation
      agie). Impossible de recharger l'état des 4 étapes (montage/comptabilite/controle/
      direction) — qui a visé, quand, décision — après un refresh.
    - tableau d'amortissement : seul POST /loans/{loan}/schedule/generate existe (renvoie le
      snapshot + lignes). Aucun GET /loans/{loan}/schedule → le tableau généré n'est plus
      consultable après navigation/refresh sans le régénérer.

    Demande :
    a) GET /loans/{loan}/approvals (liste des LoanApproval : step, decision, acted_by,
    acted_at, comments) — ou inclure `approvals` dans LoanResource (show).
    b) GET /loans/{loan}/schedule (snapshot actif + lignes) — ou inclure `schedule` /
    `active_schedule` dans LoanResource (show).

    Contournement FE en attendant : le stepper de visas est dérivé de loan.status
    (application → in_review → approved/rejected) + résultat éphémère de l'action ;
    le tableau s'affiche juste après « Générer » puis invite à régénérer au rechargement.

    Note annexe : loans.status.transition, loans.schedules.generate et
    loans.schedules.reschedule ne sont accordées qu'à platform-admin dans config/security.php
    — à confirmer si agency-manager/loan-officer doivent aussi générer le tableau / soumettre.

16. Création de prêt → 500 si le produit référence une politique de formule NON approuvée
    POST /loans renvoie 500 (FormulaPolicyNotApproved) si le produit de prêt a un
    \*\_policy_key pointant vers une politique dont config('formulas.policies.{key}.approved')
    est false. Aujourd'hui la SEULE non approuvée est `penalties_and_arrears` (approved=false
    dans config/formulas.php:125) — donc un produit avec penalty_policy_key='penalties_and_arrears'
    rend TOUT prêt incréable.

    Chaîne : LoanCrudWorkflow::store() → LoanProductFormulaPolicySnapshotter::applyToLoan()
    → snapshot() → FormulaPolicyRegistry::isApproved() == false → throw.

    Deux problèmes :
    a) Message d'erreur trompeur. snapshot() lève l'exception en nommant la PREMIÈRE
    politique configurée (interest_policy_key='loan_interest_method') au lieu de la
    vraie coupable (penalties_and_arrears). Cf. LoanProductFormulaPolicySnapshotter::snapshot()
    qui fait `foreach (configuredPolicies) throw forPolicy($policy)` au lieu d'itérer
    sur $errors. → corriger pour nommer la/les politique(s) réellement non approuvée(s).
    b) Rien n'empêche de créer/éditer un produit avec une politique non approuvée. Idéalement
    valider à l'enregistrement du produit (Store/UpdateLoanProductRequest) que chaque
    \*\_policy_key référence une politique approuvée, OU approuver `penalties_and_arrears`
    (config/formulas.php) si le sign-off stakeholder est acquis.

    Contournement appliqué :
    - Data : penalty_policy_key du produit PRET-PME-STD remis à NULL (création de prêt OK).
    - FE : toggle « Politique de pénalités & arriérés » DÉSACTIVÉ dans le drawer produit de
      prêt + penalty_policy_key forcé à null à l'enregistrement (ne peut plus être rattaché).

17. KYC documents — pas de recto/verso, type libre, fichier non récupérable
    Modèle actuel : 1 fichier par Document (collection média `kyc_documents` en `->singleFile()`),
    POST /documents = 1 `file`, et chaque rattachement (identity-documents, garant, mandataire)
    ne stocke qu'UN `document_public_id`. Conséquences :
    a) Impossible de stocker recto + verso (ex. CNI camerounaise) — un seul fichier par pièce.
    b) `document_type` est un string libre et n'existe QUE sur client identity-documents ; le
    garant et le mandataire ne portent qu'un fichier de preuve (catégorie Document grossière :
    kyc/identity/proof_of_address), pas de type fin (national_id, passport…).
    c) L'API ne ressert pas les fichiers (cf. #10) → les images KYC sont write-only (verif. uniquement).

    Demande : (1) un catalogue de types de pièces avec, par type, les faces requises
    (recto seul / recto+verso) ; (2) support multi-fichiers ou champs front_document_id /
    back_document_id sur les pièces (identity-documents, garant, mandataire) ; (3) endpoint de
    récupération/affichage des fichiers. En attendant, le FE capture UNE image par pièce
    (recto) + le type sur le formulaire client KYC.

18. Guarantee obligation `release_condition` — non validé (pas d'enum) et non exploité
    Sur loan_guarantee_obligations, `release_condition` est un string libre (max 128, défaut
    'loan_closed'). Aucun enum (Rule::in), et le endpoint /release ne le lit PAS : la libération
    est toujours conditionnée à loan.status = closed, quelle que soit la valeur. C'est donc une
    métadonnée descriptive non appliquée.
    Demande : si la libération doit dépendre de la condition (ex. 'loan_closed', 'manual',
    'date', 'guarantee_replaced'), câbler `release_condition` dans la logique de libération ET
    exposer un enum/catalogue des valeurs autorisées. En attendant, le FE propose un Select à
    valeur unique 'loan_closed' (« À la clôture du prêt ») pour éviter la saisie libre.

19. LoanResource n'expose pas l'encours (outstanding) — impacte P13 (mutation) et listes prêts
    Les colonnes existent en base (outstanding_principal_minor, global_outstanding_amount_minor,
    total_unpaid_amount_minor, etc.) mais LoanResource ne renvoie que requested_amount_minor +
    approved_principal_minor. Du coup la page Mutation affiche un « encours total » basé sur le
    principal approuvé/demandé, pas l'encours réel. Demande : ajouter l'encours
    (outstanding_principal_minor + global_outstanding_amount_minor) à LoanResource pour des
    totaux exacts (mutation, suivi, dashboards).

20. Exposer un catalogue des politiques de formule (pour piloter l'UI produit de prêt)
    Aujourd'hui chaque \*\_policy_key du produit de prêt est contraint à UNE seule valeur via
    Rule::in([...]) (interest_policy_key → loan_interest_method, penalty_policy_key →
    penalties_and_arrears, fee/tax/insurance/guarantee_deposit → fees_taxes_insurance, etc.).
    Côté FE on les expose donc en cases à cocher (rattacher / ne pas rattacher) — un Select
    n'aurait qu'une option par champ. De plus le FE ne connaît PAS l'état `approved` des
    politiques (uniquement dans config/formulas.php), ce qui a causé le 500 du point #16.

    Demande : un endpoint catalogue, ex.
    GET /api/v1/formula-policies
    → [{ key, category, approved (bool), label }, ...]
    (catégorie = interest / penalty / fees / rounding / schedule / reporting / allocation…).

    Bénéfices côté FE :
    - rendre le formulaire produit de prêt PILOTÉ PAR LES DONNÉES : ne proposer que les
      politiques `approved=true`, masquer/désactiver automatiquement les non approuvées
      (plus besoin de hardcoder la désactivation du toggle pénalité — cf. #16) ;
    - basculer en vrai Select dès qu'un champ acceptera plusieurs politiques approuvées
      (ex. plusieurs méthodes d'intérêt), sans changement FE supplémentaire.
      Idéalement, le Rule::in de chaque champ devrait alors lister toutes les valeurs de la
      catégorie correspondante (pas une seule), pour que le choix existe vraiment.

21. Rôles & permissions — l'éditeur ÉCRIT en base mais LIT le fichier de config
    GET /roles construit les permissions de chaque rôle à partir de
    config('security.permissions.roles') (RoleController::roleCatalog →
    configuredRoleDefinitions), PAS de la base (Spatie role_has_permissions).
    Or PUT /roles/{role}/permissions persiste bien en base (vérifié : le pivot de
    user-admin contient loans.guarantees.manage + crm.guarantors.pii.view après save).

    Conséquence (reproduit) : on accorde une permission → « updated successfully » →
    mais l'éditeur affiche toujours les permissions du CONFIG (defaults), jamais l'état
    réel en base. Donc : (a) « unsaved changes » permanent (baseline config ≠ sélection),
    (b) au refresh la permission accordée réapparaît décochée (GET relit le config).

    Correctif backend requis : roleCatalog() doit renvoyer les permissions RÉELLES du rôle
    en base — $role->loadMissing('permissions')->permissions->pluck('name') — et non
    configuredRoleDefinitions(). Le catalogue des permissions disponibles peut rester dérivé
    du config, mais l'attribution PAR RÔLE doit venir de la base.
    Aucun contournement FE possible : le front affiche fidèlement ce que GET /roles renvoie.

22. Prêt — impossible de modifier les comptes liés (dont recovery_account) après le brouillon
    LoanCrudWorkflow.update rejette toute mise à jour si loan.status != application
    (422). Or recovery_account_public_id (Compte de recouvrement) — indispensable au
    recouvrement — n'est utilisé QUE sur un prêt disbursed/active/rescheduled, état où le
    prêt n'est plus éditable. Donc si le compte de recouvrement n'a pas été défini à la
    création (brouillon), il n'existe AUCUN moyen de l'ajouter/changer ensuite (aucun
    endpoint n'accepte les comptes liés post-brouillon).
    Demande : permettre de mettre à jour les comptes liés (recovery/unpaid/amortization/
    transfer) sur un prêt actif, soit en assouplissant l'update, soit via un endpoint dédié
    (ex. PATCH /loans/{id}/accounts). Sinon le recouvrement automatique est inutilisable dès
    qu'on a oublié de configurer le compte au montage.

23. Index /loans — pas de filtre par client (ni clients/{client}/loans)
    GET /loans ne filtre que par status (+ scope agence). Aucun filtre client_public_id
    ni endpoint clients/{client}/loans. Sur Garanties et Suivi des exigibles, on sélectionne
    désormais le client puis ses prêts — mais le filtrage par client se fait CÔTÉ FRONT sur
    la page chargée (perPage max 100, ordre latest). Donc si un client a des prêts au-delà
    des 100 derniers prêts de l'institution, ils n'apparaîtront pas.
    Demande : un filtre serveur sur l'index prêts, ex. GET /loans?filter[client_public_id]=...
    (ou GET /clients/{client}/loans), pour peupler la liste avec TOUS les prêts du client.
