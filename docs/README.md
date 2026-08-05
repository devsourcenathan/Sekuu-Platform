# Documentation — Sekuu Platform

> **Dernière mise à jour :** Août 2026

Sekuu Platform est le socle technique commun de l'écosystème Sekuu : une plateforme de services partagés (identité, vérification, notifications, facturation, stockage, IA, recherche, analytics) sur laquelle s'appuient tous les produits SaaS Sekuu.

---

## Par où commencer

| Vous voulez… | Lisez |
| --- | --- |
| Savoir ce qui est **réellement implémenté** | [Récapitulatif](RECAP.md) |
| Comprendre le projet | [Vision](01-overview/vision.md) |
| Comprendre l'architecture technique | [Architecture](01-overview/architecture.md) |
| Développer une API | [API Guidelines](02-standards/api-guidelines.md) puis [Codes d'erreur](02-standards/error-codes.md) |
| Intégrer l'authentification | [Sécurité & tokens](02-standards/security.md) |
| Travailler sur Identity | [Identity — vision](03-services/identity/01-overview.md) |
| Faire encaisser un **module** du monolithe | [Payments — intégrer un module](03-services/payments/06-integration.md) |
| Faire encaisser un **service externe** | [Payments — API externe](03-services/payments/07-external-api.md) |
| **Rendre** l'argent | [Payments — remboursements](03-services/payments/08-refunds.md) |
| Attacher un **fichier** à un objet | [Storage — intégrer un module](03-services/storage/05-integration.md) |
| Ajouter un **magasin** — compte S3, R2, Drive | [Storage — destinations](03-services/storage/06-destinations.md) — puis `storage:destination` |
| Faire stocker un **service externe** | [Storage — API externe](03-services/storage/07-external-api.md) |
| Faire appel à un **modèle de langage** | [AI — intégrer un module](03-services/ai/06-integration.md) |
| Apporter sa **propre clé d'IA** | [AI — API externe](03-services/ai/07-external-api.md) |
| Comprendre un choix d'architecture | [Décisions (ADR)](#04-decisions--décisions-darchitecture) |
| **Mettre en production** | [Mise en service](06-operations/01-go-live.md) |

Parcours conseillé pour une première lecture : Vision → Architecture → API Guidelines → Identity.

---

## Organisation

```text
docs/
├── 01-overview/     Vision et architecture de la plateforme
├── 02-standards/    Règles applicables à tous les modules et produits
├── 03-services/     Spécification de chaque domaine
├── 04-decisions/    Décisions d'architecture (ADR)
└── 06-operations/   Mise en service, CI, exploitation
```

### 01-overview — Vue d'ensemble

| Document | Contenu |
| --- | --- |
| [vision.md](01-overview/vision.md) | Objectifs, domaines fonctionnels, packages (SDK, UI, starters), philosophie |
| [architecture.md](01-overview/architecture.md) | Monolithe modulaire, sous-domaines, base de données, communication entre modules, déploiement |

### 02-standards — Standards transverses

Ces documents sont **normatifs**. Aucun module ne peut y déroger.

| Document | Contenu |
| --- | --- |
| [api-guidelines.md](02-standards/api-guidelines.md) | REST, versionnement, format des réponses, pagination, filtres, rate limiting, idempotence, webhooks |
| [error-codes.md](02-standards/error-codes.md) | Catalogue de référence des codes d'erreur |
| [security.md](02-standards/security.md) | Tokens JWT, refresh, rotation des clés, révocation, isolation multi-tenant, mots de passe, API keys |

### 03-services — Services de la plateforme

| Service | État | Documentation |
| --- | --- | --- |
| **Identity** | **Implémenté** | [vision](03-services/identity/01-overview.md) · [modèle de données](03-services/identity/02-data-model.md) · [API](03-services/identity/03-api.md) · [contrat OpenAPI](../Modules/Identity/openapi.yaml) |
| **Notify** | **Implémenté** — canaux email, SMS et interne ; WhatsApp et push non développés | [vision](03-services/notify/01-overview.md) · [modèle de données](03-services/notify/02-data-model.md) · [API](03-services/notify/03-api.md) · [événements](03-services/notify/04-events.md) · [contrat OpenAPI](../Modules/Notify/openapi.yaml) |
| Verify | À spécifier | — |
| **Payments** | **Implémenté** — Notch Pay, Tranzak | [vision](03-services/payments/01-overview.md) · [modèle de données](03-services/payments/02-data-model.md) · [API](03-services/payments/03-api.md) · [événements](03-services/payments/04-events.md) · [agrégateurs](03-services/payments/05-providers.md) · [intégrer un module](03-services/payments/06-integration.md) · [**service externe**](03-services/payments/07-external-api.md) · [remboursements](03-services/payments/08-refunds.md) · [contrat OpenAPI](../Modules/Payments/openapi.yaml) |
| **Billing** | **Implémenté** — abonnements prépayés | [vision](03-services/billing/01-overview.md) · [modèle de données](03-services/billing/02-data-model.md) · [API](03-services/billing/03-api.md) · [événements](03-services/billing/04-events.md) |
| **Storage** | **Implémenté** — téléversement direct, destinations multiples, PDF de facture | [vision](03-services/storage/01-overview.md) · [modèle de données](03-services/storage/02-data-model.md) · [API](03-services/storage/03-api.md) · [événements](03-services/storage/04-events.md) · [intégrer un module](03-services/storage/05-integration.md) · [**destinations**](03-services/storage/06-destinations.md) · [service externe](03-services/storage/07-external-api.md) |
| **AI** | **Spécifié**, non implémenté | [vision](03-services/ai/01-overview.md) · [modèle de données](03-services/ai/02-data-model.md) · [API](03-services/ai/03-api.md) · [événements](03-services/ai/04-events.md) · [**fournisseurs et comptes**](03-services/ai/05-providers.md) · [intégrer un module](03-services/ai/06-integration.md) · [service externe](03-services/ai/07-external-api.md) |
| Search | À spécifier | — |
| Analytics | À spécifier | — |

### 04-decisions — Décisions d'architecture

| ADR | Décision |
| --- | --- |
| [ADR-0001](04-decisions/adr-0001-modular-monolith.md) | Monolithe modulaire plutôt que microservices |
| [ADR-0002](04-decisions/adr-0002-api-versioning.md) | Versionnement des API dans l'URL, dès la v1 |
| [ADR-0003](04-decisions/adr-0003-two-level-roles.md) | Rôles à deux niveaux : plateforme et métier |
| [ADR-0004](04-decisions/adr-0004-jwt-stateless-tokens.md) | Access tokens JWT stateless, refresh tokens opaques |
| [ADR-0005](04-decisions/adr-0005-notify-asynchronous-delivery.md) | Notify : envoi asynchrone, contenu figé à l'acceptation |
| [ADR-0006](04-decisions/adr-0006-transactional-vs-marketing.md) | Catégories de messages et liste de suppression |
| [ADR-0007](04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md) | Billing : abonnements prépayés plutôt que reconduction automatique |
| [ADR-0008](04-decisions/adr-0008-payment-aggregators-failover.md) | Payments : agrégateurs de paiement et règle de bascule |
| [ADR-0009](04-decisions/adr-0009-payments-module-extraction.md) | Extraction de la couche de paiement hors de Billing |
| [ADR-0010](04-decisions/adr-0010-external-payment-api.md) | Encaisser depuis un service externe |
| [ADR-0011](04-decisions/adr-0011-refunds.md) | Payments : rendre l'argent, sans décaissement automatique |
| [ADR-0012](04-decisions/adr-0012-direct-upload.md) | Storage : les octets ne traversent jamais la plateforme |
| [ADR-0013](04-decisions/adr-0013-invoice-pdf-frozen.md) | Le PDF de facture est produit une fois et figé |
| [ADR-0014](04-decisions/adr-0014-storage-destinations.md) | Storage : le magasin est une donnée, pas une configuration |
| [ADR-0015](04-decisions/adr-0015-ai-task-not-model.md) | AI : l'appelant décrit une tâche, jamais un modèle |
| [ADR-0016](04-decisions/adr-0016-ai-spend-and-privacy.md) | AI : ce que l'IA coûte, et ce qu'elle emporte |
| [ADR-0017](04-decisions/adr-0017-ai-accounts.md) | AI : le compte est une donnée, et un produit peut apporter le sien |

### 06-operations — Exploitation

| Document | Contenu |
| --- | --- |
| [01-go-live.md](06-operations/01-go-live.md) | Ce qu'il faut pour qu'un premier franc soit encaissé pour de vrai |
| [02-ci.md](06-operations/02-ci.md) | Intégration continue : ce qu'elle vérifie, et dans quel ordre |
| [03-deployment.md](06-operations/03-deployment.md) | Premier déploiement, redéploiements, retour arrière |
| [04-render.md](06-operations/04-render.md) | Déployer sur Render : image Docker, disque éphémère, files |
| [05-free-tier.md](06-operations/05-free-tier.md) | Tenir sur l'offre gratuite, et ce que cela coûte |

---

## Règles de rédaction

* **Un sujet, un document.** Une information n'est décrite qu'à un seul endroit ; ailleurs, on y renvoie par un lien.
* **Source de vérité explicite.** Lorsqu'un sujet est traité à plusieurs niveaux de détail, le document le plus précis fait autorité et le dit en en-tête.
* **Tout fichier est en `.md`**, nommé en `kebab-case`.
* **Une décision structurante donne lieu à un ADR**, jamais à un paragraphe noyé dans une spécification.
* **Pas de document « v2 » à côté d'un « v1 ».** On met à jour le document et on incrémente son numéro de version en en-tête.
* Chaque document porte une version, un statut et une date de dernière mise à jour.

---

## Sources de vérité

En cas de contradiction entre deux documents, l'ordre de priorité est le suivant :

1. Le contrat `openapi.yaml` du service concerné — il fait foi sur l'API.
2. La spécification du service (`03-services/…`) — elle fait foi sur son modèle de données.
3. Les standards (`02-standards/…`) — ils font foi sur les règles transverses.
4. L'architecture et la vision (`01-overview/…`) — elles donnent le cadre général.

Toute contradiction constatée doit être corrigée, pas contournée.

---

## Frontières entre domaines

Les confusions les plus fréquentes, tranchées une fois pour toutes :

| Sujet | Propriétaire | Précision |
| --- | --- | --- |
| Plans, abonnements, factures | **Billing** | Identity ne stocke que des droits d'accès (`organization_products`) |
| Encaisser de l'argent | **Payments** | Il ignore ce qu'il encaisse : Billing lui dit combien vaut une facture |
| Combien vaut un objet, et qui peut le payer | **Le module propriétaire** | Payments ne peut pas trancher : il ne sait rien des rôles |
| Envoi d'emails, SMS, push | **Notify** | Identity publie des événements, il n'envoie rien |
| Décision d'envoyer un message | **Le module émetteur** | Notify n'a aucune logique métier |
| Réputation d'expédition | **Notify** | Liste de suppression, prime sur toute catégorie |
| Vérification d'identité (KYC/KYB) | **Verify** | — |
| Permissions métier | **Chaque produit** | Identity ne connaît que les rôles globaux |
| Base de données | **Un module = ses tables** | Aucune jointure inter-domaines |

---

## À faire

Documents identifiés comme nécessaires, non encore rédigés :

* Spécifications des services Verify, Storage, AI, Search, Analytics.
* Contrats `openapi.yaml` par service.
* Spécification du SDK et du Design System.
* Conventions de code et de contribution.
* Stratégie d'internationalisation.
* Observabilité : logs, métriques, traces distribuées.
* Environnements, CI/CD et procédure de déploiement.
* Conformité RGPD et politique de rétention détaillée.
