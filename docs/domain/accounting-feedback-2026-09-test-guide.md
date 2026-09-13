# Guide de test — retours comptables du 08/09/2026

Ce guide couvre **tous** les points du document *CORRECTIONS HABISLOAN 08092026* :
bouton d'impression du relevé, solde disponible et solde réel, prélèvement
automatique des frais d'ouverture, aperçu et reçu de caisse, heures des sessions,
et brouillard de caisse ligne par ligne.

Il couvre aussi **deux corrections comptables** trouvées en chemin, qui changent
des chiffres que vous connaissez : le traitement des **extournes** dans les soldes
(§8) et le fait qu'activer des frais d'ouverture **ne facture pas** les comptes
déjà ouverts (§4.6).

Aucune connaissance préalable n'est supposée : chaque étape dit quoi faire, **avec
quel compte se connecter**, et **ce que vous devez voir**. Tous les montants sont
en **francs**, tels qu'ils s'affichent à l'écran.

**Durée :** environ 1 h 30 pour tout parcourir.

---

## 0. Ce qui a changé, en une page

| Point remonté | Avant | Maintenant |
|---|---|---|
| **§1a** Bouton IMPRIMER sur les relevés | Absent | **Bouton « Imprimer »** sur l'onglet *Relevé*, qui imprime **toute la période filtrée**, pas la page affichée |
| **§1b** Solde disponible / solde réel | Un seul « solde disponible » | **Deux chiffres distincts**, servis par l'API : *Solde réel* et *Solde disponible*, avec le découvert autorisé détaillé |
| **§1c** Frais d'ouverture vers un compte de produits | Inexistant | **Champ sur le produit de compte**, prélevé automatiquement à la **première opération de caisse**, avec **un sous-compte possible par type de compte** |
| **§2** Aperçu + reçu | Aperçu seul | **Reçu imprimable** après l'opération, **et depuis n'importe quelle ligne** de la liste des opérations de la session |
| **§3** Heures des sessions | Date seule | Colonnes **Heure d'ouverture** et **Heure de clôture** |
| **§4** Brouillard de caisse | Vue jour par jour des sessions | **Ligne par ligne**, une caisse à la fois, **10 colonnes**, solde progressif, impression **paysage** |
| *(corrigé en chemin)* Extournes | Comptées **deux fois** dans tous les soldes | Une extourne **s'annule** avec l'écriture d'origine |
| *(corrigé en chemin)* Activation des frais | Aurait facturé **tous les comptes existants** | N'engage que les comptes **ouverts après** la mise en place du tarif |
| *(trouvé au test)* Extourne d'un 1er versement avec frais | **Impossible** — erreur technique | Les frais sont **contre-passés avec le versement** |

---

## 1. Mise en place

### 1.1 Les deux migrations et le code d'opération — **à faire en premier**

Sans cette étape, le champ *Frais d'ouverture* n'existe pas en base et l'écran
renverra une erreur. Sur le serveur applicatif :

```bash
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\AccountOperationCodeSeeder
```

**Contrôle :** `php artisan migrate:status` ne doit plus afficher `Pending` pour
`add_account_product_opening_fee_ledger` ni pour
`snapshot_account_opening_fee_on_customer_accounts`.

### 1.2 Comptes de connexion

Mot de passe : **`password123`**. La connexion se fait avec le **numéro de
téléphone**.

| Étape | Se connecter avec | Rôle |
|---|---|---|
| Produit de compte, imputations, plan comptable | **+237690000011** | chief-accountant |
| Caisse, session de caisse, ouverture de compte client | **+237690000002** | agency-manager |
| Versement / retrait, reçus | **+237690000004** | teller |
| Brouillard de caisse (vue complète) | **+237690000002** | agency-manager |
| Brouillard de caisse (périmètre restreint, §7.5) | **+237690000004** | teller |

### 1.3 Le compte de produits à utiliser : **`720`**

Le document demandait le **7611 — Produits sur opérations d'ouverture de comptes**.
Ce numéro **n'est pas dans le plan chargé** dans l'application (celui du manuel de
procédures comptables), et la racine sous laquelle il se placerait est la mauvaise :
**`76` = SUBVENTIONS D'EXPLOITATION ET D'ÉQUILIBRE**, c'est-à-dire de l'argent
**reçu** d'un bailleur ou de l'État, pas de l'argent **facturé** au guichet.

Des frais d'ouverture facturés au client sont une **commission**. Le plan porte
déjà le compte qui convient, imputable et créé pour chaque agence :

> ### `720` — Commissions de tenue de compte
> *(racine 72 — PRODUITS SUR OPÉRATIONS DIVERSES)*

Des frais d'ouverture sont la première des commissions de tenue de compte.
**C'est ce compte qu'il faut mapper au §1.4**, et rien n'est à créer dans le plan.

Classer la commission en 76 laisserait le **résultat juste**, mais mettrait la
ligne de détail — et le **rapprochement COBAC** qui s'y rattache — au mauvais
endroit. C'est pour cela que nous n'avons pas suivi le numéro à la lettre.

**Et la ventilation par type de compte ?** Elle est obtenue **sans toucher au
plan** : chaque produit de compte peut désigner son propre compte de produits
(§4.4). Vous pouvez donc tout passer par `720` aujourd'hui et ventiler plus tard,
sans reprise de données.

> **Si votre manuel de procédures porte réellement un `7611 — Produits sur
> opérations d'ouverture de comptes`, dites-le nous.** Cela voudrait dire que le
> plan chargé dans l'application n'est pas à jour : c'est alors le **plan** qu'il
> faut corriger, pas le paramétrage — et le §4 fonctionnera à l'identique une fois
> le compte présent.

**Dans tous les cas**, le compte retenu doit être **rattaché à un compte
réglementaire EMF** (*Comptabilité › Comptes généraux* › correspondance EMF).
L'état réglementaire COBAC ne retient que les comptes ayant une correspondance
active : un compte non rattaché **disparaît simplement** de l'état, sans message.

### 1.4 L'imputation « frais d'ouverture de compte » — en **chief-accountant**

*Comptabilité › **Codes opération & imputations*** › onglet **Imputations** ›
**« + Nouvelle imputation »**.

| Champ | Valeur |
|---|---|
| Code d'opération | **`account_opening_fee`** — libellé *Frais d'ouverture de compte* |
| Agence | la vôtre |
| Sens | **Crédit** |
| Compte crédit | **`720` — Commissions de tenue de compte** (§1.3) |
| Devise | `XAF` |

> 🔴 **Une imputation enregistrée est en « Brouillon », et une imputation en
> brouillon n'est pas vue par le moteur.** Sur la ligne : **⋮ › Approuver**. La
> colonne *Approbation* doit afficher **« Approuvée »**.

**Contrôle :** dans l'onglet **Imputations**, la ligne `account_opening_fee`
affiche **Actif** et **Approuvée**, avec **`720`** dans la colonne *Compte crédit*.

> **À noter — un écran manque.** L'API sait dire si les imputations d'une agence
> sont complètes (« état de préparation »), et `account_opening_fee` y a été ajouté
> pour que le code d'ouverture de compte y figure comme les codes de prêt. Mais
> **aucun écran ne consulte cet état** : il n'y a pas de bouton à cliquer. En
> pratique, le premier signal qu'une imputation manque reste **le refus du
> versement au guichet** (§4.5.1). Dites-nous si vous voulez cet écran — c'est un
> ajout court, et c'est lui qui vous éviterait de découvrir le problème au comptoir.

### 1.5 Une caisse et une session ouvertes — en **agency-manager**

*Opérations courantes › **Caisses*** › créer une caisse si vous n'en avez pas,
puis *Opérations courantes › **Sessions de caisse*** › **Ouvrir une session** avec :

| Champ | Valeur |
|---|---|
| Caisse | la vôtre |
| Caissier | **Test Teller** |
| Fonds d'ouverture | **200 000** |
| Devise | `XAF` |

Ces 200 000 servent de point de départ à tous les contrôles du §7.

---

## 2. §1b — Solde disponible et solde réel

*Référentiel › **Compte*** › ouvrez un compte client › onglet **Soldes**.

**Attendu :** sept cartes, dans cet ordre :

| Carte | Ce que c'est |
|---|---|
| **Solde réel** | ce que le compte détient effectivement |
| **Solde disponible** | ce qui peut sortir du guichet |
| Solde comptable | même chiffre que le solde réel |
| Solde minimum | le minimum imposé par le produit |
| Mises en attente actives | les blocages en cours |
| Montant indisponible | les montants gelés sur le compte |
| **Découvert autorisé** | la facilité accordée par le produit |

Sous les cartes, une phrase explique la relation entre les deux :

> *Le solde réel est ce que le compte détient effectivement. Le solde disponible
> est ce qui peut sortir du guichet : le solde réel diminué du minimum en compte,
> des mises en attente et des montants indisponibles, augmenté du découvert
> autorisé.*

### Le contrôle qui compte

Le document décrivait le solde réel comme *« solde disponible + minimum en
compte »*. C'est vrai **tant qu'il n'y a ni blocage ni découvert**. Vérifiez que
l'écran ne se contente pas de cette addition :

| # | Montage | Attendu |
|---|---|---|
| 2.1 | Compte à **0**, produit **sans** découvert, **sans** minimum | Réel = **0**, Disponible = **0** |
| 2.2 | Compte à **0**, produit **avec découvert autorisé 500 000** | Réel = **0**, Disponible = **500 000** |
| 2.3 | Compte à **100 000**, **mise en attente de 40 000** (onglet *Mises en attente*) | Réel = **100 000**, Disponible = **60 000** |

> 🔴 **Un « Solde réel » de 500 000 au cas 2.2 est une anomalie.** Cela voudrait
> dire que l'écran additionne le découvert à l'argent du client : il afficherait
> l'argent de l'institution comme étant celui du client.

---

## 3. §1a — Le bouton IMPRIMER du relevé

Même fiche compte › onglet **Relevé**.

1. Renseignez **Du** / **Au** sur une période qui contient des mouvements.
2. Cliquez **Imprimer** (à droite des filtres).

**Attendu :** un nouvel onglet s'ouvre avec un document A4 à en-tête HabisLoan, la
période en sous-titre, un bandeau *Solde d'ouverture / Total débits / Total crédits
/ Solde de clôture / Nombre de mouvements*, puis le tableau des mouvements. La
boîte d'impression du navigateur s'ouvre seule : choisissez une imprimante ou
**Enregistrer au format PDF**.

### Le contrôle qui compte

Prenez un compte ayant **plus de 50 mouvements** sur la période.

- À l'écran, le tableau est paginé par 50.
- Le document imprimé doit contenir **tous** les mouvements de la période, et le
  *Nombre de mouvements* du bandeau doit correspondre au **nombre de lignes
  réellement imprimées**.

> Pendant la préparation, le bouton affiche **« Chargement… »** : l'application
> parcourt les pages avant d'ouvrir la fenêtre. C'est normal sur un gros compte.

> 🔴 **Un document de 50 lignes sous un bandeau annonçant 300 mouvements est une
> anomalie.** C'était le comportement avant correction.

---

## 4. §1c — Les frais d'ouverture de compte

### 4.1 Paramétrer le produit — en **chief-accountant**

*Référentiel › **Produit de compte*** › ouvrez un produit d'épargne › **Modifier**.

Section **Comptabilité**, deux champs nouveaux :

| Champ | Valeur pour le test |
|---|---|
| **Frais d'ouverture de compte** | **5 000** |
| **Compte de produits des frais d'ouverture** | laissez **vide** pour l'instant |

Le second champ n'apparaît **que si** les frais sont supérieurs à zéro. Laissé
vide, le prélèvement utilise l'imputation d'agence du §1.4.

**Contrôle :** dans la liste des produits, la colonne **Frais d'ouverture** affiche
**5 000 FCFA** sur cette ligne.

### 4.2 Ouvrir un compte et vérifier l'annonce

En **agency-manager** : *Référentiel › **Compte*** › **Nouveau compte**, sur un
client existant, avec le produit paramétré au §4.1.

**Attendu :** sur la fiche du compte créé, onglet **Infos**, section
*Comptabilité*, le champ **Frais d'ouverture à prélever** affiche **5 000 FCFA**.
Vous les reverrez à l'aperçu du versement (§5).

### 4.3 La première opération déclenche le virement

En **teller** : *Opérations courantes › **Retrait / Versement***, onglet
**Versement**, sur le compte du §4.2, **100 000 FCFA**.

**Attendu, dans l'ordre :**

1. À l'**Aperçu de l'opération**, le récapitulatif porte une ligne
   **Frais d'ouverture de compte — 5 000 FCFA**, suivie de la phrase expliquant
   qu'ils sont prélevés si le solde le permet.
2. Après validation, le panneau **Reçu de l'opération** affiche la même ligne.
3. Sur la fiche du compte, onglet **Soldes** : **Solde réel = 95 000**
   (100 000 versés − 5 000 de frais). Onglet **Infos** : *Frais d'ouverture à
   prélever* est retombé à **0**, et **Frais d'ouverture prélevés le** affiche la
   date du jour.
4. Onglet **Relevé** : **deux** mouvements — le versement de 100 000 au crédit,
   et les frais de 5 000 au débit, avec leur propre référence commençant par
   **`AOF-`**.
5. *Comptabilité › Comptes généraux* › **`720`** : son solde a augmenté de
   **5 000**.

> Les frais sont une **écriture distincte** du versement, pas une ligne ajoutée
> dedans. Le journal du versement doit rester équilibré à **100 000 / 100 000**.

### 4.4 Un sous-compte par type de compte

C'est le *« pouvant avoir plusieurs sous-comptes selon les types de comptes »* du
document.

1. Créez un sous-compte de commissions dédié (par ex. `7201` sous `720`), ou
   prenez un autre compte de commissions de la classe 72.
2. Sur un **second** produit de compte — un compte courant, par exemple — mettez
   des frais d'ouverture **et** renseignez cette fois le champ **Compte de
   produits des frais d'ouverture**.
3. Ouvrez un compte sur ce second produit, faites un premier versement.

**Attendu :** les frais de ce compte créditent **le compte désigné sur le produit**,
pas celui de l'imputation d'agence. Les deux types de compte alimentent donc deux
sous-comptes différents.

### 4.5 Les refus attendus

| # | Ce que vous faites | Attendu |
|---|---|---|
| 4.5.1 | Supprimez (ou dé-approuvez) l'imputation `account_opening_fee`, puis tentez un premier versement sur un compte avec frais en attente | **Le versement est refusé**, avec un message sur le mappage d'opération. **Aucune** opération n'est enregistrée |
| 4.5.2 | Désactivez le compte de produits désigné sur un produit, puis tentez un premier versement | **Refusé** de la même manière — le système ne bascule **pas** silencieusement sur l'imputation d'agence |
| 4.5.3 | Rétablissez l'imputation, refaites le versement | Il passe, et les frais sont prélevés |

> **Pourquoi refuser plutôt que passer sans les frais ?** Des frais qui
> disparaissent en silence ne se voient jamais. Un versement refusé, si.

### 4.6 🔴 Le contrôle le plus important : les comptes déjà ouverts

C'est le piège que la correction ferme. À faire **exactement dans cet ordre** :

1. Prenez un produit de compte **sans** frais d'ouverture (ou remettez les frais à
   vide) et **ouvrez un compte client** dessus. Faites-lui un versement, puis un
   second.
2. Maintenant **seulement**, mettez **5 000** de frais d'ouverture sur ce produit.
3. Retournez sur le compte ouvert à l'étape 1 et faites-lui **un nouveau
   versement**.

**Attendu :** **aucun frais n'est prélevé**. Sur la fiche du compte, onglet
**Infos**, le champ **Frais d'ouverture à prélever** affiche **0 FCFA** ; le reçu
ne porte pas de ligne de frais, et aucune écriture `AOF-` n'apparaît.

4. Ouvrez **un nouveau compte** sur ce même produit, et faites-lui un versement.

**Attendu :** celui-là **est** prélevé de 5 000.

> **La règle :** ce qu'un client doit pour l'ouverture, c'est ce que disait le
> tarif **le jour où il a ouvert**, pas ce qu'il dit aujourd'hui.
>
> 🔴 **Si l'étape 3 prélève 5 000, arrêtez le test et signalez-le.** Cela
> signifierait qu'activer des frais facture rétroactivement **tout le portefeuille
> déjà ouvert** dès son prochain versement.

### 4.7 Prélevés une fois, et jamais sur de l'argent bloqué

| # | Ce que vous faites | Attendu |
|---|---|---|
| 4.7.1 | Un **second** versement sur un compte déjà prélevé | **Aucun** nouveau prélèvement, aucune écriture `AOF-` supplémentaire |
| 4.7.2 | **Extournez** le tout premier versement, puis refaites-en un | L'extourne **annule aussi les frais** : le compte revient à son état d'avant. Le versement suivant **les prélève de nouveau** — rien n'est perdu |
| 4.7.3 | Premier versement de **2 000** sur un compte dont les frais sont de **5 000** | Le prélèvement est **reporté** : pas d'écriture, le compte n'est pas mis en négatif. Le versement suivant qui couvre les frais les prélève |
| 4.7.4 | Compte à 100 000 avec une **mise en attente de 98 000**, frais de 5 000 | Le prélèvement est **reporté** : les 2 000 réellement libres ne couvrent pas les frais. Une mise en attente est de l'argent gagé, pas de l'argent facturable |

---

## 5. §2 — Aperçu de l'opération et reçu de caisse

En **teller** : *Opérations courantes › **Retrait / Versement***.

### 5.1 L'aperçu

Remplissez une opération, puis **Aperçu de l'opération**.

**Attendu :** une fenêtre récapitulant *Opération*, *Titulaire*, *Compte*,
*Montant*, le **montant en lettres**, et le cas échéant les **frais d'ouverture**.
Rien n'est enregistré tant que vous n'avez pas confirmé.

### 5.2 Le reçu après l'opération

Après validation, un panneau vert **Reçu de l'opération** apparaît sous le
formulaire, avec **Imprimer le reçu** et **Nouvelle opération**.

**Attendu du document imprimé :** en-tête HabisLoan, *N° de pièce*, *Date*,
*Opération*, puis *Compte*, *Titulaire*, *Montant*, *Montant en lettres*, les
*Frais d'ouverture* le cas échéant, et le *Statut*.

> 🔴 **Le compte et le titulaire doivent être un numéro de compte et un nom.** Un
> code long du type `01M0…` à la place du titulaire est une anomalie.

### 5.3 Réimprimer n'importe quelle opération de la session

C'est le *« après toute opération saisie »* du document : le client revient
chercher son reçu alors que le caissier est passé au suivant.

Dans le tableau **Opérations de la session**, en bas de l'écran, chaque ligne porte
maintenant une action **Imprimer**.

| # | Ce que vous faites | Attendu |
|---|---|---|
| 5.3.1 | **Imprimer** sur une ligne ancienne de la session | Le reçu de **cette** opération, avec son propre numéro de pièce |
| 5.3.2 | **Imprimer** sur une opération **extournée** | Le reçu sort avec **Statut : Extournée** |

> Le reçu réimprimé ne porte **pas** la ligne de frais d'ouverture : cette
> information n'accompagne que la réponse de l'opération qui les a déclenchés.
> C'est voulu — mieux vaut ne rien imprimer qu'un montant non vérifié.

---

## 6. §3 — Les heures des sessions de caisse

*Opérations courantes › **Sessions de caisse***.

**Attendu :** le tableau porte deux colonnes nouvelles, entre *Date* et *Fonds
d'ouverture* :

| Colonne | Contenu |
|---|---|
| **Heure d'ouverture** | `HH:mm` — l'heure réelle d'ouverture |
| **Heure de clôture** | `HH:mm`, ou **—** tant que la session est ouverte |

**Contrôle :** ouvrez une session, notez l'heure sur votre montre, rafraîchissez :
la colonne doit afficher cette heure-là. Clôturez, et la seconde colonne se
remplit.

---

## 7. §4 — Le brouillard de caisse

*Édition › **Brouillard de caisse***.

L'écran demande d'abord **une caisse** — c'est la *« possibilité de sélectionner
une seule caisse »* du document. Tant qu'aucune n'est choisie, le tableau invite à
en sélectionner une.

### 7.1 Les dix colonnes

Sélectionnez votre caisse. **Attendu :**

| # | Colonne | Contenu |
|---|---|---|
| 1 | **N° d'ordre** | 1, 2, 3… dans l'ordre où l'argent a bougé |
| 2 | **Référence / N° de pièce** | `CD-…`, `CW-…`, `…-REV` |
| 3 | **Date** | la date comptable de l'opération |
| 4 | **N° de compte** | le compte client mouvementé |
| 5 | **Libellé** | ce que le caissier a saisi ; à défaut, la nature de l'opération **en français** |
| 6 | **Montant débité** | les entrées de caisse |
| 7 | **Montant crédité** | les sorties de caisse |
| 8 | **Solde progressif** | la position du tiroir après cette ligne |
| 9 | **Code guichet** | le code de l'**agence** |
| 10 | **Caissier** | le **nom** du caissier |

Une onzième colonne, **Code caisse**, accompagne le code guichet.

> **Pourquoi deux codes ?** En pratique bancaire, le *guichet* d'un RIB est
> l'agence, pas la caisse. Les deux sont affichés pour lever l'ambiguïté ; dites-nous
> lequel doit rester sur le document imprimé.

> 🔴 **Un libellé en anglais du type `Cash deposit ACC-001` est une anomalie.**
> Quand le caissier n'a rien saisi, la colonne doit afficher *Versement*, *Retrait*
> ou *Extourne*.

### 7.2 Le solde progressif — le contrôle central

Avec la session du §1.5 (fonds d'ouverture **200 000**), enchaînez :

| Opération | Solde progressif attendu |
|---|---|
| *(ouverture)* | 200 000 |
| Versement **100 000** | **300 000** |
| Retrait **30 000** | **270 000** |

Le bandeau du haut affiche **Solde d'ouverture du journal = 200 000** et le
**Solde progressif** final = **270 000**.

**Le contrôle qui compte :** allez sur *Opérations courantes › **Sessions de
caisse***, ligne de votre session, colonne **Solde théorique**. Elle doit afficher
**exactement 270 000** — le même chiffre que la dernière ligne du brouillard.

> 🔴 **Un écart entre les deux est une anomalie de premier ordre.** Le solde
> théorique est ce que la clôture de session compare aux espèces comptées : si le
> brouillard dit autre chose, l'un des deux ment au caissier.

### 7.3 Une extourne n'annule qu'une fois

Faites un versement de **50 000**, puis **extournez-le** depuis
*Retrait / Versement* › *Opérations de la session* › **Extourner**.

Revenez au brouillard. **Attendu :**

- l'opération d'origine reste **affichée**, statut **Extournée**, avec son montant
  de 50 000 en *Montant débité* ;
- l'extourne apparaît sur **sa propre ligne**, 50 000 en *Montant crédité* ;
- le **solde progressif ne bouge sur aucune des deux** : il reste à 270 000 avant
  et après la paire.

> 🔴 **Un solde progressif qui descend à 220 000 est une anomalie** : cela
> retrancherait deux fois les mêmes 50 000, et le brouillard finirait la journée
> en dessous du tiroir réel de tout ce qui a été extourné.

### 7.4 L'impression

Bouton **Imprimer / PDF** en haut à droite.

**Attendu :**

- le document sort en **paysage** — dix colonnes ne tiennent pas en portrait ;
- le bandeau porte la **Caisse**, le **Code guichet**, le **Solde d'ouverture** et
  le **Nombre d'opérations** ;
- l'en-tête du tableau **se répète sur chaque page** ;
- comme pour le relevé (§3), l'impression couvre **toute la fenêtre filtrée**, pas
  la page affichée : le *Nombre d'opérations* doit égaler le nombre de lignes
  imprimées.

### 7.5 Les filtres et le périmètre

| # | Ce que vous faites | Attendu |
|---|---|---|
| 7.5.1 | **Du** / **Au** sur une journée précédente | Le **Solde d'ouverture du journal** est la position **avant** la fenêtre, pas zéro |
| 7.5.2 | Une période **antérieure** à toute activité de la caisse | Solde d'ouverture = **0**, aucune ligne |
| 7.5.3 | *(facultatif — demande une caisse à plus de **100** opérations)* **Page 2** | Les **n° d'ordre continuent** (101, 102…) et le **solde progressif continue** — il ne repart pas de zéro |
| 7.5.4 | Connectez-vous en **teller** et ouvrez le brouillard de la même caisse | Le caissier voit **les lignes de ses propres sessions**, et un brouillard **vide** pour une caisse qu'il n'a jamais tenue — **pas** un refus d'accès |
| 7.5.5 | Un **agency-manager d'une autre agence** | **Accès refusé** |

> 7.5.4 n'est pas un bug : un caissier travaille souvent une caisse partagée. Le
> refuser entièrement l'enfermerait dehors dès qu'un collègue y a tenu une session.

---

## 8. Les extournes dans les soldes — correction de fond

Cette correction ne figure pas dans le document de retours : elle a été trouvée en
le mettant en œuvre. **Elle change des chiffres que vous connaissez**, d'où ce
contrôle dédié.

**Ce qui se passait :** une écriture extournée sortait des soldes *et* son écriture
de contre-passation s'y appliquait. Chaque extourne était donc comptée **deux
fois**.

**Le test :**

1. Sur un compte client à **0**, faites un **versement de 100 000**.
   → *Solde réel* = **100 000** (ou **95 000** si des frais d'ouverture s'appliquent).
2. **Extournez** ce versement.
3. Retournez sur l'onglet **Soldes**.

**Attendu :** *Solde réel* = **0**. Le versement est annulé et, s'il avait
déclenché des frais d'ouverture, ceux-ci sont **contre-passés avec lui** : le
compte revient exactement où il était.

> 🔴 **Un solde de −100 000 est l'anomalie corrigée.** C'était le comportement
> précédent.
>
> 🔴 **Une erreur technique au moment d'extourner** (« would be overdrawn »)
> l'est aussi. Des frais laissés en place sur un compte vidé par l'extourne
> mettaient le solde en négatif, ce que la base refuse : le versement devenait
> impossible à extourner. Les frais sont désormais annulés avec lui.

**Sur le relevé :** onglet *Relevé*, les **deux** écritures apparaissent — le
versement et sa contre-passation — et celle d'origine porte une étiquette
**Extournée**. C'est voulu : sous OHADA/COBAC une écriture comptabilisée ne
disparaît jamais, elle se contre-passe. Les deux lignes doivent apparaître, et leur
somme doit valoir le solde de clôture.

**À contrôler ailleurs aussi**, sur une agence ayant des extournes :

| Écran | Contrôle |
|---|---|
| *Édition › **Balance des comptes*** | La balance doit **s'équilibrer**, débits = crédits |
| Fiche d'un compte général | Le solde ne doit pas avoir « trop reculé » du montant des extournes |
| Tableau de bord, encaisse | Cohérent avec les caisses |

---

## 9. Ce qui doit vous faire lever la main

- Un **solde réel** qui inclut le **découvert autorisé** (§2.2).
- Un relevé ou un brouillard imprimé qui contient **moins de lignes** que le nombre
  annoncé dans son bandeau (§3, §7.4).
- Des **frais d'ouverture prélevés sur un compte ouvert avant** la mise en place du
  tarif (§4.6) — **le signalement le plus urgent de ce guide**.
- Des frais prélevés **deux fois**, ou repris après une extourne (§4.7).
- Des frais prélevés alors que le solde est **entièrement sous mise en attente**
  (§4.7.4).
- Un versement qui **passe sans frais** alors que l'imputation manque : le système
  doit **refuser** (§4.5).
- Un **écart** entre le solde progressif final du brouillard et le **solde
  théorique** de la session (§7.2).
- Un solde progressif qui **recule** sur une paire extournée (§7.3).
- Un **solde négatif** sur un compte client après une simple extourne (§8).
- Un **identifiant technique** (`01M0…`) là où un nom, un numéro de compte ou un
  code est attendu — en particulier *Titulaire* et *Compte* sur les reçus, et
  *Caissier* dans le brouillard.
- Un **libellé en anglais** dans la colonne *Libellé* du brouillard (§7.1).
- Un brouillard imprimé en **portrait**, colonnes écrasées (§7.4).

---

## 10. Ce que ce guide ne couvre pas

- **La mise à jour du plan comptable.** Le compte de produits à utiliser est
  tranché au §1.3 (`720`). Si votre manuel porte un numéro différent, c'est le
  plan chargé qu'il faut reprendre, et cela sort de ce guide.
- **Les opérations diverses de caisse (OD)** ne déclenchent pas le prélèvement des
  frais d'ouverture. Une OD peut toucher plusieurs comptes à la fois : le
  rattacher à l'un d'eux serait arbitraire. Les frais restent dus et seront pris
  au premier versement ou retrait.
- **L'annulation de frais prélevés à tort** reste une écriture manuelle : il n'y a
  pas de bouton pour rembourser des frais d'ouverture.
- **La notification SMS/e-mail** du client ne mentionne pas les frais d'ouverture.
  Dites-nous si elle doit le faire.
- **Le libellé du document imprimé** : *Code guichet* et *Code caisse* sont tous
  deux présents dans le brouillard (§7.1). Si un seul doit rester à l'impression,
  indiquez lequel.
- **L'écran d'état de préparation des imputations** n'existe pas (§1.4). Les
  imputations manquantes se découvrent au guichet, par le refus.

---

## 11. Fiche de relevé

À remplir pendant le test et à nous renvoyer.

| § | Point | OK / KO | Observation |
|---|---|---|---|
| 1.3 | Compte `720` mappé et rattaché à un compte réglementaire EMF | | |
| 2 | Solde réel et solde disponible, cas 2.1 / 2.2 / 2.3 | | |
| 3 | Impression du relevé, période complète | | |
| 4.1–4.3 | Paramétrage et premier prélèvement | | |
| 4.4 | Sous-compte par type de compte | | |
| 4.5 | Refus quand l'imputation manque | | |
| **4.6** | **Comptes ouverts avant le tarif non facturés** | | |
| 4.7 | Une seule fois, report, mises en attente | | |
| 5 | Aperçu, reçu, réimpression | | |
| 6 | Heures d'ouverture / clôture | | |
| 7.1–7.2 | Dix colonnes, solde progressif = solde théorique | | |
| 7.3 | Paire extournée neutre | | |
| 7.4 | Impression paysage, période complète | | |
| 7.5 | Filtres, pagination, périmètre caissier | | |
| **8** | **Solde après extourne** | | |
