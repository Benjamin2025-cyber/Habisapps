# Guide de test — plan comptable consolidé & profil de l'institution

Document compagnon de [consolidated-chart-of-accounts.md](consolidated-chart-of-accounts.md),
qui explique *pourquoi* la fonctionnalité est construite ainsi. Le présent document est le
**script de test pas à pas** : suivez-le du début à la fin et vous aurez exercé toutes les
parties de la modification.

Écrit pour un non-comptable. Chaque étape indique ce qu'il faut faire, ce que vous devez
voir, et — quand cela compte — *pourquoi* la comptabilité fonctionne ainsi, afin que vous
puissiez distinguer une anomalie d'un comportement correct.

Branches : API `feat-entreprise`, frontend `feat-institution-level`.

---

## 0. L'idée en quatre-vingt-dix secondes

Un EMF possède plusieurs agences. Chaque agence a sa propre caisse, et le siège veut un
chiffre unique pour « toute la caisse de l'institution ». Le plan comptable est donc un
**arbre** :

```
571000  Caisse Globale          ← niveau entreprise. Un TOTAL. Ne porte aucune écriture.
├── 571001  Caisse HABIS Test   ← agence TEST-HABIS. Les écritures réelles arrivent ici.
└── 571002  Caisse Cookbook     ← agence AG-COOK-01. Les écritures réelles arrivent ici.
```

Trois notions suffisent :

| Terme | Signification |
|---|---|
| **Compte imputable** (compte de détail) | Un vrai compte. Les écritures s'y imputent. `571001`, `571002`. |
| **Compte de regroupement** | Un sous-total. Les écritures y sont **refusées**. Son solde est la somme de ses comptes fils. `571000`. |
| **Consolidé** | Lire le total d'un compte de regroupement au lieu de ses mouvements propres (toujours nuls). |

La règle unique qui explique presque tout ce que vous allez voir : **un total ne peut pas
lui-même recevoir de l'argent.** S'il le pouvait, vous compteriez deux fois les mêmes
100 F — une fois sur `571001`, une seconde fois sur `571000`. Le système le refuse donc, à
plusieurs endroits. Ces refus sont la fonctionnalité qui marche, pas qui casse.

**Ce que vous prouvez :** (1) l'arbre peut être construit depuis l'interface, (2) les
mauvaises manipulations sont refusées, (3) les chiffres tombent juste.

---

## 1. Avant de commencer

### 1.1 Déconnectez-vous puis reconnectez-vous — ne sautez pas cette étape

Les permissions sont mises en cache dans votre session au moment de la connexion. Les
nouvelles permissions (`institution.profile.view`, `ledger.scope.institution.manage`, …) ont
été ajoutées en base par le seeder, mais **votre session actuelle porte encore l'ancienne
liste**. Rien de nouveau n'apparaît avant une reconnexion.

> Si *Paramétrage › Institution* est absent du menu latéral, c'est la raison. C'est de loin
> la première cause de « la fonctionnalité n'est pas là ».

### 1.2 Préparez le banc de test

Une base fraîche ne contient **ni** l'administrateur plateforme, **ni** la seconde agence,
**ni** les journées comptables ouvertes, **ni** le chef comptable. Deux commandes les créent :

```bash
# L'administrateur plateforme est volontairement protégé par un drapeau : DatabaseSeeder
# le saute tant que SEED_BOOTSTRAP_ADMIN vaut false, ce qui est le défaut.
SEED_BOOTSTRAP_ADMIN=true php artisan db:seed --class=BootstrapAdminSeeder

php artisan db:seed --class=ConsolidatedChartBenchSeeder
```

> **On se connecte avec le numéro de téléphone, pas avec l'adresse e-mail.** L'API recherche
> l'utilisateur sur `phone_number` ; l'e-mail ne sert jamais à l'authentification. Les
> identifiants de l'administrateur sont ceux de vos variables `SEED_BOOTSTRAP_ADMIN_*`.
> Les comptes de test créés par le banc utilisent les numéros `+2376900000xx` listés en
> console à la fin du seeder.

Vous obtenez :

| | |
|---|---|
| Agences | `TEST-HABIS` — HABIS Test Agency · `AG-COOK-01` — Cookbook Test Agency |
| Journées comptables ouvertes | TEST-HABIS · AG-COOK-01 · INSTITUTION |
| Administrateur plateforme | `SEED_BOOTSTRAP_ADMIN_EMAIL` — connexion par `SEED_BOOTSTRAP_ADMIN_PHONE` |
| Chef comptable (siège, **sans agence**) | `test.chief.accountant@example.test` |
| Comptable d'agence | `test.cookbook.accountant@example.test` (agence **AG-COOK-01**) |
| Mot de passe des comptes de test | `password123` |

Le chef comptable est créé **sans aucune agence** : c'est volontaire et cela fait partie de
ce que vous testez — le siège n'est rattaché à aucune agence.

> Vous pouvez aussi créer ce compte à la main dans *Administration › Gestion des
> utilisateurs* (rôle **chief-accountant**, agence laissée vide). Si l'interface vous
> **oblige** à choisir une agence, c'est une anomalie — signalez-la.

### 1.3 Deux façons de suivre ce guide

| Vous voulez | Faites |
|---|---|
| **Valider l'interface** (première fois, ou les écrans de saisie ont changé) | Le banc de test ci-dessus, puis suivez le guide à partir du §3, à la main. |
| **Revalider le comportement** (après un correctif, avant une mise en production) | `php artisan db:seed --class=ConsolidatedChartDemoSeeder`, puis sautez directement au §5. |

Le second seeder construit l'arbre `571xxx` et comptabilise les deux écritures pour vous —
il produit exactement l'état final des §3 et §4, en quelques secondes au lieu de vingt
minutes de saisie. Il n'exerce évidemment **pas** l'interface : ne l'utilisez pas pour un
premier passage.

Les deux seeders sont idempotents et refusent de s'exécuter en environnement de production.

### 1.4 Montants et devise

Vous saisissez en **unités principales** ; le système stocke en unités mineures (×100).
Saisir `100` donne 100,00 XAF. Ce guide affiche toujours ce que vous devez saisir.

Tout le guide se déroule en **XAF uniquement**. Le multidevise n'est pas dans le périmètre de
ce test : ne changez pas la devise, et ne consolidez pas des comptes exprimés dans des
devises différentes.

### 1.5 Le parcours manuel ne se rejoue pas

**Le parcours manuel (§3 et §4) est à passage unique.** Un second passage entre en collision
sur les codes de comptes (`571000` est unique au niveau entreprise, `571001` unique dans son
agence), et l'étape 10 clôture la journée comptable de l'institution, que seul un
administrateur plateforme peut rouvrir. Pour le refaire, repartez d'une base neuve :

```bash
php artisan migrate:fresh --seed
SEED_BOOTSTRAP_ADMIN=true php artisan db:seed --class=BootstrapAdminSeeder
php artisan db:seed --class=ConsolidatedChartBenchSeeder
```

> `migrate:fresh` efface **tout**, y compris l'administrateur plateforme et les agences.
> Sans la deuxième ligne vous ne pourrez plus vous connecter du tout.

Le parcours rapide, lui, se rejoue autant que voulu : les deux seeders réutilisent ce qui
existe déjà au lieu de le recréer.

Dans les deux cas, rien ne vous demande de nettoyer derrière vous — les résidus sont normaux.

---

## 2. La cible, sur une page

| Code | Intitulé | Périmètre | Nature | Parent | Agence |
|---|---|---|---|---|---|
| `571000` | Caisse Globale | Institution | Regroupement | — | — |
| `571001` | Caisse HABIS Test | Agence | Imputable | `571000` | TEST-HABIS |
| `571002` | Caisse Cookbook | Agence | Imputable | `571000` | AG-COOK-01 |
| `571901` | Contrepartie HABIS | Agence | Imputable | — | TEST-HABIS |
| `571902` | Contrepartie Cookbook | Agence | Imputable | — | AG-COOK-01 |

Ensuite, comptabilisez `100` dans TEST-HABIS et `40` dans AG-COOK-01, puis vérifiez que
`571000` affiche **140**.

Les deux comptes `5719xx` n'existent que parce que toute écriture a deux sens — un compte au
débit, un autre au crédit. Ils représentent la contrepartie, « d'où vient l'argent ». Ne vous
en préoccupez pas autrement.

---

## 3. Construire la structure

### Étape 1 — Identifier l'institution

**Connectez-vous en administrateur plateforme** (numéro `SEED_BOOTSTRAP_ADMIN_PHONE`). Allez
dans **Paramétrage › Institution**.

Vous devez voir un bandeau ambre, *« Institution non encore identifiée »*. C'est correct sur
une installation neuve : la ligne existe mais elle est vide. Rien n'est inventé — la raison
sociale portée sur une déclaration COBAC doit être saisie par un humain.

Renseignez au minimum :

- **Raison sociale** : `HABIS MICROFINANCE SA` — la personne morale, telle qu'elle figure sur
  l'agrément / le RCCM.
- **Nom commercial** : `HabisLoan` — la marque publique. *(À laisser vide si identique ; la
  plupart des petits EMF n'ont pas de nom commercial distinct.)*
- **Autorité de supervision** : `COBAC`
- **Numéro d'agrément** : n'importe quelle valeur de test.

Enregistrez.

✅ **Attendu** : notification de succès, le bandeau ambre disparaît, les valeurs survivent à
un rechargement de la page.

### Étape 2 — Créer le total entreprise `571000`

**Comptabilité › Comptes généraux** → *Créer*.

> **La liste des classes est celle du PCEMF, pas Actif/Passif.** Le champ *Classe* propose
> désormais les huit classes du Plan Comptable des EMF — capitaux permanents, valeurs
> immobilisées, opérations avec la clientèle, tiers, trésorerie et opérations interbancaires,
> charges, produits, hors bilan — parce que c'est le plan qu'un EMF camerounais est tenu de
> tenir. La classe est le **premier chiffre du code** : `571000` commence par 5, sa classe est
> donc *trésorerie et opérations interbancaires*. Si vous voyez encore
> Actif / Passif / Capitaux propres / Produits / Charges, le frontend n'a pas pris la
> modification en compte.

| Champ | Valeur |
|---|---|
| Périmètre | **Institution** |
| Code | `571000` |
| Intitulé | `Caisse Globale` |
| Classe | **Comptes de trésorerie et d'opérations interbancaires** (classe 5) |
| Sens normal | Débit |

Choisir **Institution** fait disparaître le champ *Agence* et remplace le champ *Nature* par
une mention indiquant qu'un compte entreprise est toujours un compte de regroupement. C'est
le schéma qui parle : un compte entreprise n'a pas d'agence et ne peut pas être imputable.

✅ **Attendu** : compte créé. Dans la liste, la colonne **Structure** affiche un badge
*Institution* et un badge *Compte de regroupement*.

> Si le sélecteur **Périmètre** est absent, c'est qu'il vous manque
> `ledger.scope.institution.manage` — revoyez le §1.1.

### Étape 3 — Créer `571001` et observer le parent changer

*Créer* à nouveau :

| Champ | Valeur |
|---|---|
| Périmètre | Agence |
| Agence | `TEST-HABIS` |
| Code | `571001` |
| Intitulé | `Caisse HABIS Test` |
| Nature | **Compte imputable (détail)** |
| Compte parent | `571000 — Caisse Globale` |
| Classe / Sens | Comptes de trésorerie et d'opérations interbancaires (classe 5) / Débit |

✅ **Deux choses attendues :**

1. `571001` est créé comme compte imputable.
2. **Revenez sur `571000`.** Il était déjà un compte de regroupement, donc rien ne change
   visiblement ici — mais retenez le mécanisme : *la première fois qu'un compte acquiert un
   compte fils, le système bascule silencieusement ce parent en compte de regroupement.* Vous
   le verrez se produire à l'[Annexe A](#annexe-a--voir-la-conversion-automatique-en-direct).
   C'est aussi pourquoi la liste est rechargée depuis le serveur après chaque création plutôt
   que mise à jour localement.

### Étape 4 — Créer `571002` sous l'autre agence

Comme à l'étape 3, avec **Agence = `AG-COOK-01`**, code `571002`, intitulé `Caisse Cookbook`,
parent `571000`.

✅ **Attendu** : compte créé. Deux agences partagent maintenant un même parent entreprise —
c'est exactement la structure demandée par les testeurs, et la raison pour laquelle le
rattachement inter-agences est refusé (étape 7b) alors que le partage d'un parent entreprise
est autorisé.

### Étape 5 — Créer les deux comptes de contrepartie

Deux comptes de plus, tous deux **Nature = Compte imputable**, **sans parent** :

- `571901` `Contrepartie HABIS` — agence TEST-HABIS — Classe *Comptes de tiers* (classe 4) —
  Sens Crédit
- `571902` `Contrepartie Cookbook` — agence AG-COOK-01 — Classe *Comptes de tiers* (classe 4)
  — Sens Crédit

Rien d'autre à faire ici. La conversion automatique — un compte qui devient silencieusement
un compte de regroupement dès qu'il acquiert un fils — vaut la peine d'être observée, mais
elle modifie `571901` définitivement : elle est donc placée à
l'[Annexe A](#annexe-a--voir-la-conversion-automatique-en-direct), à la fin. Faites-la après
l'étape 11, pas maintenant.

---

## 4. Comptabiliser de l'argent réel

C'est la partie la plus fastidieuse, et une seule règle explique cette lourdeur : **la
personne qui saisit une écriture ne peut pas l'approuver.** C'est le principe des quatre yeux
(*maker-checker*) — un contrôle bancaire, pas une gêne à contourner. Il vous faut donc **deux
utilisateurs**.

La répartition suit la pratique réelle d'un EMF : **l'agence prépare, le siège valide.** Le
comptable d'agence saisit les *opérations diverses* de sa propre agence (corrections,
régularisations) et les soumet ; il ne peut ni approuver ni comptabiliser. Le chef comptable
valide et comptabilise, pour n'importe quelle agence.

Notez que les écritures **opérationnelles** — dépôts, retraits, décaissements,
remboursements — ne se saisissent pas à la main : elles sont générées automatiquement par les
imputations d'opérations. Ce que vous saisissez ici, ce sont les écritures que personne
n'automatise.

Donc, pour ce passage :

| Rôle dans le test | Utilisateur |
|---|---|
| **Auteur** (saisit, soumet) | `test.cookbook.accountant@example.test` pour AG-COOK-01 · votre **chief-accountant** pour TEST-HABIS |
| **Réviseur** (approuve, comptabilise) | votre **chief-accountant**, ou l'administrateur plateforme |

> Le comptable d'agence ne peut saisir que dans **son** agence. Nommer explicitement une
> autre agence est refusé — c'est le refus 7h du §6.

### Étape 6 — Écriture 1 : 100 dans TEST-HABIS

**En tant que chef comptable**, allez dans **Comptabilité › Opérations diverses** → créez une
écriture :

- Agence : `TEST-HABIS`
- Référence : `TEST-CONSO-A`
- Date : aujourd'hui

Ouvrez-la et ajoutez deux lignes :

| Compte | Sens | Montant |
|---|---|---|
| `571001 — Caisse HABIS Test` | Débit | `100` |
| `571901 — Contrepartie HABIS` | Crédit | `100` |

✅ **Attendu — et c'est un contrôle majeur :** la liste déroulante des comptes **ne propose
pas `571000`**. Le total n'est pas sélectionnable, vous ne pouvez donc pas commettre l'erreur
de double comptage. Avant cette modification il *était* proposé et produisait une erreur
déroutante à l'enregistrement.

Puis **Soumettre**.

**Connectez-vous en administrateur plateforme**, ouvrez la même écriture, **Approuver**, puis
**Comptabiliser**. Seule une écriture comptabilisée agit sur les soldes.

> Tenter d'approuver en tant que chef comptable donne *« L'approbation du journal nécessite un
> réviseur différent de l'auteur. »* — comportement correct.

### Étape 7 — Écriture 2 : 40 dans AG-COOK-01

Répétez à l'identique, mais cette fois **en tant que comptable d'agence**
(`test.cookbook.accountant@example.test`) puis en chef comptable :

- Agence `AG-COOK-01` — **ne renseignez pas l'agence**, elle est déduite de l'affectation du
  comptable ; c'est le comportement attendu.
- Référence `TEST-CONSO-B`
- `571002` Débit `40` · `571902` Crédit `40`
- Soumettre (comptable d'agence) → approuver → comptabiliser (chef comptable).

✅ **Attendu** : l'écriture est créée sur AG-COOK-01 sans que vous ayez choisi d'agence, et
*Approuver* est refusé au comptable d'agence — la validation appartient au siège.

---

## 5. Lire les chiffres — le résultat attendu

### Étape 8 — Le solde consolidé de `571000`

**Comptabilité › Comptes généraux** → ouvrez `571000`.

✅ **Attendu : `140`** — et non zéro.

`571000` n'a jamais reçu la moindre écriture. 140 correspond à `100` (TEST-HABIS) + `40`
(AG-COOK-01), calculé en parcourant l'arbre. Une note grise indique que les montants sont
consolidés. **Ce seul chiffre résume toute la fonctionnalité.**

Vérifiez également que `571001` seul affiche `100` et `571002` seul `40`.

> Pour un compte de regroupement, l'écran affiche toujours le chiffre consolidé — il n'y a
> pas de bascule. Ses mouvements propres (toujours nuls, c'est bien le principe) ne sont
> lisibles que via l'API, avec
> `GET /api/v1/ledger-accounts/{id}/balance?consolidated=0`.

### Étape 9 — La balance des comptes consolidée

Une *balance des comptes* liste chaque compte avec ses totaux débit et crédit.
**Édition › Balance des comptes** → *Générer* :

- Définition : **trial_balance**
- **Consolidation : `Consolidé (cumulé)`**
- **Agence : laissez VIDE** → *« Toutes les agences (institution) »*
- Devise : `XAF`

Laisser l'agence vide est ce qui étend l'édition à toute l'institution. Choisir une agence
vous donne l'arbre de cette agence uniquement — valable, mais ce n'est pas le chiffre
consolidé de l'institution.

✅ **Attendu**, dans l'aperçu :

| Contrôle | Attendu |
|---|---|
| Ligne `571000` | débit **140**, périmètre `institution`, `is_postable` = *Non* |
| Ligne `571001` | débit **100**, agence = TEST-HABIS |
| Ligne `571002` | débit **40**, parent = `571000` |
| **Total général débit** | **140**, *et non* 280 |

Le total général est le point subtil. `571000` affiche 140 et `571001` + `571002` affichent
140 à eux deux : additionner naïvement les lignes donnerait 280. Les totaux sont calculés
uniquement à partir des comptes **effectivement mouvementés**, de sorte que chaque mouvement
est compté une seule fois, quelle que soit la profondeur de l'arbre. **Si vous voyez 280,
c'est une véritable anomalie — signalez-la.**

Régénérez maintenant avec **Consolidation : `Comptes de détail uniquement`** : `571000`
disparaît (il n'a pas de mouvements propres) et le total reste 140.

---

## 6. Les refus — les échecs qui prouvent que ça marche

Chacun de ces cas **doit échouer**. Si l'un d'eux réussit, copiez le message obtenu.

> **Où concentrer votre attention.** La colonne *Filet automatique* indique le test qui
> verrouille déjà chaque refus côté API. Si un cas doté d'un filet échoue chez vous alors que
> la suite est verte, suspectez d'abord votre environnement (session périmée, base non
> migrée) plutôt que le code. Les lignes marquées **interface uniquement** sont celles
> qu'aucun test ne couvre : ce que l'interface choisit de proposer dans une liste déroulante
> ne se vérifie qu'à l'œil. **Ce sont celles où votre passage apporte le plus.**

| # | À faire | Doit échouer avec | Filet automatique |
|---|---|---|---|
| 7a | Créer un compte avec **Périmètre = Institution** *et* **Nature = imputable** | Le champ Nature n'est pas proposé en périmètre institution — l'interface l'empêche. Via l'API : *« Les comptes du grand livre au niveau entreprise regroupent les comptes des agences et ne peuvent pas recevoir d'écritures. »* | `test_institution_grouping_account_cannot_be_asked_to_be_postable` — le masquage du champ *Nature* est **interface uniquement**. |
| 7b | Créer un compte dans **TEST-HABIS** avec le parent **`571002`** (un compte d'AG-COOK-01) | `571002` **n'est pas dans la liste des parents**. Le rattachement inter-agences est refusé : deux agences peuvent partager un parent *entreprise*, jamais les comptes l'une de l'autre. | `test_parent_account_from_another_agency_is_still_refused` — l'absence dans la liste des parents est **interface uniquement**. |
| 7c | Ajouter une ligne d'écriture sur **`571000`** | `571000` **n'est pas dans la liste des comptes** (étape 6). | `test_journal_lines_cannot_post_to_a_grouping_account` — l'absence dans la liste des comptes est **interface uniquement**. |
| 7d | Faire de `571000` la cible débit/crédit d'une imputation d'opération — *Comptabilité › Codes opération & imputations* | `571000` **n'est pas dans la liste des comptes**. Les imputations automatiques ne doivent pas non plus viser un total. | `test_operation_account_mapping_cannot_target_a_grouping_account` — l'absence dans la liste est **interface uniquement**. |
| 7e | En tant que **`test.cookbook.accountant@example.test`**, ouvrir le solde de `571000` | La ligne du compte est visible, mais le solde et les mouvements renvoient **403** avec une explication — aucun chiffre. Un comptable d'agence peut rattacher ses comptes au total entreprise sans lire un chiffre qui couvre toutes les agences. **Un 403 ici est la bonne réponse, pas une erreur.** | `test_accountant_cannot_reach_another_agency_chart_or_the_institution_chart` |
| 7f | En tant que `test.cookbook.accountant@example.test`, tenter de créer un compte entreprise | Pas de sélecteur **Périmètre** — ce rôle ne tient que le plan comptable de sa propre agence. | `test_accountant_cannot_reach_another_agency_chart_or_the_institution_chart` |
| 7g | En tant que chef comptable, tenter de **rouvrir** une journée comptable clôturée. *Nécessite une journée réellement passée à `closed` (étape 10) ; l'interface ne propose aucune action Rouvrir sans la permission — vérifiez donc en appelant directement `POST /api/v1/accounting-days/{id}/reopen`.* | **403.** La réouverture d'une période clôturée reste réservée aux administrateurs plateforme : celui qui a arrêté les comptes ne doit pas pouvoir les réouvrir. L'absence du bouton fait partie de la réponse. | `test_chief_accountant_runs_the_institution_accounting_period` vérifie l'absence de la permission ; le 403 de l'API n'est pas couvert — **à vérifier à la main**. |
| 7h | En tant que `test.cookbook.accountant@example.test`, créer une écriture en renseignant **Agence = TEST-HABIS** | **422** — *« Vous ne pouvez enregistrer des écritures que pour votre propre agence. »* L'agence prépare ses propres écritures, elle n'écrit pas dans les livres d'une autre. | `test_accountant_prepares_its_own_agency_entries_but_cannot_validate_them` |

---

## 7. Frontières des rôles

### Étape 10 — Le chef comptable pilote la période de l'institution

**En tant que chef comptable**, allez dans **Administration › Journée Comptable**.

✅ **Attendu :**
- La page s'affiche et liste les journées **alors que cet utilisateur n'a aucune agence**.
  Auparavant, tout utilisateur du siège recevait un 403 pur et simple.
- *Ouvrir* propose un choix de **Périmètre** incluant **Institution**. Avant cette
  modification, ce choix était réservé à `platform-admin` par un contrôle de rôle codé en
  dur : le nouveau rôle ne pouvait donc pas faire le travail pour lequel il a été créé.
- L'ouverture et le lancement de la clôture de la journée **institution** fonctionnent.

> **Dépendance connue, pas une anomalie :** lancer une clôture exige que les lots de contrôle
> de clôture soient configurés, ce qui est volontairement réservé à l'administrateur
> plateforme (`batch.procedures.manage`). Si le lancement de la clôture répond *« La clôture
> du jour comptable ne peut pas commencer tant que des contrôles de clôture échouent. »*,
> c'est le comportement documenté : le chef comptable détient toutes les permissions que le
> lancement de clôture vérifie, et il a tout de même besoin qu'un administrateur plateforme
> ait mis en place les contrôles.

### Étape 11 — Le comptable d'agence reste dans son périmètre

En tant que `test.cookbook.accountant@example.test` :

- ✅ il **consulte** le plan comptable, saisit et soumet les *opérations diverses* d'**AG-COOK-01**,
  et ouvre ou clôture la journée comptable de son agence ;
- ❌ il ne **crée ni ne modifie** de compte — le PCEMF est adopté une fois au siège, chaque
  compte mouvementé doit être rattaché au plan COBAC, et la consolidation ne tombe juste que
  si le plan est commun. Une subdivision se demande au chef comptable ;
- ❌ il n'approuve ni ne comptabilise ses propres écritures, ne touche pas au plan entreprise
  ni à celui d'une autre agence, ne lit pas les chiffres consolidés, et ne pilote pas la
  période de l'institution.

---

## 8. Si quelque chose semble anormal

| Symptôme | Presque certainement |
|---|---|
| *Paramétrage › Institution* absent du menu latéral | Session périmée. **Déconnectez-vous et reconnectez-vous** (§1.1). |
| Pas de sélecteur **Périmètre** sur le formulaire de compte | Idem — ou l'utilisateur n'a réellement pas `ledger.scope.institution.manage`. |
| Impossible de créer une écriture | L'agence n'a pas de **journée comptable ouverte**. Ouvrez-en une dans *Administration › Journée Comptable*. |
| *« L'approbation du journal nécessite un réviseur différent de l'auteur. »* | Fonctionne comme prévu. Approuvez avec l'**autre** utilisateur (§4). |
| Le compte que je cherche est absent d'une liste déroulante | C'est presque toujours correct : c'est un compte de regroupement, ou il appartient à une autre agence. Vérifiez la colonne **Structure**. |
| Un compte est devenu « Compte de regroupement » tout seul | Correct — il a acquis un compte fils (Annexe A). |
| Un solde affiche 0 là où vous attendiez un chiffre | L'écriture n'est pas **comptabilisée**. Soumise et approuvée ne suffisent pas. |
| Le total général vaut le double du chiffre attendu | **Véritable anomalie.** Signalez-la (§ étape 9). |
| Seuls les ~100 premiers comptes apparaissent dans une liste déroulante | Limitation connue : le plan est chargé page par page puis filtré dans le navigateur. Sans conséquence à cette taille, à corriger avant de charger un plan PCEMF complet. |

---

## 9. Liste de contrôle de couverture

- [ ] Profil de l'institution lu, modifié, persistant ; le bandeau « non identifiée » se
      comporte correctement
- [ ] Compte de regroupement entreprise créé (`571000`)
- [ ] Comptes de détail de deux agences rattachés à un même parent entreprise
- [ ] Conversion automatique d'un parent en compte de regroupement observée (Annexe A)
- [ ] Écritures comptabilisées dans deux agences via le principe des quatre yeux
- [ ] Solde consolidé de `571000` = 140
- [ ] Balance consolidée : lignes correctes **et total général non doublé**
- [ ] L'édition non consolidée fait disparaître le compte de regroupement et conserve le total
- [ ] Les huit refus du §6 sont bien refusés
- [ ] Le chef comptable pilote la période de l'institution sans aucune agence rattachée
- [ ] Le comptable d'agence prépare et soumet une OD de sa propre agence, sans pouvoir
      l'approuver
- [ ] Le comptable d'agence consulte le plan comptable sans pouvoir le modifier
- [ ] Le comptable d'agence est confiné à sa propre agence

Toute case non cochée, ou tout refus qui n'a pas refusé, mérite d'être signalé avec le message
exact et l'utilisateur avec lequel vous étiez connecté.

---

## Annexe A — Voir la conversion automatique en direct

*Facultatif, une minute. À faire **après** l'étape 11 : cela modifie `571901`
définitivement, or ce compte sert de contrepartie jusque-là.*

Créez un compte imputable jetable `571999` dans TEST-HABIS avec le **parent `571901`**.
Regardez maintenant `571901` dans la liste : il a gagné un badge *Compte de regroupement* et
n'est plus imputable — **vous ne l'avez jamais demandé.**

C'est arrivé parce qu'il est devenu un total. C'est le comportement le plus susceptible de
passer pour une anomalie, et ce n'en est pas une : un compte qui a des fils ne doit pas porter
en plus ses propres écritures, sinon le même argent serait compté deux fois. Archiver `571999`
ensuite n'annule rien — le badge sur `571901` demeure, et c'est précisément pourquoi cette
manipulation se place en fin de parcours plutôt qu'au milieu.

Le seul cas où le système refuse au lieu de convertir : donner un parent à un compte qui
**porte déjà des mouvements comptabilisés**. Vous obtenez alors *« Le compte parent
sélectionné porte déjà des mouvements et ne peut pas devenir un compte de regroupement. »* Le
convertir laisserait ces écritures échouées sur un total. Essayez sur `571001`, qui porte
désormais 100.
