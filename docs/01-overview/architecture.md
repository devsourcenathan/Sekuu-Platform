# Architecture de Sekuu Platform

> **Version :** 2.0
> **Statut :** Architecture de référence
> **Dernière mise à jour :** Août 2026

Ce document décrit l'architecture technique et de déploiement de Sekuu Platform.
Pour la vision produit et le périmètre fonctionnel de chaque domaine, voir [vision.md](vision.md).

---

# 1. Objectif

L'objectif de cette architecture est de permettre à Sekuu Platform d'évoluer progressivement :

* sans créer une infrastructure complexe dès le départ ;
* tout en gardant la possibilité d'extraire certains services dans le futur sans modifier les produits consommateurs.

Cette approche suit le principe du **Modular Monolith First** (voir [ADR-0001](../04-decisions/adr-0001-modular-monolith.md)).

---

# 2. Principe

Au démarrage, **Sekuu Platform est une seule application Laravel**.

Cette application est découpée en modules indépendants.

Chaque module possède :

* ses routes ;
* ses contrôleurs ;
* ses services ;
* ses modèles ;
* ses migrations ;
* ses tests.

Tous les modules partagent :

* la même application Laravel ;
* la même base PostgreSQL.

---

# 3. Architecture

```text
                     SEKUU PLATFORM

               Laravel Modular Monolith

 ┌──────────────────────────────────────────────────────┐
 │                                                      │
 │  Identity    Verify    Notify    Billing             │
 │  Storage     AI        Search    Analytics           │
 │                                                      │
 └──────────────────────────────────────────────────────┘
                     │
                     │
             PostgreSQL (Unique)
```

Chaque domaine est indépendant dans le code mais partage l'infrastructure.

---

# 4. Pourquoi un monolithe modulaire ?

Les microservices apportent beaucoup d'avantages mais également une forte complexité :

* plusieurs bases de données ;
* plusieurs déploiements ;
* plusieurs pipelines CI/CD ;
* plusieurs sauvegardes ;
* plusieurs systèmes de monitoring ;
* plusieurs systèmes de logs.

Pour une petite équipe ou un développeur seul, cette complexité est rarement rentable.

Le monolithe modulaire permet de bénéficier :

* d'un développement plus rapide ;
* d'une maintenance simplifiée ;
* d'une séparation claire des responsabilités ;
* d'une migration progressive vers des services indépendants.

---

# 5. Organisation des modules

```text
Modules/

Identity/

Verify/

Notify/

Billing/

Storage/

AI/

Search/

Analytics/
```

Chaque module est totalement autonome.

Exemple :

```text
Modules/

Identity/

├── Application/

├── Domain/

├── Infrastructure/

├── Presentation/

├── Routes/

├── Database/

└── Tests/
```

---

# 6. Le module AI

L'intelligence artificielle est un domaine de la plateforme au même titre qu'Identity ou Verify.

Le module **AI** fournit des capacités génériques d'intelligence artificielle à tous les produits.
Il n'implémente jamais la logique métier d'un produit.

## 6.1 Responsabilités

* Génération de texte
* Résumé de contenu
* Traduction
* Génération de quiz
* Génération de documents
* Analyse de documents
* OCR
* Recherche sémantique (RAG)
* Classification
* Modération
* Embeddings
* Génération de titres et de descriptions
* Suggestions intelligentes
* Intégration avec plusieurs fournisseurs (Anthropic, OpenAI, Google Gemini, Mistral, modèles locaux, etc.)

## 6.2 Consommation

Le module AI est consommé par tous les produits via son API versionnée.

Exemples :

* Sekuu Learn → génération de cours, quiz et corrigés.
* DealerOS → génération de descriptions produits.
* Stock → prédictions de stock et aide à la recherche.
* ClinicFlow → résumé de consultations (selon les contraintes réglementaires).
* ImmigraFlow → aide à la préparation des dossiers.

L'objectif est qu'un changement de fournisseur d'IA ne nécessite **aucune** modification dans les produits.

---

# 7. Gestion des sous-domaines

Même si une seule application Laravel est déployée, chaque module est exposé via un sous-domaine dédié.

```text
identity.sekuu.com

verify.sekuu.com

notify.sekuu.com

billing.sekuu.com

storage.sekuu.com

ai.sekuu.com

search.sekuu.com

analytics.sekuu.com
```

Tous ces sous-domaines pointent vers la même application Laravel.

Le routage interne dirige ensuite la requête vers le bon module.

---

# 8. Routage

Chaque module enregistre ses propres routes.

Toutes les routes publiques sont versionnées dès la première version — voir [ADR-0002](../04-decisions/adr-0002-api-versioning.md) et les [API Guidelines](../02-standards/api-guidelines.md).

Identity

```text
identity.sekuu.com

/api/v1/auth/login

/api/v1/auth/logout

/api/v1/users

/api/v1/organizations
```

Verify

```text
verify.sekuu.com

/api/v1/verifications

/api/v1/providers

/api/v1/webhooks
```

Notify

```text
notify.sekuu.com

/api/v1/emails

/api/v1/sms

/api/v1/push
```

AI

```text
ai.sekuu.com

/api/v1/chat

/api/v1/completions

/api/v1/summarize

/api/v1/translate

/api/v1/embeddings

/api/v1/documents/analyze

/api/v1/ocr
```

Le client pense communiquer avec plusieurs services alors que l'infrastructure n'en exécute qu'un seul.

---

# 9. Architecture réseau

```text
                   Internet
                       │
             ┌─────────┴─────────┐
             │ Reverse Proxy/CDN │
             └─────────┬─────────┘
                       │
      ┌────────────────┼─────────────────┐
      │                │                 │
identity.sekuu.com verify.sekuu.com notify.sekuu.com
      │                │                 │
      └────────────────┴─────────────────┘
                       │
               Sekuu Platform
              (Application Laravel)
                       │
                PostgreSQL Unique
```

Le reverse proxy (Nginx, Caddy, Traefik ou Cloudflare) redirige les sous-domaines vers la même application.

---

# 10. Base de données

## 10.1 Deux niveaux de bases

Il faut distinguer deux périmètres, souvent confondus :

| Périmètre | Bases | Contenu |
| --- | --- | --- |
| **Sekuu Platform** | **Une seule** base (`sekuu_platform`) | Les données de tous les modules de la plateforme (Identity, Verify, Notify, Billing…) |
| **Produits SaaS** | **Une base par produit** | Les données métier de chaque produit (patients, véhicules, cours…) |

Autrement dit : la plateforme est mono-base, l'écosystème est multi-base.

Un produit n'accède jamais à la base de la plateforme ni à celle d'un autre produit — uniquement à leurs API.

## 10.2 Organisation de la base plateforme

Les tables sont regroupées par domaine fonctionnel. Chaque module est **propriétaire exclusif** de ses tables.

Identity

```text
users

organizations

memberships

membership_roles

workspaces

workspace_members

global_roles

global_permissions

role_permissions

products

organization_products

invitations

sessions

refresh_tokens

oauth_accounts

audit_logs
```

Verify

```text
verification_requests

verification_sessions

verification_documents

verification_results
```

Notify

```text
notifications

notification_templates

notification_logs
```

Billing

```text
plans

subscriptions

invoices

transactions
```

Un module ne doit **jamais** lire ni modifier directement les tables d'un autre module.

Les échanges passent par les services du domaine concerné (section 11).

---

# 11. Communication entre modules

Deux mécanismes, et deux seulement.

## 11.1 Appel synchrone via le contrat du domaine

Un module appelle un autre module à travers une **interface de service publique** exposée par le domaine propriétaire — jamais son modèle Eloquent, jamais sa table.

```text
Billing  ──►  BillingContract::activateProduct(orgId, productId)

Identity ──►  IdentityContract::getOrganization(orgId)
```

Ces interfaces vivent dans une couche partagée. Le jour où un module est extrait, seule l'implémentation change : l'appel local devient un appel HTTP vers l'API versionnée du service. Les appelants ne sont pas modifiés.

Règle : un appel synchrone n'est autorisé que pour une **lecture**, ou pour une opération dont l'appelant a besoin du résultat immédiat.

## 11.2 Événements de domaine (asynchrone)

Pour tout le reste — et notamment les effets de bord — les modules publient des événements.

```text
Identity  publie  UserRegistered          →  Notify   envoie l'email de bienvenue

Identity  publie  InvitationSent          →  Notify   envoie l'invitation

Billing   publie  SubscriptionActivated   →  Identity active les produits de l'organisation

Billing   publie  SubscriptionExpired     →  Identity suspend les produits de l'organisation
```

Les événements sont traités via des queues (Redis au démarrage).

Un consommateur doit être **idempotent** : le même événement peut être livré plusieurs fois.

## 11.3 Ce qui est interdit

* Lire la table d'un autre module (jointure SQL inter-domaines).
* Instancier le modèle Eloquent d'un autre module.
* Appeler directement un fournisseur externe qui relève d'un autre domaine — par exemple Identity qui appellerait un fournisseur SMTP au lieu de passer par Notify.

---

# 12. Évolution vers des services indépendants

L'un des principaux objectifs est de permettre une extraction progressive.

Aujourd'hui :

```text
Identity
Verify
Notify

↓

Une seule application
```

Demain :

```text
Identity

↓

Toujours dans Sekuu Platform

----------------------------

Verify

↓

Application indépendante

----------------------------

Notify

↓

Toujours dans Sekuu Platform
```

L'URL ne change jamais.

Exemple :

Avant :

```text
verify.sekuu.com
```

Après extraction :

```text
verify.sekuu.com
```

Le consommateur ne voit aucune différence.

---

# 13. Pourquoi conserver les sous-domaines dès le début ?

Même lorsqu'un module est interne, il est exposé via son propre domaine.

Cela présente plusieurs avantages :

* contrat d'API stable ;
* indépendance des consommateurs ;
* migration transparente ;
* meilleure séparation logique.

---

# 14. Déploiement

Version initiale

```text
1 dépôt Git

1 application Laravel

1 pipeline CI/CD

1 serveur

1 base PostgreSQL
```

Version intermédiaire

```text
1 dépôt Git

1 application Laravel

2 serveurs

1 base PostgreSQL
```

Version avancée

```text
Plusieurs applications

Plusieurs pipelines

Plusieurs bases

Services totalement indépendants
```

---

# 15. Philosophie

Sekuu Platform ne doit pas être pensé comme une collection de microservices dès sa création.

Il doit être conçu comme un **monolithe modulaire**, où les frontières entre les domaines sont clairement définies.

Chaque domaine est développé comme s'il était déjà un service indépendant.

Lorsqu'un domaine devient suffisamment volumineux, il peut être extrait sans impacter les produits consommateurs.

Cette approche permet d'obtenir le meilleur compromis entre simplicité, évolutivité et coût de maintenance.

---

# 16. Vision à long terme

À terme, Sekuu Platform deviendra une véritable plateforme de services partagés.

Chaque service pourra évoluer indépendamment, être déployé séparément et disposer de sa propre infrastructure.

Cependant, cette évolution ne sera réalisée que lorsqu'un besoin technique ou fonctionnel le justifiera.

Le principe directeur est le suivant :

> **Commencer simple, concevoir pour évoluer et extraire uniquement lorsque cela apporte une réelle valeur.**
