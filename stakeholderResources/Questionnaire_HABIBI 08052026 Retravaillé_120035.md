**HABIBI S.A**

Guide de Clarification des Formules pour les Parties Prenantes

**RÉPONSE DE L\'ÉQUIPE OPÉRATIONNELLE · 08 / 05 / 2026**

> **DESTINATAIRES**
>
> Parties prenantes · Propriétaires de produits · Comptables ·
> Directeurs d\'agence · Responsables de crédit · Personnel des
> opérations
>
> Ce guide explique chaque décision de calcul dont le back-end a besoin
> avant de mettre en œuvre les prêts, comptes, opérations de guichet et
> rapports. La devise de fonctionnement de base est le XAF.
>
> **REMARQUE :** Le document a été difficile à exploiter pendant notre
> séance de travail car trop sommaire et pas assez précis.
>
> **Comment utiliser ce document :**
>
> • Lire l\'explication.
>
> • Examiner l\'illustration.
>
> • Choisir la règle métier.
>
> • Remplir les champs de décision.
>
> • Marquer la section comme approuvée uniquement lorsque les
> Finances/Opérations sont d\'accord.
>
> Les exemples sont fournis à titre illustratif uniquement. Ils ne
> constituent pas des formules finales proposées sauf approbation
> explicite.
>
> **1. PRÉCISION ET ARRONDI EN XAF**

**CE QUE CELA SIGNIFIE**

Le système calculera les intérêts, frais, taxes, pénalités, soldes et
échéanciers. Certaines formules peuvent produire des valeurs
fractionnaires (ex. 333,33 XAF). Les parties prenantes doivent décider
comment le système arrondit ces valeurs.

**ILLUSTRATION**

> Calcul d\'intérêts sur prêt produisant : intérêt brut = 10 000,67 XAF
>
> Arrondi au XAF entier le plus proche = 10 001 XAF
>
> Arrondi à l\'inférieur = 10 000 XAF \| Arrondi au supérieur = 10 001
> XAF
>
> Conserver les décimales internes jusqu\'au total final = 10 000,67 XAF
> en interne

**DÉCISIONS & RÉPONSES**

> **Montants affichés au client :** XAF avec décimales à 2 chiffres ---
> PAS d\'arrondi
>
> **Calculs internes :** OUI --- les décimales peuvent être conservées
> avant l\'arrondi final
>
> **Mode d\'arrondi :** AUCUN --- les décimales sont conservées
> intégralement
>
> **Moment de l\'arrondi :** NON APPLICABLE --- aucun arrondi appliqué
>
> **Ajustement dernière échéance :** AUCUN --- il n\'existera pas de
> différences d\'arrondi

**CHAMPS DE DÉCISION**

> **▸ Précision côté client :** Montant avec décimales tel que généré
> par le système
>
> **▸ Précision des calculs internes :** Aucune précision spécifique
>
> **▸ Mode d\'arrondi :** Aucun
>
> **▸ Moment de l\'arrondi :** Aucun
>
> **▸ Ajustement de la dernière échéance :** Aucun ajustement

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **2. MÉTHODE DE CALCUL DES INTÉRÊTS**

**CE QUE CELA SIGNIFIE**

La méthode de calcul des intérêts détermine combien le client paie pour
l\'emprunt. Le même montant et le même taux peuvent produire des totaux
très différents selon la méthode choisie.

**ILLUSTRATION**

> Prêt : principal = 100 000 XAF · taux = 10% · durée = 10 mois
>
> Intérêts à taux fixe : intérêts = 100 000 × 10% = 10 000 XAF →
> remboursement total = 110 000 XAF
>
> Solde dégressif : intérêts calculés sur capital restant dû à chaque
> période
>
> Annuité : même échéance totale, parts capital/intérêts variables dans
> le temps

**DÉCISIONS & RÉPONSES**

> **Méthode retenue :** INTÉRÊT À TAUX FIXE SUR LE CAPITAL INITIAL

**CHAMPS DE DÉCISION**

> **▸ Type de crédit de base :** Crédit amortissable
>
> **▸ Méthode de calcul des intérêts :** Intérêt à taux fixe
>
> **▸ Base de calcul :** Sur le capital initial
>
> **▸ Formule :** Capital initial × Taux d\'intérêt / Durée

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **3. CONVENTION DE DÉCOMPTE DES JOURS**

**CE QUE CELA SIGNIFIE**

Si les intérêts sont calculés par jour, le système doit savoir comment
convertir les jours en intérêts. Un mois peut être traité comme 30
jours, les jours calendaires réels, ou une autre convention.

**ILLUSTRATION**

> Principal = 100 000 XAF · taux annuel = 12% · période = 15 jours
>
> Réel/365 : intérêts = 100 000 × 12% × 15 / 365
>
> Réel/360 : intérêts = 100 000 × 12% × 15 / 360
>
> 30/360 : chaque mois = 30 jours, chaque année = 360 jours

**DÉCISIONS & RÉPONSES**

> **Convention de décompte des jours :** 360 JOURS
>
> **Date de valeur proposée :** 5 jours après la date d\'opération

**CHAMPS DE DÉCISION**

> **▸ Convention de décompte des jours :** 360 jours
>
> **▸ Règle pour les années bissextiles :** Aucune règle spécifique
>
> **▸ Règle pour les mois partiels :** À préciser --- notion de mois
> partiels à contextualiser

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **4. MONTANT DE L\'ÉCHÉANCE**

**CE QUE CELA SIGNIFIE**

Le montant de l\'échéance est ce que le client est censé payer à chaque
date d\'échéance. Il peut inclure le capital, les intérêts, la taxe,
l\'assurance, les frais ou les pénalités selon la politique.

**ILLUSTRATION**

> Capital dû = 10 000 XAF \| Intérêts dus = 1 500 XAF \| Taxe = 0 XAF
>
> Montant de l\'échéance = 11 500 XAF
>
> Avec frais mensuels de 500 XAF → montant de l\'échéance = 12 000 XAF

**DÉCISIONS & RÉPONSES**

> **Montant égal à chaque période :** OUI
>
> **Composantes incluses :** (Capital × Taux d\'intérêt) / Durée + Taxes
>
> **Première / dernière échéance différente :** NON --- montant fixe
>
> **Différences d\'arrondi :** Il n\'y aura pas de différences
> d\'arrondi

**CHAMPS DE DÉCISION**

> **▸ Règle première/dernière échéance :** Montant fixe
>
> **▸ Règle d\'arrondi :** Aucune

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **5. RÉPARTITION CAPITAL ET INTÉRÊTS**

**CE QUE CELA SIGNIFIE**

Chaque remboursement doit être réparti entre capital et intérêts afin
que le système puisse réduire correctement le solde du prêt et rapporter
les revenus correctement.

**ILLUSTRATION**

> Paiement = 12 000 XAF \| Intérêts dus = 2 000 XAF \| Capital dû = 10
> 000 XAF
>
> Si payé intégralement : 2 000 XAF → intérêts \| 10 000 XAF → réduction
> du capital
>
> Si paiement partiel (7 000 XAF) : la règle d\'allocation décide de ce
> qui est payé en premier

**DÉCISIONS & RÉPONSES**

> **Réduction du capital restant dû après paiement :** OUI
>
> **Réduction du capital initial :** NON --- le capital initial ne peut
> pas être réduit
>
> **Paiement partiel :** La retenue du capital est prioritaire, suivie
> de la retenue des intérêts

**CHAMPS DE DÉCISION**

> **▸ Moment du calcul des intérêts :** À la mise en place du prêt
>
> **▸ Règle de réduction du capital :** Le capital initial ne peut pas
> être réduit
>
> **▸ Comportement en cas de paiement partiel :** Retenue du capital
> prioritaire, puis intérêts

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **6. FRAIS DE DOSSIER / DEMANDE**

**CE QUE CELA SIGNIFIE**

Les frais de dossier sont des charges pour le traitement d\'un prêt. Le
système doit savoir comment ils sont calculés et quand ils sont
collectés.

**ILLUSTRATION**

> Frais fixes : 5 000 XAF
>
> Frais en % : prêt = 200 000 XAF · taux = 2% → frais = 4 000 XAF
>
> Déduits du décaissement : prêt approuvé = 200 000 XAF · frais = 5 000
> XAF → client reçoit 195 000 XAF

**DÉCISIONS & RÉPONSES**

> **Calcul des frais :** 3% DU MONTANT DU CAPITAL INITIAL
>
> **Événement déclencheur :** Facturé au décaissement du prêt
>
> **Mode de paiement :** Payé séparément (espèces / cash)
>
> **Remboursement si rejet :** Non remboursable --- un dossier arrivé au
> stade de mise en place a déjà été validé
>
> **⚠ NOTE :** Cas exceptionnel à voir (ex. : si lors de la mise en
> place...) --- à préciser ultérieurement.

**CHAMPS DE DÉCISION**

> **▸ Formule :** Capital accordé × 3%
>
> **▸ Événement déclencheur :** Validation en comité de crédit
>
> **▸ Mode de paiement :** Espèces / cash
>
> **▸ Traitement comptable :** Automatisé par le système --- la mise en
> place entraîne directement l\'écriture comptable

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **7. TVA / TAXE**

**CE QUE CELA SIGNIFIE**

Des taxes peuvent s\'appliquer aux intérêts, frais, assurances ou autres
charges. Le système doit connaître la base et le moment de calcul de la
taxe.

**ILLUSTRATION**

> Taxe sur les frais : frais = 5 000 XAF · taux = 19,25% → taxe = 962,5
> XAF (avant arrondi)
>
> Taxe sur les intérêts : intérêts = 2 000 XAF → taxe = 385 XAF

**DÉCISIONS & RÉPONSES**

> **Taux applicable :** 19,25% (taux légal)
>
> **Assiette de taxation :** Capital + Intérêts
>
> **Calcul en amont ou par échéance :** EN AMONT
>
> **Arrondi :** Pas d\'arrondi

**CHAMPS DE DÉCISION**

> **▸ Taux de taxe :** 19,25%
>
> **▸ Moment du calcul :** En amont
>
> **▸ Règle d\'arrondi :** Aucune
>
> **▸ Traitement comptable :** Automatisé par le système --- la mise en
> place entraîne directement l\'écriture comptable

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **8. ASSURANCE**

**CE QUE CELA SIGNIFIE**

L\'assurance prêt peut être fixe ou basée sur le montant du prêt. Le
système doit savoir quand elle est facturée et si elle peut être
remboursée.

**ILLUSTRATION**

> Assurance en % : montant du prêt = 200 000 XAF · taux = 1% → assurance
> = 2 000 XAF
>
> Assurance mensuelle : 500 XAF par échéance

**DÉCISIONS & RÉPONSES**

> **Type :** Pourcentage fixe
>
> **Base :** Basée sur le montant accordé
>
> **Moment :** Payée en amont
>
> **Remboursable à la clôture anticipée :** NON

**CHAMPS DE DÉCISION**

> **▸ Formule :** Montant accordé × 2%
>
> **▸ Moment :** En amont
>
> **▸ Règle de remboursement :** Non remboursable
>
> **▸ Traitement comptable :** Automatisé par le système --- la mise en
> place entraîne directement l\'écriture comptable

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **9. DÉPÔT DE GARANTIE**

**CE QUE CELA SIGNIFIE**

Un dépôt de garantie est de l\'argent retenu en garantie du prêt. Il
peut être collecté, retenu, libéré ou utilisé pour régler des soldes
impayés.

**ILLUSTRATION**

> Montant du prêt = 300 000 XAF · dépôt de garantie = 10% → dépôt requis
> = 30 000 XAF
>
> Solde compte = 80 000 XAF · retenue = 30 000 XAF → solde disponible =
> 50 000 XAF

**DÉCISIONS & RÉPONSES**

> **Type :** Pourcentage fixe --- 10%
>
> **Mode de collecte :** Peut être payé en espèces ou déduit (dépôt
> prêté ou dépôt payé)
>
> **Libéré à la clôture :** OUI
>
> **Peut régler des prêts impayés :** NON

**CHAMPS DE DÉCISION**

> **▸ Formule :** Montant accordé × 10%
>
> **▸ Base :** Montant accordé
>
> **▸ Mode de collecte :** Espèces
>
> **▸ Règle de libération :** Payé en totalité à la libération
>
> **▸ Règle d\'utilisation/compensation :** À la dernière échéance
>
> **▸ Traitement comptable :** Automatique

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **10. FORMULE DE PÉNALITÉ**

**CE QUE CELA SIGNIFIE**

Les pénalités s\'appliquent lorsque le remboursement est en retard. Le
système doit savoir quand elles commencent, sur quel montant elles sont
basées et si elles s\'accumulent.

**ILLUSTRATION**

> Date d\'échéance = 10 avril · délai de grâce = 3 jours → pénalité
> commence le 14 avril
>
> Pénalité journalière 1% : retard = 20 000 XAF · 5 jours → pénalité = 1
> 000 XAF
>
> Pénalité fixe : 2 000 XAF une fois en retard

**DÉCISIONS & RÉPONSES**

> **Déclencheur :** Tous les 26 du mois (soit 5 jours après la date
> d\'échéance qui est le 20)
>
> **Base de la pénalité :** 5 000 XAF fixe + 2% du montant impayé
> (hybride)
>
> **Fréquence :** Mensuelle
>
> **Composition :** 5 000 + 2% du montant impayé
>
> **Plafond :** Déterminé par le PAR --- après 90 jours le crédit passe
> en CTX
>
> **⚠ NOTE :** Le système NE DOIT PAS pénaliser les montants inférieurs
> à 1 000 XAF.

**CHAMPS DE DÉCISION**

> **▸ Déclencheur :** Clôture de la journée comptable du 25
>
> **▸ Jours de grâce :** 5 jours
>
> **▸ Formule :** 5 000 + 2% du montant impayé
>
> **▸ Fréquence :** Mensuelle
>
> **▸ Règle de capitalisation :** À préciser
>
> **▸ Règle d\'arrondi :** Aucune
>
> **▸ Traitement comptable :** Automatique

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **11. ARRIÉRÉS / MONTANT IMPAYÉ**

**CE QUE CELA SIGNIFIE**

Les arriérés représentent les montants non payés à leur date
d\'échéance. Le système doit décider comment les paiements partiels
affectent les arriérés.

**ILLUSTRATION**

> Échéance due = 20 000 XAF · client paie = 12 000 XAF → reste impayé =
> 8 000 XAF
>
> Statut : partiellement_payé_en_retard · arriérés = 8 000 XAF

**DÉCISIONS & RÉPONSES**

> **Qu\'est-ce qui rend une échéance en retard :** Lorsque le client
> n\'a pas payé la totalité à la date attendue
>
> **Classification des paiements partiels :** Il n\'y a pas de
> classification
>
> **Calcul du montant total impayé :** Échéance due − Montant versé

**CHAMPS DE DÉCISION**

> **▸ Règle de retard :** J+5
>
> **▸ Règle de paiement partiel :** Versement libre du client

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **12. ORDRE D\'ALLOCATION DES REMBOURSEMENTS**

**CE QUE CELA SIGNIFIE**

Lorsqu\'un client paie moins que le montant total dû, le système doit
décider ce qui est payé en premier.

**ILLUSTRATION**

> Pénalité = 1 000 \| Taxe = 500 \| Intérêts = 4 000 \| Capital = 15 000
> \| Total = 20 500 XAF
>
> Paiement = 10 000 XAF
>
> Option A : pénalité → taxe → intérêts → capital
>
> Option B : intérêts → capital → pénalité

**DÉCISIONS & RÉPONSES**

> **Composante payée en premier :** CAPITAL
>
> **Échéances les plus anciennes en priorité :** OUI
>
> **Paiements multiples le même jour :** Même ordre
>
> **Trop-payé :** La différence reste dans le compte du client

**CHAMPS DE DÉCISION**

> **▸ Gestion des trop-payés :** La différence reste dans le compte du
> client

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **13. DÉLAI DE GRÂCE**

**CE QUE CELA SIGNIFIE**

Un délai de grâce peut différer le remboursement du capital, le début
des pénalités, ou les deux. Les intérêts peuvent continuer à courir.

**DÉCISIONS & RÉPONSES**

> **Capital différé :** NON
>
> **Intérêts courent-ils :** OUI
>
> **Intérêts payés pendant le délai :** OUI
>
> **Intérêts capitalisés :** NON
>
> **Pénalités désactivées :** OUI

**CHAMPS DE DÉCISION**

> **▸ Comportement du capital :** Statique
>
> **▸ Comportement des intérêts :** Statique
>
> **▸ Comportement des pénalités :** Inexistantes
>
> **▸ Impact sur l\'échéancier :** Aucun impact

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **14. INTÉRÊTS CAPITALISÉS --- À PRÉCISER**

**CE QUE CELA SIGNIFIE**

Les intérêts capitalisés signifient que les intérêts impayés sont
ajoutés au capital du prêt. Les intérêts futurs peuvent alors être
calculés sur le solde plus élevé.

**ILLUSTRATION**

> Capital = 100 000 XAF · intérêts impayés = 5 000 XAF
>
> Après capitalisation : nouveau capital = 105 000 XAF

**POSITION DE L\'ÉQUIPE OPÉRATIONNELLE**

> **⚠ NOTE :** Étant donné que le capital et les intérêts sont fixes, il
> est proposé de nommer cette notion : « IMPAYÉS CAPITALISÉS ». Si un
> client n\'a pas réglé une échéance du mois X, au mois Y --- s\'il n\'a
> toujours pas versé son dû --- le montant impayé sera actualisé et les
> pénalités y seront appliquées.

**CHAMPS DE DÉCISION --- À PRÉCISER**

> **▸ Déclencheur de capitalisation :** À préciser
>
> **▸ Formule :** À préciser
>
> **▸ Base des intérêts futurs :** À préciser
>
> **▸ Traitement des pénalités :** À préciser
>
> **▸ Traitement comptable :** À préciser

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **15. REMBOURSEMENT ANTICIPÉ**

**CE QUE CELA SIGNIFIE**

Le remboursement anticipé se produit lorsqu\'un client paie avant la
date de fin prévue du prêt. Le système doit savoir si les intérêts
futurs sont dispensés ou toujours collectés.

**ILLUSTRATION**

> Capital restant = 80 000 XAF · intérêts futurs prévus = 12 000 XAF
>
> Option 1 : payer 80 000 XAF + intérêts courus actuels
>
> Option 2 : payer 80 000 XAF + tous les intérêts futurs
>
> Option 3 : payer 80 000 XAF + frais de clôture anticipée

**DÉCISIONS & RÉPONSES**

> **Remboursement anticipé autorisé :** OUI (de préférence 3 mois après
> le décaissement)
>
> **Intérêts futurs dispensés :** NON (négociable sur accord de la
> Direction selon les caractéristiques du crédit)
>
> **Frais de remboursement anticipé :** NON
>
> **Assurance remboursée :** NON
>
> **Dépôt de garantie libéré immédiatement :** OUI --- en cas de solde
> complet des sommes dues

**PRÉCISION SUPPLÉMENTAIRE**

> **⚠ NOTE :** AUTOMATISER TOUTES LES RÉCUPÉRATIONS : priorité de
> retrait du compte de crédit ; si incomplet, automatiser le retrait sur
> tout autre compte du client. Cela suppose que tous les clients soient
> rattachés à leurs différents comptes. Identification client proposée
> par code.

**CHAMPS DE DÉCISION**

> **▸ Remboursement anticipé autorisé :** OUI
>
> **▸ Règle des intérêts futurs :** NON (négociable sur accord de la
> Direction)
>
> **▸ Règle des frais :** Aucun frais
>
> **▸ Règle de remboursement de l\'assurance :** Pas de remboursement
>
> **▸ Règle de libération de la garantie :** Libérable au moment du
> solde du crédit
>
> **▸ Traitement comptable :** Configuration automatique

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **16. RÉÉCHELONNEMENT / REFINANCEMENT**

**CE QUE CELA SIGNIFIE**

Le rééchelonnement modifie le plan de remboursement. Le refinancement
peut clôturer un ancien prêt et en créer un nouveau.

**DÉCISIONS & RÉPONSES**

> **Rééchelonnement autorisé :** OUI
>
> **Nouveau prêt ou même prêt :** Le rééchelonnement MODIFIE LE MÊME
> PRÊT
>
> **Intérêts/pénalités capitalisés :** OUI
>
> **Approbation requise :** OUI --- le dossier repasse en comité de
> crédit

**CHAMPS DE DÉCISION**

> **▸ Rééchelonnement autorisé :** OUI
>
> **▸ Nouveau prêt ou même prêt :** Même prêt
>
> **▸ Règle de capitalisation :** Intérêts + Pénalités
>
> **▸ Flux d\'approbation :** Le dossier repasse en comité de crédit
>
> **▸ Traitement comptable :** Configuration automatique

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **17. SOLDE COMPTABLE**

**CE QUE CELA SIGNIFIE**

Le solde comptable doit être le solde dérivé du grand livre d\'un
compte. Les parties prenantes doivent confirmer si les transactions en
attente sont exclues.

**NOTE IMPORTANTE**

> **⚠ NOTE :** Besoin de préciser les types de comptes : Comptes de
> récupération · Comptes d\'épargne ordinaires

**DÉCISIONS & RÉPONSES**

> **Solde issu des écritures de grand livre enregistrées :** OUI ---
> écritures saisies et validées
>
> **Transactions en attente :** Bien vouloir préciser ce que signifie «
> transaction en attente »
>
> **Affichage des contrepassations :** Uniquement sur l\'interface de
> traitement interne

**CHAMPS DE DÉCISION**

> **▸ Formule du solde comptable :** À définir
>
> **▸ Statuts inclus :** À définir
>
> **▸ Gestion des contrepassations :** À définir

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **18. SOLDE DISPONIBLE**

**CE QUE CELA SIGNIFIE**

Le solde disponible est ce que le client peut utiliser. Il s\'agit
généralement du solde comptable moins les retenues, les fonds
indisponibles et les restrictions en attente.

**ILLUSTRATION**

> Solde comptable = 100 000 XAF · retenue dépôt de garantie = 30 000 XAF
> · retrait en attente = 10 000 XAF
>
> Solde disponible = 60 000 XAF

**DÉCISIONS & RÉPONSES**

> **Solde minimum :** 5 000 XAF pour les comptes d\'épargne (politique à
> définir par la Direction) --- 0 pour les comptes courants
>
> **Restrictions liées au prêt réduisent la disponibilité :** OUI
>
> **Retraits en attente :** À préciser

**CHAMPS DE DÉCISION**

> **▸ Formule du solde disponible :** Solde comptable − Minimum en
> compte
>
> **▸ Types de retenues :** Selon le type de compte
>
> **▸ Règle du solde minimum --- Épargne :** 5 000 XAF (à définir par la
> Direction)
>
> **▸ Règle du solde minimum --- Courant :** 0 XAF
>
> **▸ Règle des transactions en attente :** À préciser

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **19. MOUVEMENTS JOURNALIERS ET CUMULATIFS**

**CE QUE CELA SIGNIFIE**

Les rapports montrent les mouvements débit/crédit journaliers et
cumulatifs. Les parties prenantes doivent décider si les rapports
utilisent la date de transaction ou la date de comptabilisation.

**DÉCISIONS & RÉPONSES**

> **Date de transaction vs. date de comptabilisation :** À
> contextualiser
>
> **Contrepassations :** Seulement sur l\'interface interne
>
> **Clôture journalière :** C\'est le BATCH qui clôture la journée

**CHAMPS DE DÉCISION**

> **▸ Formule du mouvement journalier :** À contextualiser
>
> **▸ Formule du mouvement cumulatif :** À contextualiser
>
> **▸ Gestion des contrepassations :** En interne

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **20. BILLETS DE BANQUE ET PIÈCES / BILLETAGE**

**CE QUE CELA SIGNIFIE**

Gestion des billets de banque et des pièces via la configuration des
dénominations et le comptage des espèces. Utilisé lors de
l\'ouverture/clôture des caisses et du rapprochement des espèces
réelles.

**ILLUSTRATION**

> 10 billets de 10 000 XAF = 100 000 XAF
>
> 5 billets de 5 000 XAF = 25 000 XAF
>
> 20 pièces/billets de 500 XAF = 10 000 XAF → total espèces réelles =
> 135 000 XAF
>
> Formule : total_ligne = valeur_dénomination × quantité

**DÉCISIONS & RÉPONSES**

> **Dénominations acceptées :** TOUTES
>
> **Pièces suivies :** OUI
>
> **Billets endommagés suivis séparément :** NON --- acceptés
>
> **Dénominations désactivables :** NON
>
> **Comptage requis :** À l\'ouverture ET à la clôture
>
> **⚠ NOTE :** Bien vouloir configurer l\'interface de fermeture de
> caisse.

**CHAMPS DE DÉCISION**

> **▸ Dénominations acceptées :** Toutes
>
> **▸ Suivi des pièces :** OUI
>
> **▸ Règle pour les espèces endommagées :** Acceptées
>
> **▸ Comptage à l\'ouverture :** OUI
>
> **▸ Comptage à la clôture :** OUI

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **21. SOLDE THÉORIQUE DE LA CAISSE**

**CE QUE CELA SIGNIFIE**

Le solde théorique est ce que le système pense devoir se trouver dans le
tiroir-caisse du caissier en fonction des mouvements d\'espèces
enregistrés.

**ILLUSTRATION**

> Ouverture = 100 000 XAF · dépôts = 80 000 XAF · retraits = 30 000 XAF
> → solde théorique = 150 000 XAF

**DÉCISIONS & RÉPONSES**

> **Seules les transactions enregistrées incluses :** OUI
>
> **Transactions en attente :** À notifier sur l\'état de fermeture de
> caisse
>
> **Transferts entre caisses :** Le caissier produit un bordereau
> d\'approvisionnement ; le comptable passe l\'écriture après
> approbation de la Direction

**CHAMPS DE DÉCISION**

> **▸ Formule du solde d\'ouverture :** Solde initial + Entrées −
> Sorties (le solde d\'ouverture de J doit être égal au solde de
> fermeture de J−1)
>
> **▸ Formule du solde théorique :** Solde d\'ouverture + Dépôts −
> Retraits
>
> **▸ Traitement des transactions en attente :** À préciser sur l\'état
> de fermeture
>
> **▸ Moment du transfert entre caisses :** Lors des approvisionnements

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **22. DIFFÉRENCE DE RAPPROCHEMENT DE CAISSE**

**CE QUE CELA SIGNIFIE**

À la clôture, le caissier compte les espèces physiques. Le système
compare les espèces comptées au solde théorique.

**ILLUSTRATION**

> Solde théorique = 150 000 XAF · espèces comptées = 148 500 XAF →
> différence = −1 500 XAF
>
> Formule : différence = total_espèces_réelles − solde_théorique

**DÉCISIONS & RÉPONSES**

> **Tolérance autorisée :** AUCUNE TOLÉRANCE --- exigence réglementaire
>
> **Approbation des manques/excédents :** Il ne doit pas y en avoir
>
> **Clôture avec différences non résolues :** LE SYSTÈME NE DOIT PAS
> L\'AUTORISER (sauf écarts liés aux transactions en attente)

**CHAMPS DE DÉCISION**

> **▸ Tolérance :** ZÉRO
>
> **▸ Rôle d\'approbation :** Aucune approbation des écarts
>
> **▸ Traitement comptable des manques :** Aucun
>
> **▸ Traitement comptable des excédents :** Aucun
>
> **▸ Règle de clôture avec différence :** Le système ne doit pas
> l\'autoriser

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **23. ENCOURS DU PORTEFEUILLE**

**CE QUE CELA SIGNIFIE**

L\'encours du portefeuille est utilisé pour la gestion et les rapports.
Il doit être clair si cela signifie uniquement le capital ou inclut les
intérêts/pénalités.

**DÉCISIONS & RÉPONSES**

> **Composition :** Capital + Intérêts + Pénalités
>
> **Prêts radiés inclus :** NON
>
> **Prêts rééchelonnés :** Restent dans le portefeuille original ---
> seules les dates d\'échéances changent, pas les montants

**CHAMPS DE DÉCISION**

> **▸ Formule :** Somme des dettes et remboursements impayés (à préciser
> selon le portefeuille)
>
> **▸ Statuts inclus :** Portefeuille sain · Portefeuille impayé ·
> Portefeuille radié ou en perte
>
> **▸ Règles de regroupement :** Portefeuille original

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **24. PORTEFEUILLE À RISQUE / DÉLINQUANCE**

**CE QUE CELA SIGNIFIE**

Le portefeuille à risque mesure les prêts avec des échéances en retard.
Les tranches communes sont PAR30, PAR60 et PAR90.

**ILLUSTRATION**

> Prêt avec une échéance en retard de 35 jours → capital restant dû =
> 200 000 XAF → PAR30 inclut 200 000 XAF

**DÉCISIONS & RÉPONSES**

> **Tranches PAR utilisées :** PAR 30
>
> **Base du calcul :** MONTANT EN RETARD
>
> **Prêts radiés ou rééchelonnés inclus :** NON

**CHAMPS DE DÉCISION**

> **▸ Tranches PAR :** 30 jours
>
> **▸ Formule :** Montant en retard / impayés
>
> **▸ Traitement des prêts restructurés :** Non inclus

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **25. PERFORMANCE DE RECOUVREMENT**

**CE QUE CELA SIGNIFIE**

La performance de recouvrement compare ce qui aurait dû être collecté à
ce qui a été réellement collecté.

**ILLUSTRATION**

> Capital prévu = 300 000 XAF · intérêts prévus = 50 000 XAF →
> recouvrement attendu = 350 000 XAF
>
> Remboursements réels = 280 000 XAF → performance = 280 000 / 350 000 =
> 80%

**DÉCISIONS & RÉPONSES**

> **Pénalités incluses dans le recouvrement attendu :** OUI
>
> **Frais inclus :** Aucun frais appliqué
>
> **Paiements partiels comptabilisés immédiatement :** OUI
>
> **Espèces et débits de compte inclus :** OUI

**CHAMPS DE DÉCISION**

> **▸ Formule de recouvrement attendu :** Capital prévu + Intérêts
> prévus
>
> **▸ Formule de recouvrement réel :** Recouvrement attendu −
> Recouvrement reconnu
>
> **▸ Règles d\'inclusion/exclusion :** Exclusion après paiement total

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **26. GESTION DES RESSOURCES HUMAINES**

**OBJECTIF**

Le progiciel de gestion de la microfinance doit intégrer un véritable
module RH centralisé relié à tous les autres modules du système
(comptabilité, caisse, crédits, sécurité, administration et reporting).

L\'objectif est de permettre à la Direction de gérer automatiquement :

> • Fiches descriptives des employés
>
> • Contrats de travail
>
> • Salaires
>
> • Congés et absences
>
> • Sanctions
>
> • Avances sur salaires
>
> • Retenues
>
> • Déclarations sociales
>
> • Interface d\'insertion des versions scannées des pièces physiques
> des dossiers RH
>
> • Toutes les écritures comptables liées au personnel

**GESTION DES DOSSIERS DU PERSONNEL**

Chaque employé doit disposer d\'une fiche numérique complète contenant :

> • Matricule automatique
>
> • Nom et prénom · Photo · CNI · Contacts
>
> • Poste · Service · Agence d\'affectation
>
> • Date d\'embauche · Type de contrat · Salaire de base
>
> • Historique professionnel · Personnes à contacter en cas d\'urgence

Le système doit permettre : le scan et stockage des documents,
l\'archivage automatique, la recherche rapide des employés.

**GESTION DES CONTRATS**

> • Générer automatiquement les contrats de travail
>
> • Gérer les CDD et CDI
>
> • Suivre les dates d\'expiration et envoyer des alertes avant
> expiration
>
> • Conserver l\'historique des modifications

**GESTION AUTOMATISÉE DE LA PAIE**

Le module doit générer automatiquement les bulletins de paie, états de
salaires, journaux de paie, virements bancaires et retenues salariales.

Le calcul doit respecter la réglementation camerounaise : CNPS · IRPP ·
CAC · Centimes additionnels · Acomptes · Avances · Primes · Indemnités ·
Heures supplémentaires · Absences · Sanctions.

**GESTION DES PRÉSENCES ET CONGÉS**

> • Pointage · Retards · Absences · Permissions
>
> • Congés annuels · Congés maladie · Congés maternité
>
> • Validation hiérarchique · Calendrier des congés · Alertes
> automatiques

**GÉNÉRATION AUTOMATIQUE DES ATTESTATIONS**

> • Attestations de travail et de prise de service
>
> • Décisions de congé · Lettres d\'avertissement · Licenciements
>
> • Lettres d\'admission en stage · Certificats administratifs

**LIAISON OBLIGATOIRE AVEC LA COMPTABILITÉ**

Chaque paie validée doit automatiquement générer les écritures
comptables correspondantes : salaires, charges patronales, charges CNPS,
impôts, provisions, avances au personnel.

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **27. BANCASSURANCE**

**PRODUITS D\'ASSURANCE**

Moyens permettant de se prémunir d\'un risque futur moyennant une prime
versée à l\'assureur qui se porte garant de couvrir le sinistre subi par
l\'assuré.

> • Assurance emprunteur (décès, invalidité, incapacité de
> remboursement)
>
> • Assurance santé (hospitalisation, maladie, consultations médicales,
> maternité)
>
> • Assurance vie (décès, constitution d\'épargne, protection familiale)
>
> • Assurance épargne (épargne sécurisée, décès, retraite)
>
> • Assurance agricole (sécheresse, inondation, perte de récolte,
> mortalité du bétail)
>
> • Assurance habitation (incendie, dégâts des eaux, catastrophe
> naturelle, vol)
>
> • Assurance commerce / multirisque professionnelle (incendie, vol,
> perte de stock)
>
> • Assurance automobile / moto (accident, vol, dommages matériels, RC)
>
> • Assurance scolaire (accident scolaire, frais médicaux, invalidité)
>
> • Assurance voyage (maladie, accident, annulation, perte de bagages)
>
> • Assurance funéraire (frais d\'obsèques, assistance familiale)
>
> • Assurance mobile / équipements (casse, vol, perte, dommages
> électroniques)

**INTÉGRATION DANS LE PROGICIEL**

Menu principal → Module : Assurance · Sous-menus :

> • Produits d\'assurance
>
> • Souscriptions
>
> • Paiement des primes
>
> • Sinistres
>
> • Partenaires assureurs
>
> • Rapports assurance

**EXEMPLE --- PRODUIT : ASSURANCE EMPRUNTEUR**

> **▸ Code produit :** ASS
>
> **▸ Compagnie partenaire :** AXA Assurance
>
> **▸ Type :** Assurance crédit
>
> **▸ Risques couverts :** Décès · Invalidité
>
> **▸ Prime :** 1,5% du montant du crédit
>
> **▸ Mode de paiement :** Déduction automatique

**GESTION DES SINISTRES**

> • Client concerné · Produit d\'assurance · Type de sinistre · Date
> incident · Documents joints
>
> • Statut dossier : En attente · Validé · Rejeté · Indemnisé

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **28. CHANGE DE DEVISES**

**PROCESSUS SIMPLIFIÉ DE MISE EN PLACE DU SERVICE DE CHANGE MANUEL**

Le change dans une microfinance est manuel. La transaction se fait
immédiatement entre le client et la microfinance.

> • Le client se présente à l\'agence avec une pièce d\'identité et la
> devise à changer
>
> • Le responsable vérifie l\'identité du client et l\'authenticité des
> billets
>
> • Il applique le cours affiché (à la vente ou à l\'achat) --- les taux
> sont obligatoirement affichés
>
> • L\'opération est enregistrée dans le logiciel (nom, devise, taux,
> montant) → bordereau de change généré
>
> • L\'opération est notée dans un registre de change

En fin de journée, les stocks de devises sont vérifiés et les excédents
peuvent être revendus à une banque partenaire. En cas de manque, la
microfinance se réapprovisionne auprès de sa banque partenaire.

**DEVISES LES PLUS COURANTES**

> • FCFA (monnaie locale)
>
> • Euro et Dollar américain
>
> • Autres devises selon la demande
>
> **⚠ NOTE :** La microfinance doit disposer d\'une caisse séparée de la
> caisse principale : la CAISSE MULTI-DEVISES.

**MARGES APPLIQUÉES**

  ------------------------------------------- ---------------------------
  **TYPES**                                   **POURCENTAGE**

  Commission d\'achat client (Euro)           **2%**

  Commission de vente client (Euro)           **5%**

  Commission d\'achat client (autres devises) **5%**

  Commission de vente client (autres devises) **5%**
  ------------------------------------------- ---------------------------

**MISE EN PLACE DANS LE PROGICIEL**

> • Configuration multi-devises : définir le FCFA comme monnaie de
> référence et ajouter toutes les autres devises
>
> • Configuration des taux : saisie manuelle des taux d\'achat et de
> vente (modifiable au jour le jour)
>
> • Transaction de change : interface pour enregistrer l\'opération
> (nom, devise, montant, taux) --- calcul automatique et bordereau
> généré
>
> • Gestion de la caisse multi-devises : comptes en caisses distincts
> par devise pour suivre les entrées/sorties en temps réel
>
> • Paramétrage du taux avec la marge : taux de référence saisi
> manuellement · règle de calcul automatique des taux d\'achat et de
> vente

**ILLUSTRATION DU CALCUL**

> Taux du marché (Dollar) : 500 \| Marge : 5%
>
> Taux de vente : 500 + 25 = 525 \| Taux d\'achat : 500 − 25 = 475
>
> À chaque opération : afficher le taux client réellement appliqué ·
> calculer le montant en FCFA · afficher la marge réalisée

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **29. FINANCE ISLAMIQUE**

**INTRODUCTION**

La finance islamique est un système financier fondé sur les principes de
la Charia. Elle se distingue de la finance classique par l\'interdiction
de l\'intérêt (riba) et par l\'obligation de relier les opérations
financières à des activités économiques réelles.

**I. PRINCIPES FONDAMENTAUX**

> • Interdiction de l\'intérêt (riba) --- le profit doit provenir d\'une
> opération économique réelle
>
> • Interdiction de l\'incertitude excessive et de la spéculation ---
> contrats clairs, précis et transparents
>
> • Partage des pertes et des profits --- bénéfices et pertes répartis
> selon des proportions contractuelles
>
> • Licéité des activités financées --- aucun financement d\'activités
> interdites par la Charia
>
> • Adossement à l\'économie réelle --- chaque opération liée à un bien,
> service ou projet concret

**II. PROCÉDURE DE MISE EN PLACE**

> • Définir clairement les objectifs de l\'offre islamique (comptes,
> produits de financement, partenariats)
>
> • Mettre en place un cadre de conformité Charia (spécialistes ou
> comité dédié)
>
> • Former le personnel sur les différences avec la finance classique
>
> • Adapter les contrats (nature de l\'opération, droits, obligations,
> marge, garanties, partage des bénéfices/pertes)
>
> • Sensibiliser la clientèle sur les principes, avantages et
> fonctionnement

**III. PRINCIPAUX PRODUITS**

Mourabaha --- Contrat de vente avec marge bénéficiaire. La microfinance
achète un bien puis le revend au client à un prix incluant une marge
connue à l\'avance.

Ijara / Ijara wa Iqtina --- Contrat de location (avec option d\'achat).
Adapté au financement de véhicules, machines, équipements agricoles ou
professionnels.

Salam --- Vente à terme : la microfinance paie à l\'avance une
marchandise livrée plus tard. Utile pour les activités agricoles ou
commerciales.

Istisna\'a --- Contrat portant sur la fabrication ou construction d\'un
bien qui n\'existe pas encore. Convient aux projets de construction ou
fabrication sur commande.

Moudaraba --- Partenariat : la microfinance apporte les fonds, le client
apporte son expertise. Bénéfices partagés selon le contrat ; pertes à la
charge de l\'investisseur (sauf faute du gestionnaire).

Moucharaka --- Partenariat avec participation au capital. Pertes
réparties selon la participation de chacun.

**IV. LES DIFFÉRENTS COMPTES**

> • Compte courant islamique --- dépôts, retraits, paiements sans
> rémunération par intérêt
>
> • Compte d\'épargne islamique --- rémunération liée aux bénéfices
> réalisés sur des activités licites
>
> • Compte d\'investissement islamique --- rendement variable selon les
> résultats des investissements

**IMPLÉMENTATION DANS LE PROGICIEL**

> • Paramétrage des produits : type, marge, durée, actif financé,
> échéancier
>
> • Paramétrage comptable : comptes Mourabaha, Ijara, investissements,
> Zakat
>
> • Contrôle Charia : validation par le Sharia Board · blocage des
> opérations Haram · contrôle des intérêts
>
> • Traçabilité des actifs financés

**EXEMPLES DE CALCUL**

> Mourabaha : Achat = 100 000 XAF + Majoration (transport, recherche) =
> 20 000 XAF → Prix de remboursement = 120 000 XAF
>
> Ijara : Achat = 250 000 XAF · Durée = 5 mois · Paiement mensuel = 52
> 000 XAF · Résiduel = 30 000 XAF → Total = 290 000 XAF
>
> Moudaraba : Financement startup = 500 000 XAF · Durée = 5 ans ·
> Résultat prévisionnel / an = 200 000 XAF · Répartition : 60%
> microfinance / 40% entrepreneur
>
> Moucharaka : Capital = 500 000 XAF (250 000 chacun) · Bénéfice
> prévisionnel = 100 000 XAF/an · Partage : 70% startup / 30%
> microfinance

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

> **30. INTÉGRATION DU PLAN COMPTABLE DES EMF**

**EXIGENCES RÉGLEMENTAIRES**

Le progiciel doit intégrer le plan comptable réglementaire des
Établissements de Microfinance (EMF) applicable en zone CEMAC.

> • Contenir automatiquement tous les comptes comptables EMF
>
> • Empêcher les erreurs de codification
>
> • Permettre la création de sous-comptes
>
> • Relier chaque opération à une écriture comptable automatique

**LIAISON AUTOMATIQUE --- EXEMPLES D\'ÉCRITURES**

> • Décaissement crédit → compte client + compte caisse/banque
>
> • Dépôt épargne → caisse + compte épargne client

**CONTRÔLES ET SÉCURITÉ ATTENDUS**

> • Journaliser toutes les actions utilisateurs
>
> • Empêcher les suppressions frauduleuses
>
> • Gérer les droits d\'accès par profil
>
> • Produire automatiquement : Balance · Grand livre · Journaux · États
> COBAC · Rapports financiers · Rapports RH · Rapports de performance

**MODULES COMPLÉMENTAIRES DEMANDÉS**

> • SMS Banking
>
> • Alertes automatiques (notifications de remboursements, notifications
> RH)
>
> • Reporting automatique
>
> • Tableaux de bord de direction
>
> • Répertoires des codes / guide de codification (liste référentielle
> des codes et libellés définis pour chaque opération)

**✓ APPROUVÉ PAR : L\'ÉQUIPE OPÉRATIONNELLE**

**TABLEAU DE VALIDATION SUGGÉRÉ**

  -------------------------- ----------------- -------------- ---------------
  **DOMAINE**                **RESPONSABLE**   **STATUT**     **NOTES**

  Précision et arrondi en                      **En attente** 
  XAF                                                         

  Intérêts sur prêt                            **En attente** 

  Échéances                                    **En attente** 

  Frais et TVA                                 **En attente** 

  Assurance                                    **En attente** 

  Dépôt de garantie                            **En attente** 

  Pénalités                                    **En attente** 

  Allocation des                               **En attente** 
  remboursements                                              

  Délai de grâce /                             **En attente** 
  capitalisation                                              

  Remboursement anticipé                       **En attente** 

  Rééchelonnement /                            **En attente** 
  refinancement                                               

  Soldes des comptes                           **En attente** 

  Billets de banque et                         **En attente** 
  pièces                                                      

  Rapprochement de caisse                      **En attente** 

  Indicateurs de reporting                     **En attente** 
  -------------------------- ----------------- -------------- ---------------
