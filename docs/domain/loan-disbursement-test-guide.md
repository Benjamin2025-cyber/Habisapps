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
| 3 | Aucune **imputation comptable** pour le décaissement | 4 |
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
| Imputation comptable (§4) | **+237690000011** | chief-accountant |
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
| **Compte comptable** | `3712` — *le même qu'au §2* |

> Marqué « optionnel » par le formulaire, mais **obligatoire ici** : le décaissement
> écrit une écriture comptable, et sans compte comptable rattaché il est refusé avec
> « Transfer account ledger mapping is required before disbursement ».

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
**Nouvelle imputation**, en **chief-accountant**.

| Champ | Valeur |
|---|---|
| **Code d'opération** | `loan_principal_disbursement` |
| **Agence** | votre agence |
| **Compte à débiter** | un compte de crédit à la clientèle : cherchez `Crédits` et prenez-en un **aux clients** |
| **Compte à créditer** | **laisser vide** |
| **Devise** | `XAF` |
| **Effective du** | aujourd'hui |
| **Effective au** | laisser vide |
| **Statut** | `Actif` |
| **Approbation** | laisser tel quel — la valeur se pilote depuis la liste, voir §4.1 |

> **Seul le compte à débiter compte** pour cette opération : le crédit vient du compte
> du client, choisi au moment du décaissement.

### 4.1 L'approuver — l'étape qu'on oublie

L'imputation est créée en **Brouillon**. Dans ce statut elle n'est **pas utilisée** :
le décaissement se comportera comme s'il n'y avait aucune imputation.

Dans la liste, sur sa ligne : menu **Actions de l'imputation** → **Approuver**.

**Attendu :** colonne **Approbation** = **Approuvée**.

---

## 5. Régler les frais de dossier

**Crédit › Déblocage prêt**, en **agency-manager**.

> Si la liste est vide alors que vos prêts sont approuvés, c'est que les frais ne sont
> pas réglés ou que l'imputation du §4 n'est pas approuvée. Reprenez ces deux points.

Ouvrez un prêt. Le bloc **Frais de dossier** apparaît.

1. **Évaluer les frais** — calcule les montants dus (le taux du produit × le capital).
2. Pour chaque ligne **À régler** :
   - **Source de paiement** = `Compte client`
   - **Compte à débiter** = `CPT-TEST-001`
   - **Régler**

**Attendu :** chaque ligne passe à **Réglé**, puis le bloc affiche
**« Tous les frais sont réglés. Vous pouvez décaisser. »**

> **« Évaluer les frais » et « Régler » sont deux gestes distincts.** Évaluer calcule
> et n'encaisse rien ; c'est Régler qui prélève. Un prêt évalué mais non réglé reste
> bloqué au décaissement.

---

## 6. Décaisser

Sur le même écran, bouton **Décaisser**.

| Champ | Valeur |
|---|---|
| **Canal de décaissement** | `Virement sur compte` |
| **Compte de virement** | `CPT-TEST-001` |
| **Date comptable** | la journée comptable ouverte |
| **Notes** | `Test déblocage` |

**Confirmer le décaissement.**

### Ce qu'il faut vérifier

1. **Statut du prêt** → **Décaissé**, et **Décaissé le** renseigné sur la fiche.
2. **Le compte du client a été crédité** du principal — *Référentiel › Compte*,
   ouvrez `CPT-TEST-001` : solde = capital − frais déjà prélevés.
3. **Une écriture comptable existe** — *Comptabilité › Opérations diverses* :
   le compte de crédit à la clientèle **débité**, le `3712` du client **crédité**,
   du montant du principal.
4. **Le prêt disparaît de la liste « Prêts à décaisser »** : il n'est plus à décaisser.
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
