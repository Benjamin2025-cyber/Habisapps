# Guide de test — frais de dossier en pourcentage

Parcours complet, de la création du produit jusqu'au calcul des frais. Aucune
connaissance préalable du circuit de prêt n'est supposée : chaque étape dit quoi
faire, **avec quel compte se connecter**, et ce que vous devez voir.

Ce qu'on vérifie, c'est la demande de la comptabilité :

> « Le montant des frais de dossier doit être en pourcentage et sans plancher. »

---

## 0. Ce qui a changé, en trente secondes

**Avant** : les frais de dossier étaient un **montant fixe** en francs. Un prêt de
100 000 F et un prêt de 5 000 000 F payaient les mêmes frais. Le formulaire proposait
en plus un champ **« Montant plancher »**, enregistré en base et **lu par aucun
calcul**.

**Maintenant** : un seul champ, **« Taux des frais de dossier (%) »**. Les frais sont
un pourcentage du capital. Ni montant fixe, ni plancher.

> ⚠️ **À lire avant de crier au bug.** La colonne du montant fixe a été supprimée.
> Les produits créés **avant** ce changement n'ont donc aucun taux : leurs frais
> valent **0** tant que personne n'en saisit un. C'est attendu.

---

## 1. Les comptes dont vous aurez besoin

Un prêt passe **quatre visas**, et **une même personne ne peut en signer qu'un seul**
(le système refuse le deuxième). Il faut donc quatre utilisateurs différents.

**La connexion se fait avec le numéro de téléphone**, pas l'adresse e-mail. Tous ces
comptes existent déjà, avec le mot de passe **`password123`** :

| Étape | Se connecter avec | Rôle |
|---|---|---|
| Créer le produit | **+237690000011** | chief-accountant |
| Créer le client et le prêt | **+237690000005** | loan-officer |
| Visa **Montage** | **+237690000005** | loan-officer |
| Visa **Comptabilité** | **+237690000006** | accountant |
| Visa **Contrôle** | **+237690000008** | compliance-officer |
| Visa **Direction** | **+237690000002** | agency-manager |
| Frais et déblocage | **+237690000002** | agency-manager |

> Si un visa est refusé avec « vous avez déjà approuvé une autre étape », c'est cette
> règle-là : changez d'utilisateur. Ce n'est pas une anomalie.

**Une journée comptable doit être ouverte** pour l'agence concernée, sinon rien ne
s'enregistre. Vérifiez le bandeau en haut à droite : il doit afficher
« Journée ouverte ». Sinon, voir *Administration › Journée comptable*.

---

## 2. Créer le produit de prêt

Connecté en **chief-accountant** (+237690000011) → **Crédit › Produits de prêt** → **Nouveau produit**.

Seuls **le code et le libellé** sont obligatoires. Remplissez toutefois ceci, pour
que le prêt puisse ensuite être monté sans blocage :

| Champ | Valeur | Pourquoi |
|---|---|---|
| Code | `TEST-FRAIS` | unique ; sert d'identifiant |
| Libellé | `Produit test frais de dossier` | |
| Statut | `Actif` | un produit inactif ne se sélectionne pas |
| Montant minimum | `50 000` | bornes du capital autorisé |
| Montant maximum | `5 000 000` | |
| Durée min / max | `1` / `24` | |
| Unité de durée | `Mois` | |
| Fréquences de remboursement | `Mensuel` | sinon aucune échéance possible |
| Taux d'intérêt | `2` | |
| **Taux des frais de dossier (%)** | **`1,5`** | **le champ testé** |
| Taxe / Assurance | laisser vide | on isole les frais de dossier |
| Type de dépôt de garantie | laisser vide | autre prélèvement, hors sujet ici |
| Valeur du dépôt de garantie | laisser vide | |
| Garantie exigée / Caution exigée | décochés | évite des pièces à fournir |

**Avant d'enregistrer, regardez la forme du formulaire :**

- ✅ un champ **« Taux des frais de dossier (%) »**, à côté des taux d'intérêt, taxe
  et assurance ;
- ❌ **aucun** champ « Frais de dossier » exprimé en francs ;
- ❌ **aucun** champ « Montant plancher ».

> **« Type de dépôt de garantie » propose bien *pourcentage* et *fixe* — c'est
> normal, ne le signalez pas.** Le dépôt de garantie est un autre prélèvement : une
> somme que l'emprunteur dépose en garantie, pas des frais que l'institution perçoit.
> La demande de la comptabilité ne portait que sur les frais de dossier. Les deux
> options fonctionnent réellement, contrairement au « Montant plancher » qui, lui, ne
> pilotait rien.

Enregistrez, rouvrez le produit : le taux **1,5** doit avoir été conservé.

> Si « Montant plancher » est encore là, arrêtez-vous : inutile de tester la suite.

---

## 3. Créer un client

Connecté en **loan-officer** (+237690000005) → **Référentiel › Client** → **Nouveau client**.
Un client physique avec un nom et un téléphone suffit.

> Un prêt exige un client existant. Si votre base en contient déjà un utilisable,
> passez cette étape.

---

## 4. Le prêt n° 1 — capital 1 000 000 F

Toujours en **loan-officer** (+237690000005) → **Crédit › Mise en place** → **Nouveau prêt**.

| Champ | Valeur |
|---|---|
| Client | celui de l'étape 3 |
| Produit | `TEST-FRAIS` |
| Montant demandé | `1 000 000` |
| Durée | `12` mois |
| Fréquence | `Mensuelle` |

Enregistrez. Le prêt est au statut **Demande**.

### 4.1 Les quatre visas

Ouvrez le prêt. Le **Circuit de visa** est le bandeau d'étapes placé au-dessus des
onglets — ce n'est pas un onglet. Approuvez dans l'ordre, **en changeant de compte
à chaque fois** (voir le tableau de l'étape 1) :

1. **Montage** — loan-officer, +237690000005
2. **Comptabilité** — accountant, +237690000006
3. **Contrôle** — compliance-officer, +237690000008
4. **Direction** — agency-manager, +237690000002

Après le visa Direction, le prêt passe au statut **Approuvé**.

### 4.2 Évaluer les frais

Connecté en **agency-manager** (+237690000002) → **Crédit › Déblocage prêt** → sélectionnez le prêt →
section **Frais de dossier** → **Évaluer les frais** si le calcul n'a pas déjà eu lieu.

**Attendu : frais de dossier = 15 000,00 F.**

C'est 1,5 % de 1 000 000. Le même montant apparaît dans
**Crédit › Mise en place › [le prêt] › onglet Financières**, ligne « Frais de dossier ».

---

## 5. Le prêt n° 2 — sans plancher, le test qui compte

Refaites l'étape 4 à l'identique, **même produit**, mais avec un capital de
**100 000 F**. Repassez les quatre visas, puis évaluez les frais.

**Attendu : frais de dossier = 1 500,00 F.**

Dix fois moins de capital, dix fois moins de frais. C'est exactement ce que « sans
plancher » veut dire : rien ne relève ce montant vers un minimum.

> Sous l'ancien fonctionnement, ce prêt payait le **même** montant que celui de
> 1 000 000 F. C'est ce que la comptabilité reprochait au système.

**Si les deux prêts affichent le même montant de frais, arrêtez-vous et
signalez-le** : le taux n'est pas appliqué.

---

## 6. Le prêt n° 3 — un montant qui ne tombe pas juste

Même produit, capital **33 333 F**. Visas, puis évaluation.

**Attendu : frais de dossier = 500,00 F.**

1,5 % de 33 333 F donne 499,9995 F : le montant est arrondi au centime le plus
proche.

> Ce cas mérite d'être testé : avec un montant fixe la question ne se posait jamais.
> Avec un pourcentage, la plupart des capitaux ne tombent pas juste.

**Une erreur technique (500) à cette étape est un vrai défaut** — c'est le symptôme
d'un calcul qui refuse d'arrondir. Signalez-la avec le capital et le taux utilisés.

---

## 7. Deux cas limites, rapides

Modifiez le produit (en **chief-accountant**, +237690000011) et refaites une évaluation sur un nouveau
prêt :

| Taux saisi | Attendu |
|---|---|
| **0** | Frais = 0,00 F, et le prêt reste déblocable |
| Champ **vide** | Frais = 0,00 F également |

Des frais nuls ne doivent jamais bloquer un déblocage.

---

## 8. Ce qui doit vous faire lever la main

| Symptôme | Pourquoi c'est grave |
|---|---|
| Le champ **« Montant plancher »** est encore sur le formulaire | La demande n'est pas appliquée, et ce champ ne pilote rien |
| Un champ **« Frais de dossier »** en francs subsiste | Le montant fixe reste configurable |
| Deux prêts de capitaux différents ont les **mêmes** frais | Le taux n'est pas appliqué |
| Les frais d'un petit prêt sont **relevés** à un minimum | Un plancher subsiste quelque part |
| **Erreur 500** à l'évaluation | Le calcul refuse d'arrondir |
| Le taux n'est **pas conservé** après enregistrement | Le champ n'est pas transmis à l'API |

---

## 9. Ce que ce guide ne couvre pas

- La **taxe** sur les frais et le **dépôt de garantie** suivent leurs propres règles,
  inchangées ici — c'est pourquoi l'étape 2 laisse leurs champs vides, afin que le
  montant observé ne vienne que des frais de dossier.
- Si le produit utilise une **politique de formule** définissant son propre
  `dossier_fee_rate`, celle-ci l'emporte sur le taux du produit. Ce mécanisme
  existait déjà.
- Le **collecte** effective des frais et le déblocage lui-même : on s'arrête au
  montant calculé, qui est l'objet de la demande.
