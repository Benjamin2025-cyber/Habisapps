# Guide de test — frais de dossier en pourcentage

Script pas à pas pour vérifier la demande de la comptabilité :

> « Le montant des frais de dossier doit être en pourcentage et sans plancher. »

Écrit pour un non-comptable. Chaque étape dit quoi faire, ce que vous devez voir, et
pourquoi — afin de distinguer une anomalie d'un comportement correct.

---

## 0. Ce qui a changé, en trente secondes

**Avant** : les frais de dossier étaient un **montant fixe** en francs. Un prêt de
100 000 F et un prêt de 5 000 000 F payaient les mêmes frais. Le formulaire proposait
en plus un champ **« Montant plancher »**, enregistré en base et **lu par aucun
calcul** — on pouvait le remplir, il ne servait à rien.

**Maintenant** : un seul champ, **« Taux des frais de dossier (%) »**. Les frais sont
un pourcentage du capital emprunté. Il n'y a ni montant fixe, ni plancher.

> ⚠️ **À lire avant de crier au bug.** Le champ « montant fixe » a disparu de la base.
> Les produits créés **avant** ce changement n'ont donc aucun taux : leurs frais de
> dossier valent **0** tant que personne ne saisit un taux. C'est attendu — l'étape 1
> consiste précisément à en saisir un.

---

## 1. Configurer le produit

**Crédit › Produits de prêt** → ouvrez un produit (ou créez-en un).

Dans la section des taux, vérifiez d'abord la **forme du formulaire** :

- ✅ Un champ **« Taux des frais de dossier (%) »**, à côté des taux d'intérêt, de
  taxe et d'assurance.
- ❌ **Aucun** champ « Frais de dossier » en francs.
- ❌ **Aucun** champ « Montant plancher ».

Saisissez **1,5** comme taux des frais de dossier. Enregistrez, rouvrez : la valeur
doit être conservée.

> C'est la première chose que la comptabilité regardera. Si « Montant plancher » est
> encore là, rien d'autre n'a besoin d'être testé.

---

## 2. Les frais suivent le montant du prêt

Créez un prêt sur ce produit, d'un capital de **1 000 000 F**, et menez-le jusqu'à
l'étape de déblocage.

**Crédit › Déblocage prêt** → sélectionnez le prêt → **Frais de dossier** →
lancez l'évaluation si elle n'a pas déjà eu lieu.

**Attendu : frais de dossier = 15 000,00 F.**

C'est 1,5 % de 1 000 000. Vous retrouvez le même montant dans
**Crédit › Prêts › [le prêt] › Financier**, ligne « Frais de dossier ».

---

## 3. Sans plancher — le test qui compte

Créez un second prêt sur le **même produit**, capital **100 000 F**. Évaluez ses
frais.

**Attendu : frais de dossier = 1 500,00 F.**

Dix fois moins de capital, dix fois moins de frais. C'est exactement ce que
« sans plancher » veut dire : rien ne relève ce montant vers un minimum.

> Sous l'ancien fonctionnement, ce prêt payait le **même** montant fixe que celui de
> 1 000 000 F. C'est ce que la comptabilité reprochait au système.

**Si vous voyez 15 000 F, ou un montant identique aux deux prêts, arrêtez-vous et
signalez-le** : le taux n'est pas appliqué.

---

## 4. Un montant qui ne tombe pas juste

Créez un troisième prêt, capital **33 333 F**. Évaluez.

**Attendu : frais de dossier = 500,00 F.**

1,5 % de 33 333 F donne 499,9995 F. Le montant est arrondi au centime le plus proche,
vers le haut ici.

> Ce cas mérite d'exister dans le test : avec un montant fixe, la question ne se
> posait jamais. Avec un pourcentage, la plupart des capitaux ne tombent pas juste, et
> le calcul doit arrondir au lieu de refuser.

**Une erreur technique (500) sur cette étape est un vrai défaut** — c'est le symptôme
d'un calcul qui refuse d'arrondir. Signalez-la avec le capital et le taux utilisés.

---

## 5. Taux à zéro, et pas de taux

Deux cas limites, rapides :

| Taux saisi | Attendu |
|---|---|
| **0** | Frais de dossier = 0,00 F. Le prêt reste déblocable. |
| Champ **vide** | Frais de dossier = 0,00 F également — aucun taux, aucun frais. |

Dans les deux cas le déblocage doit rester possible : des frais nuls ne sont pas un
blocage.

---

## 6. Ce qui doit vous faire lever la main

| Symptôme | Pourquoi c'est grave |
|---|---|
| Le champ **« Montant plancher »** est encore sur le formulaire | La demande n'est pas appliquée ; et ce champ ne pilote rien |
| Un champ **« Frais de dossier »** en francs subsiste | Le montant fixe est toujours configurable |
| Deux prêts de capitaux différents ont les **mêmes** frais | Le taux n'est pas appliqué |
| Les frais d'un petit prêt sont **relevés** à un minimum | Un plancher est appliqué quelque part |
| **Erreur 500** à l'évaluation des frais | Le calcul refuse d'arrondir |
| Le taux saisi n'est **pas conservé** après enregistrement | Le champ n'est pas transmis à l'API |

---

## 7. Ce que ce guide ne couvre pas

- La **taxe** sur les frais de dossier et le **dépôt de garantie** suivent leurs
  propres règles, inchangées ici.
- Si le produit utilise une **politique de formule** définissant son propre
  `dossier_fee_rate`, c'est elle qui l'emporte sur le taux du produit. Ce mécanisme
  existait déjà et n'a pas changé.
