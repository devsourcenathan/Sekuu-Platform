# ADR-0010 — Encaisser depuis un service externe

> **Statut :** Acceptée — **non implémentée**
> **Date :** Août 2026

---

## Contexte

L'[ADR-0009](adr-0009-payments-module-extraction.md) a extrait la couche de paiement pour qu'un autre produit puisse encaisser. Elle a résolu le problème pour un produit **dans le monolithe** : il implémente `PayableSource` et s'enregistre dans une configuration.

Sekuu Learn sera un **service externe**, avec sa propre base de code, qui ne consomme que l'API HTTP. Il ne peut implémenter aucune interface PHP.

Trois choses lui manquent aujourd'hui, et aucune n'est un détail :

1. `POST /api/v1/payments` appartient à Billing et code en dur `InvoicePayable::TYPE`.
2. Les scopes de clé d'API sont une **liste fermée** (`IssueApiKey::SCOPES`) qui ne contient que `notifications.*`.
3. Payments reçoit des callbacks ; il n'en émet aucun.

## Le problème que cette ADR tranche

Le contrat interne repose sur deux appels que la plateforme fait **vers** le produit : `quote()` pour obtenir le prix, `settled()` pour remettre l'issue.

Sur un service externe, ces deux appels deviendraient des requêtes HTTP sortantes. Le second serait émis **à l'intérieur de la transaction d'encaissement** — verrous tenus pendant un aller-retour réseau vers un tiers. C'est exactement la fenêtre de blocage que `settled()` synchrone existe pour éviter en interne.

Il faut donc inverser les deux échanges, et assumer ce que cette inversion coûte.

## Décision

### Le prix est déclaré, et la clé dit qui a le droit de le déclarer

L'invariant n'est pas « le montant ne vient jamais d'HTTP ». Il est : **seul le propriétaire de l'objet nomme son prix.**

En interne, l'interface le garantit. En externe, c'est une clé d'API portant le scope `payments.charge` **et une liste de `subject_types` autorisés**.

Sekuu Learn peut déclarer le prix de `learn.enrollment`, et rien d'autre — ni une facture Billing, ni la commande d'un autre produit.

La restriction vit sur la clé, pas dans le scope : `IssueApiKey::SCOPES` est une liste fermée, et y encoder un type par produit la ferait croître sans fin. Une colonne `subject_types` sur `api_keys`, vérifiée à chaque création, est explicite et interrogeable.

### Cette clé est strictement serveur à serveur

C'est la condition qui fait tenir tout le reste. Exposée à un client final, elle permet de déclarer n'importe quel montant sur n'importe quel objet du produit.

C'est écrit en toutes lettres dans la spécification, et ce doit être la première chose qu'un intégrateur lit.

### Le payeur n'a pas à exister dans Identity

`payer_type` suit `{module}.{ressource}`, comme `subject_type`. Un apprenant Learn n'est pas un utilisateur Sekuu.

Conséquence acceptée : **Payments ne peut prévenir personne.** La résolution du contact passe par `IdentityContract`, qui ne connaît pas ce payeur. Les messages d'échec, de reçu et de relance sont à la charge du produit.

Cela reste cohérent avec le contrat interne, où `failed()` existe précisément pour que le propriétaire prévienne son client dans ses termes.

### L'issue est livrée par webhook **et** par sondage

Les deux, obligatoirement. C'est la même raison qui fait que Payments ne croit pas les callbacks des agrégateurs : un webhook se perd, et un produit qui ne l'apprend pas laisse un client payé sans service.

S'y ajoute un endpoint de réconciliation, seul filet quand les deux ont échoué.

### Ce que l'on renonce à garantir

En interne, « l'argent est encaissé » et « le service est ouvert » sont **atomiques**.

Un service externe ne peut pas participer à cette transaction. Il existera une fenêtre — brève, mais réelle — pendant laquelle un client a payé et n'a pas son service.

**Cette perte est irréductible et doit être dite**, pas contournée par un mécanisme qui donnerait l'illusion de l'atomicité. On la rend courte et rattrapable ; on ne la supprime pas.

## Conséquences

**Positives**

* Un produit dans une autre base de code, un autre langage, un autre hébergement, peut encaisser.
* L'invariant du prix survit, sous une forme vérifiable et révocable.
* La restriction par `subject_type` limite les dégâts d'une clé fuitée à un seul produit.

**Négatives**

* **La perte d'atomicité**, décrite ci-dessus. C'est le coût principal.
* Le prix est **figé à la création** : le client valide deux minutes plus tard, et l'objet a pu changer entre-temps. La course existe en interne, mais y est plus étroite.
* L'autorisation de l'utilisateur final quitte la plateforme. Payments ne saura jamais si l'apprenant avait le droit de s'inscrire.
* Des webhooks sortants à écrire, avec signature, réessais, rotation de secret et endpoints injoignables à gérer — un chantier à part entière.
* Deux chemins d'intégration à documenter et à maintenir, aux garanties différentes. Un intégrateur qui lit le mauvais guide se trompe.

**Mitigations**

* La spécification dit explicitement ce qu'un service externe **ne peut pas** obtenir, avant de dire ce qu'il obtient.
* Le guide interne porte désormais un avertissement en tête renvoyant vers le guide externe.
* Un produit qui n'implémente que le webhook aura tôt ou tard un client payé sans service : le sondage et la réconciliation sont présentés comme obligatoires, pas comme des options.

## Ce qui a tranché

Le même raisonnement que l'[ADR-0008](adr-0008-payment-aggregators-failover.md) : l'asymétrie des dégâts.

Un produit externe qui ne peut pas encaisser ne rapporte rien. Un produit externe qui pourrait déclarer un montant arbitraire sur les objets d'un autre produit permettrait de payer 100 XAF une formation à 15 000 — ou pire, de manipuler les factures de Billing.

D'où le choix : autoriser la déclaration du prix, mais la borner à un périmètre que la clé porte explicitement, et le dire assez fort pour que personne ne mette cette clé dans un navigateur.

## Alternatives écartées

**Appeler le service externe en synchrone pour `quote()` et `settled()`** — c'est-à-dire transposer littéralement le contrat interne. Mettrait un aller-retour réseau vers un tiers à l'intérieur de la transaction d'encaissement, verrous tenus. Une lenteur chez le produit deviendrait une lenteur de la caisse.

**Faire déclarer le montant sans restriction de `subject_type`** — une clé fuitée permettrait alors de manipuler les objets de tous les produits, Billing compris.

**N'exposer que le webhook, sans sondage** — c'est l'erreur que Payments a déjà refusé de commettre vis-à-vis des agrégateurs, et pour la même raison.

**Faire de Learn un module du monolithe** — écarté par le produit, pas par la technique. Cela aurait évité tout ce chantier et préservé l'atomicité, au prix d'une base de code commune.
