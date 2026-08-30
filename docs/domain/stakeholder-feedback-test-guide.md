# Guide de test — retours du comité (août 2026)

Ce guide couvre **tous** les points remontés dans le dernier document de retours :
formulaire du produit de prêt, création d'un prêt, frais de mise en place, comptes
comptables, pénalités, fiche compte client, billetage et documents KYC.

Aucune connaissance préalable n'est supposée : chaque étape dit quoi faire, **avec
quel compte se connecter**, et **ce que vous devez voir**. Les montants indiqués
sont ceux que vous devez lire à l'écran, en francs.

---

## 0. Ce qui a changé, en une page

| Point remonté | Avant | Maintenant |
|---|---|---|
| Type de valeur de pénalité | Deux champs descriptifs, non utilisés | **Une seule règle** : 5 000 F + 2 % de l'impayé, identique pour tous les crédits |
| Type de formule descriptif | Champs libres incompréhensibles | **Supprimés** |
| Politiques de calcul rattachées | À sélectionner produit par produit | **Imposées automatiquement**, ni saisissables ni affichées |
| TVA sur frais de dossier | Inexistante | **Champ dédié**, 19,25 % par défaut, distinct de la TVA du crédit |
| Compte comptable par défaut | Champ sur le produit | **Supprimé** — chaque crédit ouvre ses propres comptes |
| Valeur du dépôt de garantie | Pourcentage **ou** montant fixe | **Pourcentage uniquement** |
| Périodicité / différé / durée totale | Saisis à la main | **Déduits** du nombre d'échéances et de la **1re échéance** |
| Code activité financée | Champ à saisir | **Supprimé** — secteur et sous-secteur suffisent |
| Numéro du client | Sans lien avec la comptabilité | **C'est son compte comptable** |
| Billetage | Montants divisés par 100 | **Corrigé** |
| Documents KYC | Liste sans consultation | **Clic sur la ligne** → visuel + caractéristiques |

---

## 1. Comptes et mise en place de l'environnement

Un prêt passe **quatre visas**, et **une même personne ne peut en signer qu'un
seul**. Il faut donc plusieurs utilisateurs. Mot de passe : **`password123`**.
La connexion se fait avec le **numéro de téléphone**.

| Étape | Se connecter avec | Rôle |
|---|---|---|
| Produit de prêt, plan comptable | **+237690000011** | chief-accountant |
| Créer le client, créer le prêt | **+237690000005** | loan-officer |
| Soumettre le KYC | **+237690000007** | kyc-officer |
| Valider le KYC | **+237690000008** | compliance-officer |
| Visa **Montage** | **+237690000005** | loan-officer |
| Visa **Comptabilité** | **+237690000006** | accountant |
| Visa **Contrôle** | **+237690000008** | compliance-officer |
| Visa **Direction** | **+237690000002** | agency-manager |
| Frais, déblocage | **+237690000002** | agency-manager |
| Session de caisse, billetage | **+237690000004** | teller |
| **Traitement des arriérés** (batch, §6) | **+237699100000** | platform-admin (*Bootstrap Admin*) |

> **Pourquoi un compte d'administration pour le §6 ?** L'évaluation des arriérés
> est un **traitement de fin de journée / fin de mois** (voir §6), pas une action
> de guichet. Elle est volontairement fermée aux rôles front-office (agent de
> crédit, caissier) et n'est lancée à la main que par un profil back-office
> habilité — ici le `platform-admin`. Aucun des dix comptes ci-dessus ne peut la
> lancer ; utilisez le compte d'administration pour cette étape uniquement.

> Un visa refusé avec « vous avez déjà approuvé une autre étape » n'est **pas** une
> anomalie : changez d'utilisateur.

> ⚠️ **Si la date du jour n'est pas le `26/08/2026`.** Tous les montants et toutes
> les durées de ce guide sont calés sur une demande datée du **26/08/2026**. Sur un
> environnement daté autrement, **saisissez explicitement `26/08/2026` dans « Date de
> demande »** à chaque création de prêt : sinon les valeurs attendues aux §3 et §6
> seront décalées d'autant de jours.

---

### Les cinq pré-requis à créer

**Cette section n'est pas optionnelle.** Cinq éléments doivent exister *avant* de
commencer, et aucun n'est créé automatiquement. Chacun se fait entièrement à
l'écran — **aucune manipulation en base n'est nécessaire**. Faites-les dans
l'ordre : chaque étape dépend de la précédente.

#### 1.1 La journée comptable

Bandeau en haut à droite : il doit afficher **« Journée ouverte »**.
Sinon : *Administration › **Journée Comptable*** et ouvrez-la.

#### 1.2 Les cinq imputations comptables — en **chief-accountant** (`+237690000011`)

*Comptabilité › **Codes opération & imputations*** › onglet **Imputations** ›
bouton **« + Nouvelle imputation »**. Il en faut **cinq**, une par code :

| Code d'opération | Sens | Compte à renseigner (plan PCEMF) |
|---|---|---|
| `loan_principal_disbursement` | **Débit** | `3222` — Crédits de trésorerie aux clients |
| `loan_setup_dossier_fee` | Crédit | `7153` — Commissions sur crédits à court terme |
| `loan_setup_principal_tax` | Crédit | `4303` — État, TVA facturée |
| `loan_setup_tax` | Crédit | `4303` — État, TVA facturée |
| `loan_setup_guarantee_deposit` | Crédit | `18700` — Dépôts de garantie reçus |

Dans chaque imputation : choisissez le **code**, puis l'**agence** (`TEST-HABIS`),
puis le compte — **côté débit ou côté crédit selon le sens du tableau**, pas les
deux. Le même code de compte existe pour plusieurs agences : prenez bien celui
suffixé **`· TEST-HABIS`**.

> 🔴 **Une imputation enregistrée est en « Brouillon » — et une imputation en
> brouillon n'est pas vue par le moteur.** Le formulaire ne propose que
> *Brouillon* et *Soumise* : l'approbation est une action séparée. Sur la ligne,
> ouvrez le menu **⋮ › Approuver**. La colonne **Approbation** doit afficher
> **« Approuvée »**.
>
> Le chief-accountant peut créer **et** approuver. L'accountant, lui, peut créer
> mais **pas** approuver — avec ce compte les imputations resteraient en brouillon
> et le déblocage échouerait sans raison visible.

**Contrôle :** l'onglet Imputations liste **5 imputations**, toutes **Actif** et
**Approuvée**. Sans elles, rien ne se débloque — et c'est voulu.

#### 1.3 Une caisse — en **agency-manager** (`+237690000002`)

**Sans caisse, le bouton « Ouvrir une session » reste grisé et le §8 est
intestable.** Le teller ne peut pas la créer lui-même (il n'a que la consultation).

*Opérations courantes › **Caisses*** › bouton **« Nouvelle caisse »**.

> ⚠️ **Renseignez l'Agence en premier.** Changer l'agence **vide** les champs
> *Caissier assigné* et *Compte comptable* — ils dépendent de l'agence. Si vous les
> remplissez avant, ils seront silencieusement effacés à l'enregistrement et la
> caisse sera créée sans caissier ni compte.
>
> L'agence est **obligatoire**, malgré la mention « Requise pour un administrateur
> sans agence » sous le champ.

Dans l'ordre :

| Champ | Valeur |
|---|---|
| **Agence** *(en premier)* | `TEST-HABIS — HABIS Test Agency` |
| Code / Nom | `CAISSE-01` / `Caisse principale` |
| **Caissier assigné** | `Test Teller` |
| **Compte comptable** | `5710 — Caisse FCFA · TEST-HABIS` |
| ✅ **Exige le détail des coupures** | **coché** |
| Statut | Active |

> 🔴 **« Exige le détail des coupures » est le champ qui commande tout le §8.**
> Coché, l'ouverture de session affiche la grille de billetage. Décoché, elle
> n'affiche qu'une simple case « Fonds d'ouverture » et le billetage ne peut pas
> être testé.

**Contrôle :** la caisse apparaît dans la liste avec **CAISSIER = Test Teller**.
Si la colonne affiche « — », le caissier n'a pas été enregistré : rouvrez la fiche
par **⋮ › Modifier** et renseignez-le (en édition, l'agence ne change plus, donc
rien n'est effacé).

#### 1.4 Un client vérifié KYC, et son compte

1. **loan-officer** (`+237690000005`) : *Référentiel › **Client*** › créer le client.
2. **kyc-officer** (`+237690000007`) : onglet **Documents KYC**, déposer puis
   **soumettre** la pièce.
3. **compliance-officer** (`+237690000008`) : **valider**. Le badge doit passer à
   **KYC : Vérifié**.
4. **loan-officer** : *Référentiel › **Compte*** › **« Nouveau compte »** pour ce
   client (ex. `CPT-TEST-001`).

> Le compte client n'est pas décoratif : c'est lui qui **paie les frais de mise en
> place** au §4 et qui **reçoit le déblocage** par virement. Sans compte, le §4
> s'arrête après l'évaluation.

#### 1.5 Approvisionner le compte client — en **teller** (`+237690000004`)

Les frais du §4 totalisent **347 525 F** (30 000 + 211 750 + 5 775 + 100 000). Le
compte doit être provisionné d'au moins autant, sinon les boutons **« Régler »**
échouent.

1. *Opérations courantes › **Sessions de caisse*** › **« Ouvrir une session »** —
   c'est ici que se fait le billetage du **§8**, profitez-en pour le tester
   maintenant : saisissez le billetage du §8 (total **1 540 000 F**) comme fonds
   d'ouverture, et renseignez la **Date comptable** (celle de la journée ouverte).
2. *Opérations courantes › **Retrait / Versement*** › onglet **Versement** :
   client, compte `CPT-TEST-001`, **Montant `500 000`**.
3. Le versement exige lui aussi un **décompte des espèces** : le *Total compté*
   doit **égaler** le montant (ex. 50 × 10 000 F). Puis **Aperçu de l'opération** ›
   **Enregistrer le versement**.

---

## 2. Le formulaire du produit de prêt

Se connecter en **chief-accountant**. *Crédit › **Produits de prêt*** puis le bouton **« Nouveau produit »**.

### 2.1 Ce qui ne doit **plus** être là

Parcourez le formulaire du haut en bas. Les champs suivants **ont disparu**, c'est
le cœur de la demande :

- ❌ **Type de valeur de pénalité**, **Valeur de pénalité**, **Type de formule**,
  **Base de formule** ;
- ❌ **toutes** les listes de politiques de calcul (intérêt, pénalités, imputation,
  frais, taxe, assurance, dépôt de garantie, arrondi, échéancier, reporting) ;
- ❌ **Compte comptable par défaut** ;
- ❌ **Type de dépôt de garantie** (le « pourcentage / fixe »).

> ⚠️ **Ne confondez pas avec les produits de compte.** *Référentiel › **Produit de compte*** (épargne, compte courant) conserve légitimement un champ **« Compte
> comptable par défaut »** : seuls les **produits de prêt** l'ont perdu, parce que
> chaque crédit ouvre ses propres comptes. Le voir là-bas n'est pas une anomalie.

À la place, la section **Pénalité** affiche une phrase, pas un choix :

> La pénalité est la même pour tous les crédits : une partie fixe de 5 000 FCFA plus
> une partie variable de 2 % du montant impayé…

et la section **Comptabilité** :

> Aucun compte comptable par défaut : chaque ligne de crédit ouvre automatiquement
> ses propres comptes lors de la mise en place…

### 2.2 Ce qui doit être là

- ✅ **TVA sur les frais de dossier (%)**, pré-remplie à **19,25** — distincte de **TVA sur le
  principal**, qui porte sur le crédit lui-même ;
- ✅ **Dépôt de garantie (%)** — un seul champ, en pourcentage ;
- ✅ **Jours de grâce avant pénalité** ;
- ✅ **Différé de remboursement min. / max. (jours)**.

> ⚠️ **Trois champs contiennent le mot « grâce » ou « différé ». Ce ne sont pas des
> doublons.** « Jours de grâce avant pénalité » = le délai après l'échéance avant
> qu'une pénalité tombe. « Différé de remboursement min./max. » = les bornes du
> délai avant la **première** échéance. Ils ont été renommés pour lever la
> confusion signalée.

### 2.3 Créer le produit de test

| Champ | Valeur |
|---|---|
| Code / Nom du produit | `TEST-AOUT` / `Produit test août` |
| Statut | Actif |
| Montant minimum / Montant maximum | `100 000` / `5 000 000` |
| Durée minimale / Durée maximale | `1` / `24` |
| Unité de durée | **Mois** |
| Fréquences de remboursement autorisées | Mensuel |
| Taux d'intérêt | `10` |
| **TVA sur le principal** | `19,25` |
| **TVA sur les frais de dossier (%)** | `19,25` |
| Taux des frais de dossier (%) | `3` |
| Taux d'assurance | `2` |
| **Dépôt de garantie (%)** | `10` |
| Jours de grâce avant pénalité | `5` |

Enregistrez, puis **rouvrez le produit** et vérifiez deux choses :

1. **Chaque taux affiche exactement ce que vous avez saisi.** Un taux d'intérêt à
   `9` au lieu de `10`, ou un dépôt de garantie à `9,99` au lieu de `10`, veut dire
   qu'une valeur a bougé entre la saisie et l'enregistrement — signalez-le.
2. **Aucune politique de calcul n'apparaît** dans la fiche. C'est normal : elles
   sont attachées côté serveur.

> Les montants attendus de l'étape 4 supposent **un taux d'intérêt de 10 %** et un
> **dépôt de garantie de 10 %**. Si votre produit affiche autre chose, corrigez-le
> avant de continuer, sinon tous les montants seront décalés.

---

## 3. Créer un prêt — le formulaire complet

Se connecter en **loan-officer**. *Crédit › **Mise en place*** puis le bouton
**« Nouveau prêt »**.

Le formulaire a **quatre sections**. Voici **tous** les champs, dans l'ordre où ils
apparaissent, et quoi mettre. **Seuls trois sont obligatoires** : le titulaire, le
produit et le montant — tout le reste peut rester vide. Pour ce test, remplissez la
colonne « À saisir ».

### Section 1 — Rattachement

| Champ | Obligatoire | À saisir |
|---|---|---|
| **Titulaire (client)** | ✅ | votre client vérifié KYC |
| **Produit de prêt** | ✅ | `TEST-AOUT` |
| **Agent de crédit** | — | laissez vide : connecté en loan-officer, le système vous rattache automatiquement |
| **Date de demande** | — | **laissez la valeur par défaut : aujourd'hui, `26/08/2026`** |

### Section 2 — Montant & échéancier

| Champ | Obligatoire | À saisir |
|---|---|---|
| **Montant demandé** | ✅ | `1 000 000` |
| **Devise** | — | laissez `XAF` |
| **Nombre d'échéances** | — | `12` |
| **1re échéance** | — | `26/09/2026` (un mois après la demande) |
| **Périodicité (jours)** | — | **ne se saisit pas** — grisé |
| **Différé (jours)** | — | **ne se saisit pas** — grisé |
| **Durée totale (jours)** | — | **ne se saisit pas** — grisé |

### Section 3 — Comptes rattachés

Quatre listes : **Compte d'amortissement**, **Compte des impayés**, **Compte de
recouvrement**, **Compte de virement**. **Toutes facultatives.**

> Elles ne proposent que les comptes **du client sélectionné**. Si le client n'en a
> aucun, les listes sont vides : c'est normal, laissez-les vides. Elles ne bloquent
> ni la création ni les visas. Le compte de virement ne sert qu'au déblocage **par
> virement** ; pour un déblocage en espèces il n'est pas nécessaire.

### Section 4 — Activité financée

| Champ | Obligatoire | À saisir |
|---|---|---|
| **Objet du prêt** | — | `Test retours comité` |
| **Secteur d'activité** | — | n'importe lequel |
| **Sous-secteur** | — | un sous-secteur **du secteur choisi** |
| **Adresse de l'activité** | — | libre |
| **Adresse de l'entrepreneur** | — | libre |

> ❌ Le champ **Code activité financée** n'existe plus. Le secteur et le
> sous-secteur portent la classification — c'était la demande.

Validez avec **« Créer le prêt »**. Le prêt est créé **en brouillon**.

> Le **titulaire** et le **produit** ne sont plus modifiables après création :
> à l'édition ils s'affichent en lecture seule. C'est voulu.

### 3.1 Le test principal

> 🔒 **Les trois champs ci-dessous ne se saisissent pas.** Périodicité, Différé et
> Durée totale sont **grisés** dans le formulaire : le serveur les calcule à partir
> du **nombre d'échéances** et de la **1re échéance**. C'était la demande — « ils
> sont censés apparaître automatiquement ». Vous ne les remplissez jamais ; vous
> les **lisez** pour vérifier le calcul.

Vous avez saisi `12` échéances et une **1re échéance** au `26/09/2026`.
Enregistrez, puis ouvrez la fiche du prêt, onglet **« Financières »**.

**Ce qui doit s'afficher (en lecture seule) :**

| Champ | Valeur affichée |
|---|---|
| Périodicité (jours) | **30** |
| Différé (jours) | **0** |
| Durée totale (jours) | **365** |

> **Pourquoi 0 de différé ?** Le 26/09 est exactement une échéance mensuelle après
> le 26/08. La première échéance n'est pas du différé. **Pourquoi 365 et pas 360 ?**
> Douze mois calendaires font un an, pas 12 × 30.

### 3.2 Faire bouger le différé — sans le saisir

Puisque le différé se déduit de la 1re échéance, **on le change en changeant cette
date**, jamais en tapant dedans.

Créez un **second prêt**, même client, même produit, mêmes `12` échéances et même
**date de demande `26/08/2026`** — **une seule chose change** : la **1re échéance**,
que vous mettez au **`10/10/2026`**.

Ouvrez sa fiche, onglet **« Financières »**. Sans avoir touché aux champs grisés,
ils doivent maintenant afficher :

| Champ | Valeur affichée | Pourquoi |
|---|---|---|
| Périodicité (jours) | **30** | inchangée : c'est l'unité de durée du produit |
| Différé (jours) | **14** | du 26/09 (1 mois après la demande) au 10/10 |
| Durée totale (jours) | **380** | du 26/08/2026 à la 12ᵉ échéance, le 10/09/2027 |

> ⚠️ **Si le Différé reste à 0 ou garde la valeur du premier prêt, c'est une
> anomalie** : la déduction ne suit pas la date.

### 3.3 Le plafond de différé

Le seul endroit où un différé se **saisit**, c'est sur le **produit**, en tant que
borne — pas sur le prêt. Ce test vérifie que la borne tient alors même que plus
personne ne saisit de différé : il faut donc qu'elle morde sur la **date**.

Ouvrez le produit `TEST-AOUT`, mettez **Différé de remboursement max. = `30`**,
enregistrez.

Avec la **date de demande d'aujourd'hui (`26/08/2026`)**, l'ancre est le `26/09/2026` (demande
+ un terme). Le différé, c'est ce qui sépare la 1re échéance de cette ancre. Créez
deux prêts qui encadrent la borne — **rien d'autre ne change entre les deux, que la
date** :

| 1re échéance | Différé impliqué | Résultat attendu |
|---|---|---|
| **`26/10/2026`** | **30 jours** — pile la borne | ✅ **enregistré** |
| **`27/10/2026`** | **31 jours** — un jour de trop | ❌ **refusé**, message porté par la **1re échéance** |

> C'est le point important, et un jour d'écart suffit à le montrer : la borne du
> produit reste opposable même si le différé n'est plus un champ. Une date lointaine
> ne permet pas de la contourner — et une date pile à la borne n'est pas refusée à
> tort.

> 🧹 **Videz ensuite le champ « Différé de remboursement max. »** sur le produit.
> Il n'est plus utile aux étapes suivantes, et le laisser à 30 ferait refuser sans
> raison apparente un prêt créé plus loin avec une 1re échéance éloignée.

### 3.4 Faire passer les quatre visas

Le prêt est en **brouillon**. Pour la suite (frais, déblocage) il doit être
**approuvé**, ce qui demande les **quatre visas** du circuit HABIS.

Ouvrez le **prêt n° 1** (celui du §3.1). Le panneau **« Circuit de visa »** liste
les quatre étapes, chacune **En attente**, avec trois boutons : **Approuver**,
**Renvoyer**, **Rejeter**.

Pour chaque étape, **connectez-vous avec l'utilisateur correspondant**, cliquez
**Approuver**, puis **Confirmer le visa** (le commentaire est facultatif) :

| Ordre | Étape | Se connecter avec | Rôle |
|---|---|---|---|
| 1 | **Montage** | +237690000005 | loan-officer |
| 2 | **Comptabilité** | +237690000006 | accountant |
| 3 | **Contrôle** | +237690000008 | compliance-officer |
| 4 | **Direction** | +237690000002 | agency-manager |

> ⚠️ **Une même personne ne peut signer qu'un seul visa.** Si un visa est refusé
> avec « vous avez déjà approuvé une autre étape », changez d'utilisateur : ce
> n'est pas une anomalie. De même, les étapes se font **dans l'ordre** — la
> Comptabilité ne peut pas viser avant le Montage.

Après le visa **Direction**, le statut du prêt passe de **Brouillon** à
**Approuvé**. C'est cette bascule qui le fait apparaître à l'étape suivante.

---

## 4. Frais de mise en place — les deux TVA

Se connecter en **agency-manager**. *Crédit › **Déblocage prêt***, puis ouvrez le
prêt n° 1 (1 000 000 F). Le panneau **« Frais de dossier »** porte le bouton
**« Évaluer les frais »**.

> **Le prêt n'apparaît dans cette liste que s'il est approuvé** — d'où le §3.4. Si
> vous ne le trouvez pas, c'est qu'il manque un visa, pas que les frais sont en
> cause : le calcul lui-même accepte un prêt en brouillon, c'est l'écran de
> déblocage qui ne liste que les prêts approuvés.
>
> Le panneau vit sur l'écran de **déblocage**, pas sur la fiche du prêt : le
> décaissement n'est possible qu'une fois ces frais encaissés, d'où sa place.

**Quatre lignes doivent apparaître :**

| Ligne | Base | Montant attendu |
|---|---|---|
| Frais de dossier | 1 000 000 | **30 000 F** |
| TVA sur les frais de dossier | 30 000 | **5 775 F** |
| TVA sur le principal | **1 100 000** | **211 750 F** |
| Dépôt de garantie | 1 000 000 | **100 000 F** |

Et l'assurance, hors encaissement : **20 000 F**.

> ⚠️ **La TVA du crédit se calcule sur 1 100 000, pas sur 1 000 000.** La base reste
> le **capital accordé plus l'intérêt total** (1 000 000 + 100 000). Ajouter la TVA
> sur les frais **n'a pas** déplacé cette base. Une TVA de 192 500 F serait une
> anomalie : signalez-la.

> La TVA sur les frais porte **uniquement sur les 30 000 F de frais**, jamais sur le
> capital.

Cliquez **« Évaluer les frais »** une seconde fois : les montants ne bougent pas et aucune ligne n'est
dupliquée. Après déblocage, **« Évaluer les frais »** doit être **refusé**.

### 4.1 Encaisser les frais, puis débloquer

L'évaluation ne fait que **calculer**. Tant que les quatre lignes ne sont pas
encaissées, le bouton **« Confirmer le décaissement »** reste inactif — le
message *« 4 frais restant(s) à régler avant le décaissement »* le rappelle.

Dans le même panneau :

1. **Source de paiement** : `Compte client`.
2. **Compte à débiter** : le compte approvisionné au §1.5 (`CPT-TEST-001`).
3. Cliquez **« Régler »** sur **chacune des quatre lignes**. Chaque ligne passe de
   **À régler** à **Réglé**, et le compteur descend. L'**Assurance (20 000 F)**
   n'a pas de bouton : elle est *Informatif* et ne bloque pas.
4. Quand les quatre sont réglées, le panneau affiche **« Tous les frais sont
   réglés. Vous pouvez décaisser. »**
5. **Canal de décaissement** : `Virement sur compte`, puis **Compte de virement**
   = le compte du client. *(En espèces, le compte de virement n'est pas demandé.)*
6. **« Confirmer le décaissement »**. Le prêt **disparaît de la liste** des prêts à
   décaisser et passe au statut **Décaissé**.

> Le bouton **« Dispenser (direction) »** à côté de chaque ligne est la sortie
> manuelle évoquée au §12 (annulation / dispense de frais) : ne l'utilisez pas ici,
> sinon la ligne ne sera pas encaissée et le §5 n'aura rien à montrer.

---

## 5. Les comptes du dossier

C'est la contrepartie de la suppression du compte par défaut.

Après l'évaluation des frais, allez dans *Comptabilité › **Comptes généraux*** et
recherchez le **numéro du prêt**. Vous devez trouver **deux comptes nouveaux** :

| Compte | Code | Parent |
|---|---|---|
| Crédit client `<n° prêt>` | `<code du compte de regroupement>.<n° prêt>` | le compte mappé sur `loan_principal_disbursement` |
| Dépôt de garantie crédit `<n° prêt>` | idem | le compte mappé sur `loan_setup_guarantee_deposit` |

> **Il n'y a pas de compte de pénalités par dossier**, et c'est voulu : les
> pénalités sont un produit, et le plan comptable ne se subdivise pas par
> emprunteur pour les produits.

**Vérifications** — ouvrez *Édition › **Journal des écritures*** (en
**chief-accountant** : l'agency-manager n'a pas la section Édition) et retrouvez
les deux écritures produites au §4.1 :

1. **L'encaissement du dépôt de garantie** crédite `18700.<n° prêt>` — le compte
   **du dossier** — pour **100 000 F**, et débite le compte du client
   (`CLI000001`). Le compte de regroupement `18700` **ne doit pas** apparaître
   dans l'écriture.
2. **Le déblocage** débite `3222.<n° prêt>` — le **Crédit client** du dossier —
   pour **1 000 000 F**, pas le regroupement `3222`.
3. **Le compte de regroupement roule ses enfants.** Dans *Comptabilité ›
   **Comptes généraux***, cherchez `3222` : vous voyez le compte de regroupement
   **et** une ligne `3222.<n° prêt>` par dossier. Le solde affiché sur le
   regroupement est **consolidé** : ses propres écritures anciennes **plus**
   celles de tous les dossiers en dessous. Un regroupement à **0** alors que les
   dossiers portent des montants est une anomalie.

---

## 6. Pénalités de retard

Une pénalité suppose une échéance **déjà en retard**. On ne peut donc pas partir
d'un prêt daté d'aujourd'hui : il faut un prêt dont la **1re échéance est passée**.
Les deux prêts ci-dessous sont datés en arrière exprès, pour qu'ils soient
réellement en impayé **le jour où vous testez**.

Créez-les avec **600 000 F sur 12 mois** : chaque échéance vaut alors **55 000 F**
(50 000 de capital + 5 000 d'intérêt), ce qui rend les chiffres lisibles. Débloquez
chacun, puis lancez l'évaluation des arriérés (voir §6.0), **à la date du jour**.

### 6.0 Où lance-t-on l'évaluation des arriérés

> 🔴 **Ce n'est pas sur *Suivi des exigibles*.** Cet écran ne sert qu'au suivi
> manuel (relances, rendez-vous, promesses de paiement) — il n'a pas de bouton
> « évaluer ». L'évaluation des arriérés est un **traitement batch**, lancé
> **en `platform-admin`** (`+237699100000`, cf. §1) :
>
> *Paramétrage › **Procédure Batch** › onglet **Exécutions** › **« + Nouvelle
> exécution »*** :
>
> | Champ | Valeur |
> |---|---|
> | Procédure | `Loan Arrears Assessment` (`loan_arrears_assessment`) |
> | **Date comptable** | la date d'arrêté (« as of ») — pour ce test, **la date du jour** |
> | Agence | `HABIS Test Agency (TEST-HABIS)` |
>
> Cliquez **Créer** : l'exécution apparaît **En attente**. Ouvrez son menu **⋮ ›
> Exécuter**, confirmez. Le statut passe à **Réussie** et le détail affiche
> `assessed_loans`, `loans_with_new_penalties` et `assessed_penalty_minor`
> (en centimes). C'est la **Date comptable** que vous changez au §6.4, pas une
> case sur le prêt.
>
> **En production, personne ne lance ceci à la main** : la procédure est
> planifiée (cadence *Quotidienne* pour l'évaluation, *Mensuelle* pour la
> pénalité) et tourne dans le traitement de fin de journée. Le lancement manuel
> décrit ici est réservé au back-office / administrateur pour un rattrapage.

> ⚠️ **« Débloquez chacun » veut dire toute la chaîne** : les **quatre visas** du
> §3.4 (quatre utilisateurs différents), puis l'**encaissement des quatre frais**
> et le décaissement du §4.1. Une pénalité suppose un prêt **décaissé** : un prêt
> seulement approuvé ne produit aucun arriéré.
>
> Prévoyez la trésorerie : les frais valent **208 515 F par prêt** à 600 000 F
> (18 000 + 3 465 + 127 050 + 60 000), soit **417 030 F pour les deux**.
> Réapprovisionnez le compte client au §1.5 si besoin.

> Les jours de grâce du produit valent **5** : une échéance ne devient pénalisable
> que 5 jours après son échéance.

### 6.1 La formule — prêt A

**Prêt A** : 600 000 F, 12 mois, **1re échéance `26/07/2026`**. Au `26/08/2026`,
une seule échéance a dépassé les 5 jours de grâce (celle du 26/07).

**Pénalité attendue : 6 100 F** = 5 000 F (partie fixe) + 1 100 F (2 % de 55 000).

Le mois suivant, une nouvelle pénalité s'ajoute. Les pénalités déjà posées n'en
génèrent **jamais** de nouvelles (pas de capitalisation).

> ℹ️ **Combien exactement le mois suivant ?** Cela dépend du nombre d'échéances
> alors en retard. Si vous relancez à une date où **deux** échéances ont dépassé
> la grâce (la 26/07 **et** la 26/08), l'ajout n'est pas 6 100 mais
> **7 200 F** = 5 000 (partie fixe, **une seule** par dossier et par mois) +
> 2 × 1 100 (2 % sur **chacune** des deux échéances), portant le cumul à
> **13 300 F**. C'est la même règle qu'au §6.2 — la partie fixe reste mensuelle,
> la partie variable suit le nombre d'échéances. Un cumul de **12 200 F** ne vaut
> que si une seule échéance est en retard au second passage.

### 6.2 La partie fixe est mensuelle, pas par échéance — prêt B

**Prêt B** : 600 000 F, 12 mois, **1re échéance `26/05/2026`**. Au `26/08/2026`,
**trois** échéances ont dépassé la grâce — celles du 26/05, du 26/06 et du 26/07
(celle du 26/08 est encore dans ses 5 jours).

Évaluez **une seule fois** :

**Attendu : 8 300 F** = **une** partie fixe de 5 000 F + 2 % sur chacune des trois
échéances (3 × 1 100).

> ⚠️ **18 300 F serait une anomalie.** Les 5 000 F sont un frais de recouvrement
> mensuel, un par dossier et par mois, pas un par échéance.

### 6.3 Le plancher et le plafond

| Impayé | Pénalité attendue | Pourquoi |
|---|---|---|
| **999 F** | **0 F** | sous le plancher de 1 000 F |
| **1 000 F** | **1 000 F** | la formule donnerait 5 020 F ; **plafonnée à la dette** |
| **2 000 F** | **2 000 F** | idem |
| **55 000 F** | **6 100 F** | au-dessus du plafond, formule intégrale |

> **Une pénalité ne peut jamais dépasser le montant impayé qu'elle sanctionne.**
> Le plafond est cumulatif : une fois les pénalités égales à la dette, les mois
> suivants n'ajoutent rien.

### 6.4 Une réévaluation antidatée ne double pas

Sur le **prêt A**, relancez le batch d'arriérés (§6.0) **trois fois**, en changeant
la **Date comptable** (la date d'arrêté « as of ») à chaque exécution :

| Ordre | Date d'arrêté | Pénalité produite |
|---|---|---|
| 1 | `26/08/2026` (aujourd'hui) | **6 100 F** |
| 2 | `20/07/2026` — **antidaté** | **0 F** |
| 3 | `30/08/2026` — même mois, plus tard | **0 F** |

La ligne d'échéance doit rester à **6 100 F** au total, pas 12 200 ni 18 300.

> ⚠️ **Si le passage antidaté produit une seconde pénalité, c'est une anomalie.**
> Le contrôle porte sur « déjà pénalisé depuis le début du mois d'arrêté », et la
> date de dernière pénalisation ne recule jamais — sans quoi il suffirait de
> relancer le traitement sur un mois antérieur pour facturer deux fois.

---

## 7. La fiche du compte client

Se connecter en **loan-officer**. *Référentiel › **Compte*** puis **« Nouveau compte »**.

### 7.1 Le numéro du client **est** son compte comptable

Dans la section **Comptabilité** de la fiche, le champ **Compte comptable (code)**
doit afficher le **numéro du client** (ex. `CLI000123`) — pas un identifiant
technique.

C'est ce code que porteront les écritures. Il est aussi **recherchable** depuis la
liste des comptes.

> Si le client détient **plusieurs** comptes, le premier porte son numéro nu et les
> suivants le portent en préfixe : `CLI000123.ACC00000002`. Le numéro du client
> reste la partie de tête de tous ses comptes.

### 7.2 Les trois corrections d'affichage

| Point | Ce que vous devez voir |
|---|---|
| **Agence** | Le **nom de l'agence** tel qu'enregistré — plus un identifiant |
| **Titulaire (client)** | Le nom **complet** : NOM Prénom **et deuxième prénom** s'il existe |
| **Mandataires** | Les mandataires enregistrés **sur la fiche client** apparaissent, avec une colonne **Portée** indiquant « Tous les comptes » ou « Ce compte » |

> ⚠️ C'était le bug signalé : un mandataire saisi à la création du profil client
> n'apparaissait sur **aucun** compte. Testez précisément ce cas : créez un
> mandataire depuis la fiche client, sans le rattacher à un compte, puis ouvrez
> l'onglet **Mandataires** d'un compte de ce client.

---

## 8. Session de caisse — le billetage

Se connecter en **teller**. *Opérations courantes › **Sessions de caisse*** ›
**« Ouvrir une session »**, puis choisissez la caisse `CAISSE-01`.

> 🔴 **Si le bouton « Ouvrir une session » est grisé**, c'est qu'aucune caisse
> n'existe pour l'agence : revenez au **§1.3** (c'est l'**agency-manager** qui la
> crée, pas le teller). **Si la grille de coupures n'apparaît pas** après avoir
> choisi la caisse, c'est que **« Exige le détail des coupures »** n'est pas coché
> sur la fiche caisse — même §1.3.

La grille **DÉTAIL DES COUPURES** s'affiche dès que la caisse est sélectionnée.
Saisissez **exactement** le billetage remonté :

| Coupure | Nombre | Montant attendu sur la ligne |
|---|---|---|
| 10 000 F (billet) | 100 | **1 000 000 F** |
| 5 000 F (billet) | 100 | **500 000 F** |
| 2 000 F (billet) | 10 | **20 000 F** |
| 1 000 F (billet) | 15 | **15 000 F** |
| 500 F (billet) | 2 | **1 000 F** |
| 500 F (pièce) | 8 | **4 000 F** |
| **Total** | | **1 540 000 F** |

> ⚠️ **Un total de 15 400 F signifie que le correctif n'est pas appliqué sur cet
> environnement.** Chaque ligne serait alors exactement 100 fois trop petite.
> Dans ce cas, vérifiez que la migration de réparation et le semis des coupures
> ont bien tourné au déploiement, puis re-testez.

Chaque ligne affiche son sous-total à droite du nombre, et le pied de grille
affiche **« Total compté »**. C'est ce total qui doit valoir **1 540 000 FCFA**.

Contrôle complémentaire : *Paramétrage › **Type monnaie*** doit lister les coupures BEAC —
billets 500 à 10 000, pièces 1 à 500 (**14 coupures**), chacune avec sa valeur en
francs (« 10 000 FCFA » pour le billet de 10 000, pas « 100 FCFA »).

> Le même décompte est redemandé à chaque **versement** ou **retrait** d'espèces
> (*Retrait / Versement*, section **Décompte des espèces**). Là, le *Total compté*
> doit **égaler le montant de l'opération**, sinon l'enregistrement est refusé.

---

## 9. Documents KYC — consultation

Se connecter en **kyc-officer** ou **compliance-officer**. Ouvrez une fiche client
› onglet **Documents d'identité**.

**Cliquez sur la ligne** d'un document. Un panneau s'ouvre avec :

1. **Document** — le visuel. **Recto et verso** côte à côte si le type en a deux.
   Sous chaque face : nom du fichier, type, taille, date de dépôt. Un lien
   **« Ouvrir dans un nouvel onglet »** permet d'agrandir.
2. **Caractéristiques** — type, numéro, autorité émettrice, statut de vérification,
   dates d'émission / d'expiration, dates de soumission et de validation, et le
   motif de rejet le cas échéant.

**À vérifier :**

- un document **PDF** s'affiche comme un PDF, pas comme une image cassée ;
- cliquer sur le **menu d'actions** (⋮) d'une ligne ouvre le menu **sans** ouvrir
  le panneau derrière ;
- un document sans fichier joint affiche « Aucun fichier joint », pas une erreur.

---

## 10. Ce qui doit vous faire lever la main

- Une TVA sur le principal de **192 500 F** au lieu de 211 750 F sur le prêt à
  1 000 000 (la base aurait été changée).
- Une pénalité de **18 300 F** sur trois échéances impayées (partie fixe comptée
  par échéance).
- Une pénalité **supérieure** au montant impayé.
- Un total de billetage de **15 400 F**.
- Un **compte comptable par défaut** encore proposé sur le produit de prêt.
- Une **liste de politiques de calcul** encore proposée.
- Un **identifiant technique** (long code `01M0…`) affiché là où un nom ou un code
  est attendu. Regardez en particulier : l'**agence** et le **titulaire** sur la
  fiche compte, le **type / produit de compte** sur cette même fiche, et le
  **caissier** à l'ouverture d'une session de caisse ainsi que dans la colonne
  *Caissier* de la liste des sessions.
- Un déblocage qui passe **sans** mappage d'opération configuré : le système doit
  refuser.

---

## 11. Deux questions posées, deux réponses

**« À quoi renvoie l'onglet MISE EN ATTENTE ? »** — libellé à l'écran : **Mises en attente**.
C'est le blocage d'une partie du solde. Aucune écriture n'est passée : l'argent ne
bouge pas, mais le montant est retiré du **solde disponible** —
`disponible = solde comptable − solde minimum − montant indisponible − mises en
attente actives`. Une mise en attente porte un montant, un motif, qui l'a posée et
quand, une échéance éventuelle. Usages courants : chèque en cours
d'encaissement, gage, blocage judiciaire. Seules les mises en attente **actives**
réduisent le disponible ; libérées ou annulées, elles cessent immédiatement de
compter.

**« Comment se passeront les impressions ? »**
Le mécanisme existe déjà et est utilisé par huit écrans (journal, relevés,
rapports, image globale client, impayés, brouillard de caisse…). Le bouton
d'impression construit un document A4 à en-tête, l'ouvre dans un nouvel onglet et
déclenche la boîte d'impression du navigateur, où l'on choisit **une imprimante**
ou **Enregistrer au format PDF**.

> **Ce qui manque encore** : la **fiche client** et la **fiche compte** ne sont pas
> encore branchées sur ce mécanisme. Le branchement est court, mais il faut d'abord
> décider **ce que doit contenir** une fiche client imprimée : identité seule, ou
> identité + comptes + soldes. Dites-nous, et c'est fait.

---

## 12. Ce que ce guide ne couvre pas

- La **banque islamique** : hors périmètre de cette version, traitée séparément.
- Le **plan comptable** lui-même : les comptes PCEMF (`3222`, `4303`, `7153`,
  `18700`, `5710`…) sont supposés déjà chargés pour l'agence. En revanche les
  **imputations d'opérations** et la **caisse**, qui ne sont pas créées
  automatiquement, sont couvertes pas à pas au **§1.2** et au **§1.3** : ce sont
  des pré-requis du test, pas des acquis de l'environnement.
- Les cas exceptionnels de frais de dossier (annulation, dispense, remboursement),
  qui restent une **décision manuelle de la Direction** et ne sont pas automatisés.
- L'**assurance** en tant que module : le taux du produit produit un montant
  d'assurance sur le prêt, sans souscription ni prime.
