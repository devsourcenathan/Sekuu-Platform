# ADR-0019 — Ce qui est accordé est figé sur l'abonnement

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

[ADR-0018](adr-0018-platform-operator.md) rend les limites de plan modifiables
par API. Reste une question qu'elle ne tranche pas : **quand un changement
prend-il effet ?**

Aujourd'hui, `BillingContract::limit()` lit `plans.limits` au moment où un module
compte sa ressource. Un abonnement ne référence qu'un `plan_id`.

Conséquence immédiate, et elle n'est pas acceptable : passer `storage_gb` de 50
à 20 le mardi **rétrograde tous les abonnés du plan mardi soir**, y compris ceux
qui ont payé une année d'avance la semaine précédente. Certains se retrouvent
au-dessus de leur quota sans avoir rien fait.

## Le problème

Le modèle est **prépayé** ([ADR-0007](adr-0007-mobile-money-prepaid-subscriptions.md)) :
un client paie une période à l'avance, pour ce qu'on lui a promis. Reprendre en
cours de période ce qui a été payé n'est pas un réglage, c'est une rupture de
contrat.

Symétriquement, personne n'a jamais été lésé par une limite qui monte.

Le codebase a déjà tranché deux fois dans ce sens. Une facture fige
`billing_details` à l'émission. Un PDF est produit une fois et ne suit pas le
code d'aujourd'hui ([ADR-0013](adr-0013-invoice-pdf-frozen.md)). La même logique
s'applique à ce qui a été promis.

## Décision

**Les limites accordées sont copiées sur l'abonnement, et relues là.**

Une colonne `granted_limits` en `jsonb` sur `subscriptions`, écrite à
l'ouverture de chaque période. `BillingContract::limit()` lit cette copie, plus
jamais le plan.

### La hausse s'applique tout de suite, la baisse au renouvellement

| Changement | Effet |
| --- | --- |
| Une limite **monte** | Reportée sur tous les abonnements actifs, immédiatement |
| Une limite **baisse** | Reportée à la période suivante, à chaque renouvellement |
| Une limite **apparaît** | Comme une hausse — elle n'enlève rien |
| Une limite **disparaît** | Comme une baisse — elle ferme une ressource |

L'asymétrie est le cœur de la décision. Elle n'est pas une commodité : elle dit
que **la plateforme peut être plus généreuse que promis, jamais moins.**

C'est aussi ce qui rend une correction sans danger. Un opérateur qui se trompe
en haussant fait un cadeau ; le même qui se trompe en baissant ne casse rien
avant le renouvellement, et a le temps de se reprendre.

### `null` reste illimité, et l'absence reste « non couvert »

La distinction à trois états de `PlanLimit` est préservée dans la copie. Elle
compte plus que jamais ici : une clé absente d'un plan signifie que la ressource
n'est **pas couverte**, et non qu'elle vaut zéro.

### Une commande rebâtit les copies

`billing:regrant` réapplique les limites du plan à tous les abonnements actifs,
en respectant l'asymétrie. Elle sert au rattrapage — les abonnements créés avant
cette décision n'ont pas de copie — et après une correction.

Elle est **idempotente** et se relit : sans cela, personne n'oserait la lancer
sur une base de production.

## Conséquences

**Deux vérités cohabitent** : le plan tel qu'il est vendu aujourd'hui, et ce que
chaque abonné a obtenu. C'est une complexité réelle, et c'est exactement la
complexité du métier — un catalogue change, des contrats courent.

**Un support pourra répondre à « pourquoi suis-je bloqué ? ».** Aujourd'hui la
réponse est « le plan dit 50 » ; demain elle est « votre période vous accorde
20, et votre renouvellement du 3 septembre vous en accordera 50 ». La seconde
est vérifiable.

**Un abonnement sans copie doit être traité comme non couvert**, pas comme
illimité. Le défaut le plus dangereux serait de lire `null` sur une colonne
vide et d'en conclure « illimité » — d'où une colonne **non nullable**, avec un
objet vide par défaut, et `billing:regrant` pour la remplir.

**Le quota d'IA hérite de tout cela gratuitement.** `ai_credits_monthly` est une
clé comme les autres : elle se règle par l'API d'opérateur, se fige sur
l'abonnement, et suit la même asymétrie.

## Ce qui a été écarté

**Versionner les plans.** Chaque modification créerait une version, les
abonnements pointant la leur. C'est plus fidèle, et c'est le modèle des grands
systèmes de facturation. Écarté pour l'instant : une table de versions, une
migration des références, et une interface pour choisir la version — beaucoup de
mécanique pour un catalogue de trois plans. La copie sur l'abonnement en donne
l'essentiel.

**Appliquer tout changement immédiatement, en prévenant le client.** Une
notification ne remplace pas un consentement, et sur un modèle prépayé elle
arriverait après l'encaissement.

**Ne rien appliquer avant le renouvellement, même les hausses.** Cohérent, et
inutilement rigide : une hausse ne lèse personne, et la reporter obligerait à
expliquer à un client pourquoi il ne bénéficie pas d'une amélioration annoncée.
