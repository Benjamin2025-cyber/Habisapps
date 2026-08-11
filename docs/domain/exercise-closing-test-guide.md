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
  propre écriture. Prévoyez un « chef comptable » et un second utilisateur
  (platform-admin fait l'affaire) pour approuver.
- **Une base fraîchement migrée et semée.** Le plan comptable PCEMF complet est
  créé par agence.
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
l'institution.**

Allez dans **Administration › Journée comptable**.

### 2.1 Une agence seule est refusée

1. Assurez-vous qu'**aucune journée d'institution** n'est ouverte.
2. Cliquez **Ouvrir une journée**, choisissez la portée **Agence**.

**Attendu :** le champ de date est remplacé par un message expliquant que
l'institution n'a aucune journée ouverte, et le bouton d'envoi est **désactivé**.

> *Pourquoi* : la date de l'institution est déplacée par le siège, pour tout le
> réseau. Ce n'est pas un effet de bord de l'ouverture d'un guichet.

### 2.2 Le siège ouvre la date

3. Même écran, portée **Institution**, date **31/12/2026**. Validez.

**Attendu :** journée ouverte au 31/12/2026. Le bandeau en haut affiche
« Journée ouverte · **Siège** · 31 décembre 2026 ».

> Le mot **Siège** compte : c'est la journée de l'institution, pas celle d'une
> agence. Deux personnes voyaient auparavant « Journée ouverte » en parlant de deux
> journées différentes.

### 2.3 L'agence suit, sans choisir sa date

4. **Ouvrir une journée**, portée **Agence**, choisissez une agence.

**Attendu :** le champ date affiche **31/12/2026 en lecture seule**, avec la mention
que la journée d'agence s'ouvre à la date de l'institution. Validez : la journée
s'ouvre.

---

## 3. Passer des écritures à solder

**But : donner au compte de résultat et à la clôture quelque chose à traiter.**

Allez dans **Comptabilité › Écritures comptables**. Créez et **comptabilisez** (via
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

**Comptabilité › Clôture de l'exercice.** Agence : la même. Exercice : **2026**.
Cliquez **Clôturer l'exercice**.

**Attendu :**

- Résultat net **76 000**, porté au **131** (bénéfice)
- Statut **En attente de revue** — et un bandeau qui rappelle que rien n'a encore
  été transféré

### 5.1 Faire aboutir le transfert

Allez dans **Comptabilité › Écritures comptables**, cherchez la référence
**`CLOT-2026-<code agence>`**, puis **Approuvez** et **Comptabilisez** — avec le
**second utilisateur**.

> ⚠️ **Point rugueux connu** : la page de clôture ne renvoie pas vers son écriture.
> Il faut la retrouver à la main par sa référence.

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

Approuvez et comptabilisez l'écriture avec le **second utilisateur**, puis
consultez les soldes :

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
| Statut **Comptabilisée** sans avoir approuvé | Le contrôle à quatre yeux est contourné |
