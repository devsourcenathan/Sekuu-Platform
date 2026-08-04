# ADR-0009 — Extraction de la couche de paiement

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Billing a été construit pour un seul usage : Sekuu facture ses organisations clientes. Un abonnement prépayé, une facture, une TVA, un encaissement Mobile Money.

Sekuu Learn vend des formations à des apprenants. C'est un autre problème sur trois points, et un seul les résume : **le payeur n'est pas une organisation, et l'argent ne revient pas forcément à Sekuu.**

L'analyse complète est dans [docs/05-analyses/extraction-payments.md](../05-analyses/extraction-payments.md). Son verdict tient en une phrase : le travail utile n'était pas le déplacement de fichiers — la majorité de la couche n'avait aucune dépendance vers la facturation et se déplaçait mécaniquement.

Une option moins coûteuse existait : découpler sans déménager. Elle a été écartée parce que rien n'aurait empêché la re-fusion, et parce que Learn aurait dû dépendre du module `Billing` pour encaisser — ce qui rendait l'objectif inatteignable en pratique.

## Décision

### Le montant n'est jamais passé, il est demandé

`InitiatePayment::handle()` ne prend **aucun montant**. Il n'existe dans aucune signature accessible à l'appelant.

Le propriétaire de l'objet payé répond à `PayableSource::quote()` — il lit son objet, vérifie que ce payeur a le droit de le régler, et produit le montant et le libellé.

C'est la préservation d'une protection qui était jusqu'ici **structurelle** : le contrôleur chargeait la facture en base, aucun nombre ne traversait HTTP. Une méthode `encaisser(int $montant)` aurait déplacé ce contrôle d'un invariant vers une convention, et le premier appelant aurait écrit `$request->integer('amount')`.

Un objet de valeurs construit par l'appelant aurait été pire : plus falsifiable qu'un modèle chargé côté serveur, en donnant l'illusion d'avoir typé la sécurité.

### L'autorisation voyage avec le prix

`quote()` reçoit le payeur. Payments ne peut pas trancher qui a le droit de régler quoi — il ne sait rien des rôles. Sans ce contrôle chez le propriétaire, connaître un identifiant de facture suffirait à faire sonner le téléphone de quelqu'un.

### Le règlement est synchrone, la diffusion asynchrone

| Destinataire | Mécanisme | Moment |
| --- | --- | --- |
| Le propriétaire de l'objet, un seul | `PayableSource::settled()` / `failed()` | **dans** la transaction |
| Tous les autres | `DomainEvent` | après |

**Cette exception à [l'architecture § 11.1](../01-overview/architecture.md), qui réserve l'appel synchrone à la lecture, est délibérée.** Confier `settled()` à une file créerait une fenêtre où l'argent est encaissé et le service fermé, qu'un consommateur en échec définitif rendrait permanente. C'est exactement la défaillance que ce module existe pour empêcher.

`failed()` existe pour que le propriétaire prévienne son client dans ses propres termes, et publie **ses** événements. Notify associe les événements aux templates par un tableau littéral : une clé renommée ne tombe plus, sans exception ni journal, et le SMS d'échec disparaîtrait en silence.

### Un objet payable est désigné, jamais décrit

`subject_type` + `subject_id`, tous deux **NOT NULL**, sans clé étrangère. Le type suit `{module}.{ressource}`, la convention déjà en vigueur pour les événements.

Le `NOT NULL` n'est pas de la coquetterie : c'est ce qui permet à l'index d'unicité anti-double-invite de se passer de sa clause d'exclusion. La version précédente portait `invoice_id NULL` et excluait explicitement les intentions sans facture — **un paiement sans facture n'avait donc aucune protection**, et c'est précisément le cas nominal de Learn.

La résolution `type → propriétaire` passe par une table de configuration. Un type inconnu échoue durement : un repli silencieux ferait aboutir un paiement que personne ne saurait rattacher.

### Qui paie et qui encaisse sont deux colonnes

`payer_type` / `payer_id` d'un côté, `payee_organization_id` de l'autre — `null` signifiant « la plateforme encaisse pour elle-même ».

L'ancienne colonne `organization_id` désignait le payeur, parce qu'il n'y avait qu'un vendeur. La redéfinir comme « qui encaisse » aurait cassé Billing : Sekuu encaisse les abonnements, et Sekuu n'a pas de ligne dans `organizations`.

C'est la seule décision de ce chantier dont l'erreur aurait obligé à remigrer des données monétaires.

### Deux registres, pas un

`payment_transactions` porte `charge`, `fee`, `refund` — l'argent réellement encaissé. `credit_entries` porte `credit`, `debit`, `adjustment` — le crédit commercial d'une organisation.

La frontière existait déjà dans le code : `creditTypes()` excluait exactement `charge` et `fee`. La scission la rend physique et supprime deux clés étrangères qui allaient devenir inter-modules.

`append-only` est répliqué des deux côtés : c'est une propriété du registre, pas du module.

### Le périmètre des ADR précédentes

**ADR-0007** raisonne sur les abonnements prépayés. Elle reste entièrement valable — mais son périmètre est désormais **Billing seul**. Un achat ponctuel chez Learn n'a ni période, ni grâce, ni renouvellement.

**ADR-0008** reste valable **pour Payments**, et s'applique désormais à tout paiement, quel qu'en soit le motif. Sa formule « une seule intention vivante par facture » se lit maintenant « par objet payable ».

### `Money` monte dans le noyau

Billing en a besoin pour ses factures, Payments pour ses montants. Le dupliquer créerait deux définitions de l'exposant — et le franc CFA, seule devise sans centime, est précisément là où l'écart passerait inaperçu.

## Conséquences

**Positives**

* Un produit qui vend autre chose qu'un abonnement peut encaisser sans dépendre de Billing.
* Le trou de protection sur les paiements sans facture est comblé, avant qu'il ne devienne le cas nominal.
* Trois défauts préexistants ont été corrigés au passage : idempotence non scopée entre payeurs, absence de verrou sur l'intention pendant l'encaissement, et `settled_at` remis à `null` par un sondage tardif.
* Des tests d'architecture exécutables remplacent la discipline : aucun fichier de Payments ne peut référencer Billing, aucune clé étrangère ne peut relier les deux.

**Négatives**

* Une indirection de plus sur le chemin du paiement. Lire « comment une facture est réglée » demande maintenant d'ouvrir deux modules.
* La couture `settled()` ajoute un point de rejeu que rien ne couvrait : il est protégé par un test dédié, et par l'idempotence du propriétaire.
* Deux registres signifient deux requêtes pour un relevé complet.
* Le contrat OpenAPI duplique les enveloppes communes : `Yaml::parseFile` ne suit aucun fichier externe.

**Mitigations**

* La table de vérité de `AttemptStatus::allowsFailover()` est **exhaustive** et itère sur `cases()` : un état ajouté sans décision explicite fait échouer la suite.
* Les acquis des bacs à sable — la détection des refus chez Tranzak, la double forme du champ `transaction` chez Notch Pay, les montants imbriqués — sont chacun couverts par un test nommé d'après le démenti qu'ils corrigent.

## Ce que cela ne résout pas

**L'extraction rend Payments réutilisable ; elle ne le rend pas multi-bénéficiaire.**

`ChargeRequest` ne porte aucun compte de destination. `payment_transactions` n'a pas de type `payout`, ni d'état de reversement. La commission est traitée comme une charge de la plateforme, ce qui n'est vrai que tant que le marchand **est** Sekuu.

`payee_organization_id` existe et laisse la porte ouverte, mais rien derrière n'est construit. Encaisser pour le compte d'un tiers engage par ailleurs une responsabilité réglementaire que le code ne règle pas.

Le remboursement reste déclaré et jamais écrit. Un apprenant qui annule une formation est un cas de support banal, et le choix — ligne de registre négative, ou table avec son propre cycle de vie — doit être **pris** avant que des données monétaires existent, même s'il n'est pas implémenté tout de suite.
