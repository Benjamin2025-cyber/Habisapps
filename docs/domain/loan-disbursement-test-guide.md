# Tester : le déblocage d'un prêt

**Pour qui :** un testeur, pas un comptable.

**Durée :** environ 45 minutes, dont 30 de paramétrage à ne faire **qu'une fois**.

---

## Avant tout : pourquoi ça ne marchera pas tout de suite

Un prêt approuvé n'est pas décaissable tant que quatre choses n'existent pas. J'ai
vérifié votre base : **aucune des quatre** n'est en place, donc vos cinq prêts
approuvés sont tous bloqués.

| # | Ce qui manque | Sections |
|---|---|---|
| 1 | Aucun **produit de compte** n'existe | 2 |
| 2 | Le client n'a **aucun compte** pour recevoir les fonds | 3 |
| 3 | Aucune **imputation comptable** — il en faut deux : capital et frais | 4 |
| 4 | Les **frais de dossier** ne sont pas réglés | 5 |

Les sections 2 à 4 sont du paramétrage : faites-les une fois, elles serviront à tous
les prêts suivants. Le vrai test commence à la section 5.

---

## 1. Les comptes

**Connexion par numéro de téléphone**, mot de passe **`password123`**.

| Étape | Se connecter avec | Rôle |
|---|---|---|
| Produit de compte (§2) | **+237690000011** | chief-accountant |
| Compte client (§3) | **+237690000004** | teller |
| Imputation comptable (§4) | **+237690000006** | accountant |
| **Approuver** l'imputation (§4.1) | **+237690000011** | chief-accountant |
| Frais et décaissement (§5, §6) | **+237690000002** | agency-manager |

> **Rejouez d'abord les permissions**, puis **déconnectez-vous et reconnectez-vous** :
> `php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder`
>
> Ouvrir un compte client était réservé au seul profil technique — sept profils
> pouvaient lire les comptes, aucun n'en pouvait créer. C'est corrigé, mais les droits
> sont lus à la connexion : sans reconnexion vous gardez les anciens.

**Une journée comptable ouverte** est nécessaire (bandeau en haut à droite).

---

## 2. Créer un produit de compte

**Référentiel › Produit de compte** → **Nouveau produit**, en **chief-accountant**.

### Identité

| Champ | Valeur |
|---|---|
| **Code** | `CPT-COURANT` |
| **Nom du produit** | `Compte courant client` |
| **Famille** | `Compte courant` |
| **Agence** | votre agence |
| **Devise** | `XAF` |

### Comptabilité

| Champ | Valeur |
|---|---|
| **Compte comptable par défaut** | cherchez `3712` — *Comptes courants clients* |

### Règles

| Champ | Valeur |
|---|---|
| **Solde minimum** | `0` |
| **Épargne ordinaire** / **Compte de recouvrement** | décochés |
| **Autorise le débit de recouvrement** | décoché |
| **Autorise le découvert** | **coché** |
| **Plafond de découvert** | `500 000` |

> **Pourquoi autoriser le découvert :** les frais de dossier sont prélevés sur le
> compte du client **avant** que le prêt ne soit décaissé. À cet instant le compte est
> à zéro. Sans découvert, le règlement des frais échoue — et l'on ne peut pas décaisser
> sans avoir réglé les frais. C'est l'ordre imposé par l'application.

### Statut

**Statut** = `Actif`. Puis **Créer le produit**.

---

## 3. Ouvrir un compte au client

**Référentiel › Compte** → **Nouveau compte**, en **teller** (+237690000004).

### Titulaire & affectation

| Champ | Valeur |
|---|---|
| **Titulaire (client)** | votre client (le même que pour les prêts) |
| **Agence** | la même que le prêt |

### Compte

| Champ | Valeur |
|---|---|
| **Numéro de compte** | `CPT-TEST-001` |
| **Intitulé du compte** | `Compte test déblocage` |
| **Type / Produit de compte** | `CPT-COURANT` |
| **Devise** | **`XAF`** ← doit être identique à celle du prêt |

### Comptabilité (optionnel)

| Champ | Valeur |
|---|---|
| **Compte comptable** | **laisser vide** |

> **Laissez-le vide, c'est voulu.** Le compte reprend automatiquement le compte
> comptable par défaut de son produit — celui que vous avez mis au §2. C'est pour
> cela que le §2 le demandait.
>
> Connecté en **teller**, la liste sera de toute façon sans options : choisir un
> compte du plan comptable demande un droit que le guichet n'a pas, et n'en a pas
> besoin. Ce n'est pas un blocage.
>
> Le décaissement, lui, exige que le compte client ait bien un compte comptable —
> il l'aura, par héritage. Sans produit **et** sans compte comptable, il échouerait
> avec « Transfer account ledger mapping is required before disbursement ».

### Cycle de vie

| Champ | Valeur |
|---|---|
| **Date d'ouverture** | aujourd'hui |
| **Date de clôture** | laisser vide — on ouvre le compte, on ne le ferme pas |
| **Statut** | `Actif` |

Puis **Créer le compte**.

---

## 4. Déclarer l'imputation comptable du décaissement

**Comptabilité › Codes opération & imputations** → onglet **Imputations** →
**Nouvelle imputation**, en **comptable** (+237690000006).

**Il en faut deux**, pas une : le capital et les frais se comptabilisent par des
opérations distinctes. Créez-les l'une après l'autre.

### Imputation 1 — le capital

| Champ | Valeur |
|---|---|
| **Code d'opération** | `loan_principal_disbursement` |
| **Agence** | votre agence |
| **Compte à débiter** | **`3222`** — *Crédits de trésorerie aux clients* |
| **Compte à créditer** | **laisser vide** |
| **Devise** | `XAF` |
| **Effective du** | aujourd'hui |
| **Effective au** | laisser vide |
| **Statut** | `Actif` |
| **Approbation** | laisser tel quel — la valeur se pilote depuis la liste, voir §4.1 |

### Imputation 2 — les frais de dossier

Ici c'est l'inverse : les frais sont un **produit** pour l'institution, donc le compte
renseigné est celui **à créditer**.

| Champ | Valeur |
|---|---|
| **Code d'opération** | `loan_setup_dossier_fee` |
| **Agence** | votre agence |
| **Compte à débiter** | **laisser vide** |
| **Compte à créditer** | **`7153`** — *Commissions sur crédits à court terme* |
| **Devise** | `XAF` |
| **Effective du** | aujourd'hui |
| **Effective au** | laisser vide |
| **Statut** | `Actif` |

> **Pourquoi `7153`.** Les frais de dossier sont une commission perçue sur un crédit à
> court terme — la contrepartie exacte du `3222` de l'imputation 1. Le débit, lui,
> vient du compte du client au moment du règlement.
>
> **Et les deux autres codes ?** `loan_setup_tax` et `loan_setup_guarantee_deposit`
> existent aussi, mais ne servent que si le produit porte une taxe ou un dépôt de
> garantie. Le produit du §2 n'en a aucun : votre prêt n'a qu'un seul frais, et donc
> une seule imputation de frais à déclarer. **Règle générale : une imputation par
> type de frais réellement paramétré sur le produit.**

> **Pourquoi laisser « Compte à créditer » vide.** L'écriture du décaissement a deux
> côtés, et un seul est fixe :
>
> ```
> Débit   3222  Crédits de trésorerie aux clients   200 000   ← cette imputation
> Crédit  3712  Compte courant du client            200 000   ← le compte du client
> ```
>
> Le **débit** ne change jamais : tout prêt débloqué dans cette agence crée une
> créance en `3222`. C'est une règle, donc elle se déclare une fois ici.
>
> Le **crédit**, lui, dépend de l'endroit où va l'argent : le compte du client choisi
> au moment du décaissement, ou la caisse si vous décaissez en espèces. Il ne peut
> pas être fixé à l'avance, et l'application ne lit d'ailleurs que le côté débit de
> cette imputation. Un compte saisi ici serait **ignoré** — pire que vide, puisqu'il
> aurait l'air paramétré.
>
> C'est aussi pour cela que le §3 compte : le compte du client doit porter un compte
> comptable (il hérite du `3712` de son produit). C'est la moitié crédit de cette
> écriture.
>
> **Pourquoi `3222`.** Débloquer un prêt fait naître une créance sur le client : elle
> se loge à l'actif, en classe 3 (opérations avec la clientèle), au débit. `3222` est
> le compte des crédits de trésorerie **aux clients** — celui qui correspond au prêt
> de trésorerie du guide.
>
> **Les voisins immédiats, à ne pas confondre** — même famille, autre bénéficiaire :
>
> | Code | Bénéficiaire |
> |---|---|
> | `3221` | les **sociétaires** (les membres) |
> | **`3222`** | les **clients** ← celui du guide |
> | `32239` | les autres **EMF du réseau** |
>
> Et le compte suit la **nature** du crédit : un prêt d'équipement irait en `3232`
> (*Crédits à l'équipement aux clients*), un crédit à la consommation en `3261`
> (*Crédits à la consommation aux clients*). Pour ce test, le choix ne change aucun
> montant — seulement le compte mouvementé.
>
> Vérifiez que la ligne choisie porte bien **votre agence** : le plan comptable
> répète chaque compte de détail dans chaque agence, et le sélecteur l'affiche
> désormais à la suite du libellé.

### 4.1 L'approuver — l'étape qu'on oublie

L'imputation est créée en **Brouillon**. Dans ce statut elle n'est **pas utilisée** :
le décaissement se comportera comme s'il n'y avait aucune imputation.

**Déconnectez-vous et reconnectez-vous en chef comptable (+237690000011).** Puis,
dans la liste, sur la ligne de l'imputation : menu **Actions de l'imputation** →
**Approuver**.

**Attendu :** colonne **Approbation** = **Approuvée**.

> **Qui fait quoi.** Le comptable d'agence **propose** l'imputation de son agence, le
> chef comptable la **met en service**. C'est exactement le partage que le comptable
> a déjà sur les opérations diverses : il prépare l'écriture, le siège la valide.
>
> Une imputation décide où l'argent se comptabilise tout seul, pour toutes les
> opérations qui suivent : celui qui l'écrit ne peut donc pas l'approuver. Dans cet
> ordre-là, **un seul chef comptable suffit** — il est le valideur, pas l'auteur. Il
> n'y a aucun poste à créer.

---

## 5. Régler les frais, puis décaisser — un seul écran

**Crédit › Déblocage prêt**, en **agency-manager**.

La liste montre les prêts approuvés. Sur la ligne de votre prêt, cliquez
**Décaisser** : **tout se passe dans ce tiroir**, les frais comme le décaissement,
de haut en bas.

> Si votre prêt n'apparaît pas dans la liste, c'est l'imputation du §4 qui n'est pas
> **Approuvée**. Reprenez le §4.1.

### 5.1 En haut : le montant

**Montant à décaisser** — affiché, non modifiable. Ce doit être le capital approuvé,
`200 000 FCFA`.

### 5.2 Le bloc « Frais de dossier »

S'il affiche **« Évaluer les frais »**, cliquez-le : il calcule ce qui est dû
(taux du produit × capital). S'il affiche déjà un montant, l'évaluation est faite.

**Attendu :** `1,5 %` de `200 000` = **3 000 FCFA**, marqué **À régler**.

Puis, pour chaque ligne restant à régler :

| Champ | Valeur |
|---|---|
| **Source de paiement** | `Compte client` |
| **Compte à débiter** | `CPT-TEST-001` |

et cliquez **Régler**.

> **« Dispenser (direction) »** existe à côté : c'est une remise de frais, pas un
> paiement. Ne l'utilisez pas ici — ce serait un autre test.
>
> **Évaluer et Régler sont deux gestes.** Évaluer calcule et n'encaisse rien ; Régler
> prélève. Tant qu'une ligne reste **À régler**, le bandeau annonce le nombre de
> **frais restant(s) à régler avant le décaissement**, et le bouton de confirmation
> refuse.

**Attendu :** la ligne passe à **Réglé**, et le bloc affiche
**« Tous les frais sont réglés. Vous pouvez décaisser. »**

### 5.3 Le bas du tiroir : le décaissement

| Champ | Valeur |
|---|---|
| **Canal de décaissement** | `Virement sur compte` |
| **Compte de virement** | `CPT-TEST-001` |
| **Date comptable** | laisser vide — par défaut aujourd'hui |
| **Notes** | `Test déblocage` |

> **Deux fois le même compte, et c'est normal.** « Compte à débiter » (§5.2) est le
> compte d'où sortent les **frais** ; « Compte de virement » est celui où arrive le
> **capital**. Ici c'est le même compte client, mais ce sont deux mouvements
> distincts.

Cliquez **Confirmer le décaissement**.

---

## 6. Ce qu'il faut vérifier

1. **Statut du prêt** → **Décaissé**, et **Décaissé le** renseigné sur la fiche.
2. **Le compte du client** — *Référentiel › Compte*, ouvrez `CPT-TEST-001` :
   crédité de `200 000`, débité de `3 000` de frais.
3. **L'écriture comptable** — *Comptabilité › Opérations diverses* : `3222` **débité**
   de `200 000`, le compte comptable du client **crédité** d'autant.
4. **Le prêt disparaît** de la liste « Prêts à décaisser ».
5. **Redécaisser est refusé.** Rouvrez le prêt et retentez : l'application doit
   refuser un second décaissement, pas en créer un deuxième.

---

## 7. Le canal espèces (optionnel)

Choisir **Canal de décaissement** = `Espèces (caisse)` fait apparaître un champ
**Session de caisse**, qui exige une session ouverte dans l'agence du prêt. Sans
session, le formulaire l'annonce (« Aucune session de caisse ouverte dans l'agence du
prêt ») et la liste reste vide.

C'est un autre workflow — la caisse — et il n'est pas couvert ici : restez sur
`Virement sur compte`.

---

## 8. Ce qu'il faut remonter

Pour chaque anomalie : **ce que vous avez saisi**, **ce que vous attendiez**, **ce que
l'écran a affiché**.

Et particulièrement : **tout écran qui n'affiche rien sans dire pourquoi** — une liste
vide, un bouton absent, un blocage muet. C'est le défaut le plus fréquent trouvé
jusqu'ici, et celui que seul un testeur peut voir.
