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

## 1. Comptes et pré-requis

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

> Un visa refusé avec « vous avez déjà approuvé une autre étape » n'est **pas** une
> anomalie : changez d'utilisateur.

**Avant de commencer :**

1. **Une journée comptable ouverte** pour l'agence (bandeau en haut à droite).
   Sinon : *Administration › **Journée Comptable***.
2. **Les mappages d'opérations** doivent exister pour l'agence — *Comptabilité › **Codes opération & imputations*** :
   `loan_principal_disbursement` (débit), `loan_setup_dossier_fee`,
   `loan_setup_principal_tax`, `loan_setup_tax`, `loan_setup_guarantee_deposit`
   (crédit). **Sans eux, rien ne se débloque** — et c'est voulu.
3. Un client **vérifié KYC** (étapes habituelles : créer, soumettre, valider avec
   deux comptes différents).

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

**Vérifications :**

1. L'encaissement du dépôt de garantie crédite le compte **du dossier**, pas le
   compte de regroupement.
2. Le déblocage débite le **Crédit client `<n° prêt>`**.
3. Ouvrez le **compte de regroupement** : son solde doit inclure **ses propres
   écritures anciennes plus celles de tous les dossiers en dessous**. S'il affiche
   0 alors que les dossiers portent des montants, c'est une anomalie.

---

## 6. Pénalités de retard

Une pénalité suppose une échéance **déjà en retard**. On ne peut donc pas partir
d'un prêt daté d'aujourd'hui : il faut un prêt dont la **1re échéance est passée**.
Les deux prêts ci-dessous sont datés en arrière exprès, pour qu'ils soient
réellement en impayé **le jour où vous testez**.

Créez-les avec **600 000 F sur 12 mois** : chaque échéance vaut alors **55 000 F**
(50 000 de capital + 5 000 d'intérêt), ce qui rend les chiffres lisibles. Débloquez
chacun, puis lancez l'évaluation des arriérés depuis *Crédit › **Suivi des
exigibles*** (ou le traitement de fin de mois), **à la date du jour**.

> Les jours de grâce du produit valent **5** : une échéance ne devient pénalisable
> que 5 jours après son échéance.

### 6.1 La formule — prêt A

**Prêt A** : 600 000 F, 12 mois, **1re échéance `26/07/2026`**. Au `26/08/2026`,
une seule échéance a dépassé les 5 jours de grâce (celle du 26/07).

**Pénalité attendue : 6 100 F** = 5 000 F (partie fixe) + 1 100 F (2 % de 55 000).

Le mois suivant, une nouvelle pénalité de 6 100 F s'ajoute : **12 200 F** cumulés.
Les pénalités déjà posées n'en génèrent **jamais** de nouvelles.

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

Sur le **prêt A**, relancez l'évaluation des arriérés **trois fois**, en changeant
la date d'arrêté (« as of ») à chaque passage :

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

Se connecter en **teller**. *Opérations courantes › **Sessions de caisse***.

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

Contrôle complémentaire : *Paramétrage › **Type monnaie*** doit lister les coupures BEAC —
billets 500 à 10 000, pièces 1 à 500.

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
- Un **identifiant technique** affiché là où un nom ou un code est attendu
  (agence, titulaire, compte comptable).
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
- Le **paramétrage initial** du plan comptable et des mappages d'opérations
  (prérequis, pas objet du test).
- Les cas exceptionnels de frais de dossier (annulation, dispense, remboursement),
  qui restent une **décision manuelle de la Direction** et ne sont pas automatisés.
- L'**assurance** en tant que module : le taux du produit produit un montant
  d'assurance sur le prêt, sans souscription ni prime.
