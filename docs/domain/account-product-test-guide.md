# Tester : les produits de compte

**Pour qui :** un testeur. Aucune connaissance comptable requise.

**Durée :** 30 à 40 minutes pour tout parcourir.

**Où :** **Référentiel › Produit de compte**

---

## 0. Avant de commencer

**Connexion par numéro de téléphone**, mot de passe **`password123`**.

| Profil | Numéro | Ce qu'il doit pouvoir faire |
|---|---|---|
| chief-accountant | **+237690000011** | tout : créer, modifier, activer, désactiver, archiver |
| agency-manager | **+237690000002** | **consulter seulement** |
| teller | **+237690000004** | **consulter seulement** — il doit lire le catalogue pour ouvrir un compte |
| user-admin | **+237690000001** | **consulter seulement** |
| accountant | **+237690000006** | **rien** — le menu ne doit pas apparaître |
| loan-officer | **+237690000005** | **rien** — le menu ne doit pas apparaître |

> **Rejouez les permissions et reconnectez-vous** avant de commencer :
> `php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder`
> Les droits sont lus à la connexion.

---

## 1. Ce qu'un produit de compte sert à faire

Un produit de compte est le **modèle** des comptes clients : à l'ouverture d'un compte,
celui-ci hérite du produit sa **devise**, son **type**, son **compte comptable** et son
**découvert autorisé**. Le compte garde ensuite sa copie.

C'est ce qui explique la plupart des règles testées ci-dessous.

---

## 2. Création — le chemin nominal

En **chief-accountant** → **Nouveau produit**.

| Section | Champ | Valeur |
|---|---|---|
| Identité | **Code** | `TEST-EP-01` |
| | **Nom du produit** | `Épargne test` |
| | **Famille** | `Épargne` |
| | **Agence** | votre agence |
| | **Devise** | `XAF` |
| Comptabilité | **Compte comptable par défaut** | cherchez `3712` |
| Règles | **Solde minimum** | `0` |
| | **Autorise le découvert** | décoché |
| Statut | **Statut** | `Actif` |

**Attendu :** bandeau **« Produit créé »**, et la ligne apparaît dans le catalogue avec
son code, sa famille et sa devise.

---

## 3. Les refus attendus

Chacun doit être refusé **sous le champ concerné**, pas dans un bandeau générique.

| # | Ce que vous faites | Attendu |
|---|---|---|
| 3.1 | **Code** vide, ou **Nom** vide | champ obligatoire |
| 3.2 | **Code** = `TEST-EP-01` (déjà pris), même agence | *le code est déjà utilisé dans cette agence* |
| 3.3 | **Code** = `TEST-EP-01` mais **une autre agence** | **accepté** — le code n'est unique que par agence |
| 3.4 | **Devise** = `XA` (2 lettres) ou `XAFR` | code ISO à 3 lettres |
| 3.5 | **Solde minimum** = `-100` | doit être positif ou nul |
| 3.6 | **Plafond de découvert** = `-1` | doit être positif ou nul |

> **3.3 n'est pas un bug.** Chaque agence tient son propre catalogue : deux agences
> peuvent avoir un produit `EPARGNE-01` chacune.

---

## 4. Le découvert autorisé

C'est le champ qui a le plus d'effet réel — il change ce qu'un client peut débiter.

### 4.1 Le champ n'apparaît que s'il sert

1. Créez un produit `TEST-DEC-01`, famille `Compte courant`.
2. **Autorise le découvert** décoché → **Plafond de découvert** est **absent**.
3. Cochez-le → le champ **apparaît**.
4. Renseignez `500 000`, enregistrez.

### 4.2 Le décocher, puis réenregistrer

Rouvrez `TEST-DEC-01`, **décochez** *Autorise le découvert*, **Enregistrer**.

**Attendu : ça enregistre.**

> ⚠️ **C'est le bug signalé par la comptabilité**, désormais corrigé. Avant, on
> obtenait *« Le champ Plafond de découvert doit être un nombre »* — en désignant un
> champ que le formulaire venait justement de masquer. Un produit sans découvert
> n'était donc modifiable qu'une seule fois.

### 4.3 Son effet réel

Le découvert augmente le **solde disponible** d'un compte, sans changer son solde
comptable. Vérifiable plus tard sur un compte rattaché à ce produit :
*Référentiel › Compte* → onglet **Soldes** → **Solde disponible** = solde comptable
**+** plafond de découvert.

---

## 5. Les cases qui ne font rien (pour l'instant)

Dans la section **Règles**, trois cases portent la mention *« Aucune règle de
l'application ne s'en sert pour l'instant »* :

- **Épargne ordinaire**
- **Compte de recouvrement**
- **Autorise le débit de recouvrement**

**Attendu :** elles s'enregistrent et se relisent correctement — cochez, enregistrez,
rouvrez, elles sont toujours cochées. Mais **aucun comportement ne doit en découler**.

> Ce sont des classifications conservées sur le produit. La mention est là pour éviter
> qu'on attende d'elles un effet qu'elles n'ont pas. **Ne les signalez pas comme un
> bug** ; signalez-les si la mention a disparu.

---

## 6. Modification — ce qui se fige, et quand

### 6.1 Toujours figé

Sur la ligne du produit, menu **Actions** → **Modifier**. **Code** et **Agence** sont
**grisés** : ils ne se modifient pas après création.

### 6.2 Figé seulement une fois des comptes ouverts

1. Créez `TEST-FIG-01`, devise `XAF`, famille `Épargne`.
2. **Avant d'ouvrir un compte** : changez la devise en `EUR` → **accepté**.
   Remettez `XAF`.
3. Ouvrez un compte client avec ce produit (*Référentiel › Compte*, en teller).
4. **Après** : retentez la devise en `EUR` → **refusé**, sous le champ *Devise*.
5. Retentez de changer la **Famille** → **refusé** de la même façon.
6. Changez le **Nom** → **accepté**. Ce sont deux champs gelés, pas le produit entier.

> **Pourquoi.** Un compte copie la devise et la famille à son ouverture et garde sa
> copie. Changer le produit ensuite ne migre rien : cela ferait seulement que les
> comptes ouverts demain contredisent ceux d'hier. La devise est la plus sensible —
> un décaissement exige que la devise du compte soit celle du prêt.

### 5.1 Les quatre familles

Le sélecteur **Famille** doit proposer **quatre** valeurs, toutes enregistrables :

| Famille | Essai |
|---|---|
| **Épargne** | déjà testée en §2 |
| **Compte courant** | déjà testée en §4 |
| **Recouvrement** | créez `TEST-REC-01` |
| **Islamique** | créez `TEST-ISL-01` |

**Attendu :** les quatre se créent, et la colonne **Famille** du catalogue affiche la
bonne valeur pour chacune.

> C'est en famille **Recouvrement** que la comptabilité a rencontré le blocage du §4.2 :
> refaites le §4.2 sur `TEST-REC-01` pour confirmer que c'est réglé sur cette
> famille-là aussi.

---

## 7. Statuts

| Action | Depuis la ligne, menu **Actions** | Attendu |
|---|---|---|
| 7.1 | **Désactiver** | statut **Inactif** |
| 7.2 | Ouvrir un compte client | le produit inactif **n'est plus proposé** |
| 7.3 | **Activer** | statut **Actif**, à nouveau proposé |
| 7.4 | **Archiver** | statut **Archivé**, plus proposé non plus |

> Un produit archivé **ne casse pas** les comptes déjà ouverts avec lui : ils gardent
> leur devise, leur compte comptable et leur découvert. L'archivage empêche seulement
> d'en ouvrir de nouveaux.

---

## 8. Filtres et recherche

Dans le catalogue :

| Contrôle | Essai | Attendu |
|---|---|---|
| **Rechercher** | `TEST-EP` | ne laisse que les produits correspondants |
| **Famille** | `Compte courant` | ne laisse que cette famille |
| **Statut** | `Archivé` | ne laisse que les archivés |
| Les trois ensemble | | se combinent sans se contredire |

Le compteur **« n produit(s) »** doit suivre le filtre.

---

## 9. Droits — à faire avec chaque profil

Déconnectez-vous, reconnectez-vous, et regardez **le menu et les boutons**.

| Profil | Menu *Produit de compte* | Bouton *Nouveau produit* | Menu *Actions* sur une ligne |
|---|---|---|---|
| chief-accountant | visible | **présent** | **présent** |
| agency-manager | visible | absent | absent |
| teller | visible | absent | absent |
| user-admin | visible | absent | absent |
| accountant | **absent** | — | — |
| loan-officer | **absent** | — | — |

> **Consulter sans pouvoir modifier est le comportement attendu**, pas une anomalie :
> le guichet doit lire le catalogue pour ouvrir un compte, sans pouvoir le changer.
>
> Ce qu'il faut signaler, en revanche : **un écran vide sans explication**. Une liste
> qui ne dit rien, un menu déroulant sans options et sans message. C'est le défaut le
> plus fréquent trouvé jusqu'ici.

---

## 10. Ce qu'il faut remonter

Pour chaque anomalie : **le profil utilisé**, **ce que vous avez saisi**, **ce que vous
attendiez**, **ce que l'écran a affiché**.

Et surtout : **tout écran qui refuse sans dire pourquoi, ou qui désigne un champ que
vous ne voyez pas à l'écran** — c'était exactement le cas du plafond de découvert.
