# Guide de test — journée comptable, compte de résultat, clôture et affectation

Script de test pas à pas, à suivre du début à la fin. Écrit pour un non-comptable :
chaque étape dit quoi faire, ce que vous devez voir, et — quand cela compte —
*pourquoi*, afin de distinguer une anomalie d'un comportement correct.

Branches : API `feat-entreprise`, frontend `feat-institution-level`.

---

## 0. L'idée en quatre-vingt-dix secondes

**Une seule date comptable pour l'institution.** Le siège place l'EMF sur une date ;
les agences ouvrent leur journée *à l'intérieur* de cette date. C'est ainsi que
fonctionnent les progiciels du métier : Apache Fineract ne conserve qu'une date
d'activité pour tout l'établissement, et Finacle refuse de clôturer le siège pour
une date tant qu'une agence y est encore ouverte.

**À la fin de l'exercice, deux opérations distinctes :**

| | Ce que ça fait | Qui décide |
|---|---|---|
| **Clôture** | Solde les classes 6 et 7, porte le résultat au **131** (bénéfice) ou **132** (perte) | Le comptable, sur la base des chiffres |
| **Affectation** | Vide le 131/132 vers réserves, report à nouveau, dividendes | L'**assemblée générale** |

Les deux créent une **écriture soumise** : rien n'est transféré avant qu'elle ne soit
approuvée puis comptabilisée par **une autre personne**.

---

## 1. Ce qu'il faut avant de commencer

- **Deux comptes utilisateurs.** Le contrôle à quatre yeux interdit d'approuver sa
  propre écriture : prévoyez un « chef comptable » et un second utilisateur
  (platform-admin fait l'affaire) pour approuver et comptabiliser.

  Le chef comptable, lui, gère **aussi bien la journée de l'établissement que celles
  des agences** — c'est le même périmètre institution qui lui permet déjà de passer
  des écritures à distance dans les livres d'une agence. Tout le parcours peut donc
  être mené par lui, le second utilisateur n'intervenant que pour approuver.
- **Une base fraîchement migrée et semée.** Le plan comptable PCEMF complet est
  créé par agence, et **trois journées comptables sont déjà ouvertes** à la date du
  semis (l'établissement et chaque agence). L'étape 2 part de cet état : il n'y a
  rien à défaire au préalable.
- **Aucune écriture existante.** Sur une base neuve il n'y a aucune écriture : c'est
  normal, et c'est pourquoi les premières étapes en créent.

Comptes réellement présents dans le plan semé, à utiliser tel quel :

| Code | Intitulé | Rôle dans le test |
|---|---|---|
| `7011` | Intérêts sur placements interbancaires | produit (classe 7) |
| `6011` | Opérations interbancaires | charge (classe 6) |
| `6611` | Impôt sur les sociétés | impôt sur le résultat |
| `6612` | Etat, autres impôts et taxes directs | patente & co. |
| `5710` | Caisse FCFA | contrepartie |
| `131` | Bénéfice de l'exercice | reçoit le résultat |
| `111` | Réserves légales | destination d'affectation |
| `121` | Report à nouveau créditeur | destination d'affectation |

---

## 2. La hiérarchie des journées comptables

**But : vérifier qu'une agence ne peut pas travailler hors de la date de
l'institution, et que l'institution ne peut pas quitter une date où une agence
travaille encore.**

Allez dans **Administration › Journée comptable**.

### 2.0 D'où vous partez

Sur une base fraîchement semée, **trois journées sont déjà ouvertes** à la date du
semis : celle de l'établissement et celle de chaque agence. C'est le semeur de banc
de test qui les crée, et c'est cohérent avec la règle — l'établissement d'abord, les
agences à l'intérieur.

Vous le voyez dans **Historique des journées** : trois lignes, même date, statut
*Ouverte*, périmètres *Établissement*, *Agence*, *Agence*.

Le test consiste donc à **déplacer** cette date, pas à partir de zéro. C'est aussi
ce que vous devrez faire de toute façon pour atteindre le 31/12/2026.

### 2.1 L'institution ne peut pas partir avant ses agences

1. Périmètre consulté : **Établissement**.
2. Cliquez **Démarrer la clôture**.

**Attendu :** refus, avec un message indiquant que des journées d'agence sont
encore ouvertes sur cette date, et que les agences clôturent d'abord.

**Vérifiez aussi** que la journée de l'établissement est **restée *Ouverte*** — elle
ne doit pas être passée en *Clôture en cours*.

> *Pourquoi* : c'est la règle de Finacle. Sinon l'établissement déclare la date
> terminée alors que les guichets y enregistrent encore, et les chiffres arrêtés
> pour cette date continuent de bouger après avoir été produits.

### 2.2 Les agences clôturent, puis l'institution

3. Périmètre consulté : chaque **agence** à son tour → **Démarrer la clôture**, puis
   **Clôturer**. En exploitation courante c'est le personnel de l'agence qui le
   fait ; le siège peut le faire à sa place, ce qui est précisément ce qu'on vérifie
   ici.
4. Revenez sur **Établissement** → **Démarrer la clôture**, puis **Clôturer**.

**Attendu :** cette fois l'établissement se clôture.

### 2.3 Une agence seule est maintenant refusée

Plus aucune journée n'est ouverte.

5. **Ouvrir une journée**, portée **Agence**.

**Attendu :** le champ de date est remplacé par un message expliquant que
l'institution n'a aucune journée ouverte, et le bouton d'envoi est **désactivé**.

> *Pourquoi* : déplacer la date de l'institution est une décision du siège, pour
> tout le réseau. Ce n'est pas un effet de bord de l'ouverture d'un guichet.

### 2.4 Le siège ouvre la date, l'agence suit

6. **Ouvrir une journée**, portée **Établissement**, date **31/12/2026**. Validez.

**Attendu :** journée ouverte au 31/12/2026, et le bandeau en haut affiche
« Journée ouverte · **Siège** · 31 décembre 2026 ».

> Le mot **Siège** compte : c'est la journée de l'institution, pas celle d'une
> agence. Deux personnes voyaient auparavant « Journée ouverte » en parlant de deux
> journées différentes.

7. **Ouvrir une journée**, portée **Agence**, choisissez une agence.

**Attendu :** le champ date affiche **31/12/2026 en lecture seule**, avec la mention
que la journée d'agence s'ouvre à la date de l'institution. Validez.

---

## 3. Passer des écritures à solder

**But : donner au compte de résultat et à la clôture quelque chose à traiter.**

Allez dans **Comptabilité › Opérations diverses**. Créez et **comptabilisez** (via
le second utilisateur) les écritures suivantes, toutes datées du **31/12/2026** :

| Écriture | Lignes |
|---|---|
| Produit | `7011` au **crédit** 120 000 · `5710` au **débit** 120 000 |
| Charge | `6011` au **débit** 30 000 · `5710` au **crédit** 30 000 |
| Impôts | `6611` au **débit** 9 000 · `6612` au **débit** 5 000 · `5710` au **crédit** 14 000 |

Rappel : chaque écriture doit être **équilibrée** (total débit = total crédit), sinon
elle est refusée à la revue. Et c'est le **second utilisateur** qui approuve puis
comptabilise.

> Les montants se saisissent en francs, comme partout ailleurs dans
> l'application. Les rapports les affichent avec les décimales : « 120 000 » saisi
> se relit « 120 000,00 XAF ». Tous les chiffres attendus ci-dessous sont donnés
> dans l'unité que vous avez saisie.

---

## 4. Compte de résultat

**But : vérifier les huit soldes intermédiaires de gestion.**

**Édition › Compte de résultat** → **Générer un rapport**. Agence : celle utilisée.
Période : **01/01/2026 → 31/12/2026**. Devise XAF.

Ouvrez **Aperçu**. Attendu :

| Solde | Montant | Sens |
|---|---|---|
| 80 Produit net financier | **90 000** | crédit |
| 81 Produit d'exploitation global | 90 000 | crédit |
| 82 Résultat d'exploitation | **85 000** | crédit |
| 83 Résultat courant | 85 000 | crédit |
| 84 Résultat exceptionnel | 0 | *(aucun)* |
| 85 Résultat avant impôt | 85 000 | crédit |
| 86 Impôts sur le résultat | **9 000** | crédit |
| 87 Résultat net | **76 000** | crédit |

**Les deux chiffres à vérifier en priorité :**

- **82 = 85 000**, et surtout **pas 76 000**. Toute la classe 66 (14 000) est
  retirée, puis l'impôt sur les sociétés (9 000) est **rendu**. Seule la patente
  (5 000) reste à la charge de l'exploitation. Si vous lisez 76 000, l'impôt sur les
  sociétés n'a pas été rendu et il est compté deux fois — une fois ici, une fois au
  86.
- **86 = 9 000**, pas 14 000. La ligne d'impôt ne contient que l'impôt sur les
  sociétés. Si elle affiche 14 000, la patente est comptée deux fois.

> Un solde à zéro n'affiche **aucun sens** — ni débit ni crédit. C'est voulu :
> l'absence de position n'est pas une position.

---

## 5. Clôture de l'exercice

**Comptabilité › Clôture de l'exercice**. Agence : la même. Exercice : **2026**.
Cliquez **Clôturer l'exercice**.

**Attendu :**

- Résultat net **76 000**, porté au **131** (bénéfice)
- Statut **En attente de revue** — et un bandeau qui rappelle que rien n'a encore
  été transféré

### 5.1 Faire aboutir le transfert

Restez sur la page : la ligne de la clôture porte un bouton **« Approuver et
comptabiliser »**. Cliquez-le — **connecté avec le second utilisateur**.

> Le second utilisateur n'est pas une précaution du guide, c'est la règle : celui
> qui établit une écriture ne peut pas l'approuver. Le même compte se verra
> refuser le bouton.
>
> Si vous préférez le faire à la main, la même écriture est visible dans
> **Comptabilité › Opérations diverses** sous la référence
> **`CLOT-2026-<code agence>`** ; le bouton fait exactement les deux gestes.

**Attendu au retour :** statut **Comptabilisée**.

### 5.2 Vérifier l'effet

Dans **Comptabilité › Comptes généraux**, consultez les soldes :

- `7011` → **0**, aucun sens
- `6011` → **0**
- `131` → **76 000** au **crédit**

> C'est tout l'objet de la clôture : les classes 6 et 7 repartent à zéro, le
> résultat est logé au 131.

### 5.3 Le compte de résultat reste lisible

Régénérez le compte de résultat sur **01/01/2026 → 31/12/2026**.

**Attendu : les mêmes 76 000 qu'à l'étape 4**, pas zéro.

> L'écriture de clôture est datée du dernier jour de l'exercice, comme il se doit,
> mais elle est **exclue** du compte de résultat. Sinon elle annulerait l'activité
> qu'elle solde et les comptes annuels afficheraient zéro dès leur signature. La
> **balance des comptes**, elle, l'affiche : ce rapport doit montrer le grand livre
> tel qu'il est.

---

## 6. L'exercice clôturé n'accepte plus rien

**But : vérifier le verrou de période.**

Essayez de créer une écriture datée du **31/12/2026** (ou de toute date de 2026).

**Attendu :** refus, avec un message indiquant que l'exercice est clôturé et que la
correction doit passer par l'exercice **courant** — au **67** pour une perte sur
exercice antérieur, au **77** pour un profit.

Essayez également de **réouvrir** une journée comptable de 2026 : également refusé.

> Sans ce verrou, un montant ajouté après coup serait ramassé par la clôture
> suivante et viendrait gonfler le résultat de l'année d'après, tandis que les
> comptes déposés à la COBAC ne correspondraient plus au grand livre.

---

## 7. Affectation du résultat

**But : vider le 131 selon la décision de l'assemblée générale.**

D'abord, avancez la date : **Administration › Journée comptable** → ouvrez la
journée **Institution** au **30/06/2027**, puis la journée de l'agence.

> Vous devrez d'abord clôturer la journée de l'agence au 31/12/2026 : l'institution
> ne peut pas quitter une date où une agence est encore ouverte.

Allez dans **Comptabilité › Affectation du résultat**. Agence : la même. Exercice :
**2026**. Décidé le : **30/06/2027**.

**Attendu avant toute saisie :** « À répartir : **76 000** (131) » et « Reste à
affecter : 76 000 » — le montant est lu sur la clôture comptabilisée, pas ressaisi.

Saisissez deux lignes :

| Compte | Montant |
|---|---|
| `111` Réserves légales | 20 000 |
| `121` Report à nouveau créditeur | 56 000 |

**Attendu :** « Reste à affecter » tombe à **0,00**. Validez.

### 7.1 Vérifier les refus utiles

- Une répartition qui **ne tombe pas juste** (par exemple 20 000 seulement) est
  refusée. Sinon le 131 garderait un reliquat que plus rien ne pourrait solder,
  l'exercice étant déjà marqué comme affecté.
- Affecter **vers le 131 lui-même** est refusé : l'écriture s'équilibrerait sans
  rien accomplir.
- **Deux fois le même exercice** est refusé : le 131 serait débité deux fois et les
  réserves créditées d'un argent jamais gagné.

### 7.2 Faire aboutir, puis vérifier

Même geste qu'à la clôture : la ligne de l'affectation porte son bouton
**« Approuver et comptabiliser »**, à cliquer avec le **second utilisateur**.
Consultez ensuite les soldes :

- `131` → **0**, aucun sens
- `111` → **20 000** au crédit
- `121` → **56 000** au crédit

> Sans l'affectation, le résultat de 2027 viendrait s'empiler sur celui de 2026 dans
> le 131, et le bilan surévaluerait « Bénéfice de l'exercice » indéfiniment.

---

## 8. Points rugueux connus

Ce ne sont pas des anomalies à signaler — ils sont connus :

1. **Pas de lien vers l'écriture.** Les pages de clôture et d'affectation
   n'ouvrent pas l'écriture qu'elles créent ; il faut la chercher par sa référence
   dans les écritures comptables.
2. **En-têtes de colonnes en anglais** dans l'aperçu des rapports
   (« Amount », « Balance side »). C'est le comportement de tous les rapports du
   module, pas seulement du compte de résultat.
3. **Séquence en deux endroits.** Ouvrir la date de l'institution se fait dans
   *Administration*, clôturer l'exercice dans *Comptabilité*.

---

## 9. Ce qui doit vous faire lever la main

| Symptôme | Pourquoi c'est grave |
|---|---|
| Solde **86 = 14 000** | La patente est comptée dans l'impôt sur le résultat |
| Solde **82 = 76 000** | L'impôt sur les sociétés n'a pas été rendu au 82 (compté deux fois) |
| Compte de résultat à **zéro** après la clôture | L'écriture de clôture n'est plus exclue |
| Une écriture datée de 2026 **acceptée** après clôture | Le verrou de période ne fonctionne plus |
| `131` **non nul** après affectation | Le résultat s'empilera d'une année sur l'autre |
| Une journée d'agence ouvrable à une **autre date** que l'institution | La date unique n'est plus garantie |
| L'établissement **clôturable** alors qu'une agence est ouverte | Les chiffres arrêtés continueront de bouger |
| Une erreur technique (500) au lieu d'un refus lisible | Le garde-fou n'existe qu'en base, pas dans l'application |
| Un **code technique** affiché à la place d'une phrase (« accounting_day_… ») | Le message localisé de l'API n'est plus repris |
| Le chef comptable **ne voyant pas** les agences dans « Périmètre consulté » | Il ne peut plus clôturer les agences dont dépend la clôture de l'établissement |
| Un comptable d'agence voyant la journée d'une **autre** agence | Le cloisonnement par agence est perdu |
| Statut **Comptabilisée** sans avoir approuvé | Le contrôle à quatre yeux est contourné |
