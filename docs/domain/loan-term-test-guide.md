# Tester : durée, périodicité et différé d'un prêt

**Pour qui :** un testeur, pas un comptable. Tout est cliquable, rien à calculer de
tête sauf une addition de jours.

**Durée :** partie A, 5 minutes. Partie B, une trentaine de minutes (elle demande les
quatre visas).

---

## Ce qui a changé

Trois réglages du produit de prêt étaient **affichés, enregistrés, et sans aucun
effet**. Ils fonctionnent maintenant.

| Réglage | Avant | Maintenant |
|---|---|---|
| **Durée minimale / maximale** | ignorée : un produit « 4 à 12 » acceptait un prêt de 60 échéances | le prêt est refusé hors bornes |
| **Délai de grâce min. / max.** | ignoré de la même façon | refusé hors bornes |
| **Unité de durée** (jour / semaine / mois) | ignorée : l'échéancier espaçait **toujours** d'un mois | l'échéancier suit l'unité choisie |
| **Différé (jours)** du prêt | enregistré, jamais appliqué : l'échéancier était identique avec ou sans différé | la 1re échéance est repoussée |

> Seules les **dates** changent. Les montants ne bougent pas : les intérêts se
> calculent sur le capital, pas sur la durée. Un échéancier faux ne se voyait donc
> nulle part dans les totaux — c'est pour ça que personne ne l'avait signalé.

---

## 1. Les comptes

Mêmes comptes que le guide des frais de dossier. **Connexion par numéro de
téléphone**, mot de passe **`password123`**.

| Étape | Se connecter avec | Rôle |
|---|---|---|
| Créer le produit | **+237690000011** | chief-accountant |
| Créer le prêt | **+237690000005** | loan-officer |
| Visa **Montage** | **+237690000005** | loan-officer |
| Visa **Comptabilité** | **+237690000006** | accountant |
| Visa **Contrôle** | **+237690000008** | compliance-officer |
| Visa **Direction** | **+237690000002** | agency-manager |

Il faut **un client déjà actif et KYC validé**. Si vous avez fait le guide des frais
de dossier, reprenez le même client — inutile d'en recréer un.

**Une journée comptable doit être ouverte** pour l'agence : bandeau en haut à droite,
« Journée ouverte ».

> **Avant de commencer**, rejouez les permissions une fois :
> `php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder`
>
> **Puis déconnectez-vous et reconnectez-vous.** Les droits sont lus à la connexion :
> tant que vous gardez la session ouverte, vous continuez avec ceux d'avant, et un
> bouton nouvellement autorisé reste invisible sans que rien ne l'explique.
>
> Le loan-officer et le kyc-officer n'avaient pas le droit de lire la liste du
> personnel, ce qui laissait le champ **Agent de crédit** (et, sur la fiche client,
> **Prospecteur** et **Agent de recouvrement**) désespérément vide. Sans ce seed,
> vous rencontrerez le même vide.

---

## 2. Créer le produit de test

**Crédit › Produits de prêt** → **Nouveau produit**, avec **+237690000011**.

Seuls **Code** et **Nom du produit** sont obligatoires pour l'API, mais le tableau
ci-dessous donne **tous** les champs du formulaire, dans l'ordre où ils apparaissent,
avec quoi mettre dans chacun. Les champs marqués **← testé** sont ceux dont dépend ce
guide : ne les improvisez pas.

### Section « Identité »

| Champ | Valeur |
|---|---|
| **Code** | `TEST-DUREE` |
| **Nom du produit** | `Produit test durée hebdo` |
| **Garant obligatoire** | décoché — évite d'avoir à saisir un garant |
| **Garantie (objet) obligatoire** | décoché — idem |

### Section « Limites »

| Champ | Valeur | |
|---|---|---|
| **Durée minimale** | `4` | **← testé** |
| **Durée maximale** | `12` | **← testé** |
| **Unité de durée** | **`semaine(s)`** | **← testé** |
| **Fréquences de remboursement autorisées** | cochez **`Hebdomadaire`** | champ déclaratif ; il ne pilote pas l'échéancier |
| **Montant minimum** | `10 000` | |
| **Montant maximum** | `5 000 000` | |
| **Jour d'échéance** | **laisser vide** | ne vaut que pour un produit mensuel ; désormais ignoré en hebdomadaire, où il écraserait toutes les dates sur le même jour du mois |
| **Délai de grâce min. (jours)** | `0` | **← testé** |
| **Délai de grâce max. (jours)** | `30` | **← testé** |

### Section « Frais & taux »

| Champ | Valeur | |
|---|---|---|
| **Taux d'intérêt** | `2` | |
| **Taux de taxe** | laisser vide | |
| **Taux d'assurance** | laisser vide | |
| **Taux des frais de dossier (%)** | `1,5` | pas l'objet de ce guide, mais un produit sans taux donne des frais à 0 et brouille la lecture du prêt |
| **Type de dépôt de garantie** | laisser vide | |
| **Valeur du dépôt de garantie** | laisser vide | |

### Section « Pénalité »

Rien à tester ici. **Laissez tout vide** : ces quatre champs-là forment un groupe, et
en toucher un seul rend les autres obligatoires.

| Champ | Valeur |
|---|---|
| **Type de valeur de pénalité** | laisser vide |
| **Valeur de pénalité** | laisser vide |
| **Base de la formule de pénalité** | laisser vide |
| **Type de formule (descriptif)** | laisser vide |
| **Jours de grâce avant pénalité** | laisser vide — indépendant des quatre ci-dessus |

### Section « Comptabilité »

| Champ | Valeur |
|---|---|
| **Compte comptable par défaut** | n'importe quel compte proposé dans la liste |
| **Politiques de calcul rattachées** (7 listes) | laisser telles quelles |

> Le compte comptable ne sert qu'au **déblocage**, qui n'est pas fait dans ce guide :
> on s'arrête au tableau d'amortissement, qui ne passe aucune écriture. Choisissez
> le premier de la liste, cela n'aura aucun effet sur le test.

### Section « Statut »

| Champ | Valeur |
|---|---|
| **Statut** | **`Actif`** — un produit inactif ne se propose pas dans le formulaire de prêt |

Cliquez **Créer le produit**, puis **rouvrez-le** : « Unité de durée » doit toujours
afficher **semaine(s)**, et les durées **4** / **12**.

---

## 3. Le formulaire de prêt, champ par champ

Vous allez le remplir **plusieurs fois** (trois refus en partie A, puis un prêt réel
en partie B). Voici la base commune — **tous** les champs du formulaire, dans l'ordre.
Seuls **Titulaire**, **Produit de prêt** et **Montant demandé** sont obligatoires.

**Crédit › Prêts** → **Nouveau prêt**, avec **+237690000005**.

### Section « Rattachement »

| Champ | Valeur |
|---|---|
| **Titulaire (client)** | votre client actif et KYC vérifié |
| **Produit de prêt** | `TEST-DUREE` |
| **Agent de crédit** | vous-même (+237690000005) — facultatif ; si la liste est vide, voir l'encadré du seed en section 1 |
| **Date de demande** | laisser la date du jour |

### Section « Montant & échéancier »

| Champ | Valeur | |
|---|---|---|
| **Montant demandé** | `200 000` | |
| **Devise** | `XAF` | |
| **Nombre d'échéances** | *varie selon l'essai* | **← testé** |
| **1re échéance** | **laisser vide** | **← testé**, voir l'encadré en 5.1 |
| **Périodicité (jours)** | **laisser vide** | voir l'encadré en 5.1 |
| **Différé (jours)** | *varie selon l'essai* | **← testé** |
| **Durée totale (jours)** | laisser vide | champ déclaratif, ne pilote rien |

### Section « Comptes rattachés »

**Laissez les quatre listes vides.**

| Champ | Valeur |
|---|---|
| **Compte de virement** | laisser vide |
| **Compte d'amortissement** | laisser vide |
| **Compte des impayés** | laisser vide |
| **Compte de recouvrement** | laisser vide |

> **Votre client n'a pas besoin d'avoir un compte pour ce test.** Ces comptes ne
> servent qu'au **déblocage** et aux **remboursements**, que ce guide ne fait pas :
> on s'arrête au tableau d'amortissement. Un prêt s'enregistre, passe ses quatre
> visas et produit son échéancier sans qu'aucun compte client existe.
>
> Si la section affiche « Sélectionnez d'abord un client », c'est normal et sans
> conséquence ici.

### Section « Activité financée »

Tout est facultatif et sans effet sur ce test : **Objet du prêt**, **Secteur
d'activité**, **Sous-secteur**, **Code activité financée**, **Adresse de l'activité**,
**Adresse de l'entrepreneur**. Mettez `Test durée` dans l'objet, laissez le reste vide.

---

## 4. Partie A — les refus (pas besoin de visas)

Deux façons de faire, au choix : créer un nouveau prêt à chaque essai, ou en créer
un seul et le **rouvrir pour le modifier** entre chaque essai. Les deux passent par
les mêmes contrôles.

Essayez d'enregistrer **trois fois**, en ne changeant que la ligne indiquée :

| Ce que vous saisissez | Attendu |
|---|---|
| **Nombre d'échéances** = `60` | refus sous le champ : *le nombre d'échéances dépasse la durée maximale du produit de prêt* |
| **Nombre d'échéances** = `2` | refus : *…inférieur à la durée minimale…* |
| **Nombre d'échéances** = `8` et **Différé (jours)** = `90` | refus sous « Différé » : *le différé dépasse le maximum du produit de prêt* |

**C'est le cœur du test.** Avant, les trois passaient sans un mot.

> Le message doit apparaître **sous le champ concerné**, pas seulement en haut. Si le
> refus est générique ou muet, notez-le.
>
> ⚠️ Si vous obtenez **« Le champ currency est interdit »** sous *Devise* alors que
> vous n'avez pas touché à ce champ, votre navigateur utilise encore l'ancienne
> version : rechargez la page. C'était un défaut du formulaire de modification, qui
> envoyait la devise et la date de demande — deux champs non modifiables — et faisait
> échouer l'enregistrement avant même que la durée soit examinée.

---

## 5. Partie B — l'échéancier

### 5.1 Créer le prêt pour de bon

Même formulaire qu'en section 3, avec ces trois lignes :

| Champ | Valeur |
|---|---|
| **Nombre d'échéances** | `8` |
| **Différé (jours)** | `20` |
| **1re échéance** | **laisser vide** |

> **Pourquoi laisser « 1re échéance » vide :** si vous donnez une date, c'est elle qui
> s'applique, et le différé n'a plus rien à décaler. Le test ne montrerait rien.
>
> **Pourquoi laisser « Périodicité (jours) » vide :** ce champ ne pilote rien
> aujourd'hui. C'est l'**unité de durée du produit** qui espace les échéances. Le
> champ est trompeur ; il est signalé pour être tranché séparément.

Enregistrez.

### 5.2 Passer les quatre visas

Ouvrez le prêt. Le **Circuit de visa** est le bandeau d'étapes placé au-dessus des
onglets — ce n'est pas un onglet. Approuvez dans l'ordre, **en changeant de compte à
chaque fois** :

1. **Montage** — loan-officer, +237690000005
2. **Comptabilité** — accountant, +237690000006
3. **Contrôle** — compliance-officer, +237690000008
4. **Direction** — agency-manager, +237690000002

Après le visa Direction, le prêt passe au statut **Approuvé**. **Notez la date du
jour** : c'est elle qui sert de base à toutes les dates de l'échéancier.

> Un visa refusé avec « vous avez déjà approuvé une autre étape » n'est pas une
> anomalie : une même personne ne peut en signer qu'un seul. Changez de compte.

### 5.3 Générer l'échéancier

Ouvrez le prêt → onglet **Tableau d'amortissement** → **Générer le tableau**.

> Le bouton refuse de générer tant que le prêt n'est pas **Approuvé** — le message le
> dit (« Le tableau ne peut être généré que sur un prêt approuvé ou rééchelonné »).
>
> Si le bouton **n'apparaît pas du tout** alors que le prêt est approuvé, c'est un
> problème de droits : rejouez le seed de la section 1. Générer un échéancier était
> réservé au seul profil technique, ce qui n'a pas de sens — c'est l'agent de crédit
> ou le chef d'agence qui remet le tableau au client.

### 5.4 Ce qu'il faut vérifier

**a) La 1re échéance = date d'approbation + 20 jours + 1 semaine, soit + 27 jours.**

Le différé de 20 jours est un report : pendant 20 jours le client ne doit rien.
Ensuite seulement le rythme démarre, et la 1re échéance tombe une semaine plus tard.

> Exemple si vous approuvez le **15/09/2026** : 15/09 + 20 j = 05/10, + 1 semaine =
> **12/10/2026**.

**b) Les 8 échéances sont espacées de 7 jours**, pas d'un mois.

En reprenant l'exemple : 12/10, 19/10, 26/10, 02/11, 09/11, 16/11, 23/11, 30/11.

**Avant la correction, on aurait vu des dates espacées d'un mois, et la 1re serait
tombée un mois après l'approbation, différé ou pas.** Ce sont les deux choses à
regarder.

**c) Les montants se répartissent normalement** — 8 lignes, le capital totalisant
`200 000`. Ils n'ont pas changé, et ne doivent pas avoir changé.

---

## 6. Contrôle rapide : le différé sert-il vraiment ?

Le plus convaincant est la comparaison. Refaites **un second prêt identique** (même
produit, `200 000`, `8` échéances) mais **Différé = `0`**, visas, tableau.

**Attendu :** sa 1re échéance tombe **20 jours plus tôt** que celle du premier prêt.

Si les deux tableaux sont identiques, le différé est de nouveau ignoré — c'est
exactement le bug corrigé, et il faut le signaler.

---

## 7. Ce qu'il faut remonter

Pour chaque anomalie : **ce que vous avez saisi**, **ce que vous attendiez**, **ce que
l'écran a affiché**, et la date d'approbation du prêt (sans elle, aucune date n'est
vérifiable).
