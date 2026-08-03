# Sekuu Platform

## Vision Globale de la Plateforme

> **Version :** 2.0
> **Statut :** Vision & Architecture
> **Projet :** Sekuu Ecosystem
> **Dernière mise à jour :** Août 2026

Ce document décrit la vision et le périmètre fonctionnel de la plateforme.
Pour les détails techniques et de déploiement, voir [architecture.md](architecture.md).

---

# 1. Vision

Sekuu est une plateforme d'ingénierie logicielle conçue pour accélérer la création, le déploiement et l'évolution de produits SaaS.

L'objectif est de mutualiser toutes les fonctionnalités communes afin que chaque nouveau produit puisse se concentrer uniquement sur sa logique métier.

Chaque produit développé au sein de l'écosystème Sekuu doit pouvoir bénéficier immédiatement :

* d'une authentification centralisée ;
* d'un système de vérification (KYC/KYB) ;
* d'un système de notifications ;
* d'un moteur d'intelligence artificielle ;
* d'un stockage partagé ;
* d'une architecture standardisée ;
* d'un SDK commun ;
* d'une interface utilisateur homogène.

---

# 2. Objectifs

Sekuu Platform poursuit plusieurs objectifs.

## Réduction du temps de développement

Créer un nouveau SaaS ne doit plus nécessiter de reconstruire :

* l'authentification ;
* les notifications ;
* la gestion des organisations ;
* les abonnements ;
* les composants UI ;
* les appels API ;
* les traductions.

Ces fonctionnalités sont fournies par la plateforme.

---

## Standardisation

Tous les produits doivent partager :

* les mêmes conventions ;
* les mêmes composants ;
* les mêmes API ;
* les mêmes pratiques de développement.

Cela garantit une expérience cohérente pour les développeurs comme pour les utilisateurs.

---

## Évolutivité

L'architecture doit permettre :

* d'ajouter facilement de nouveaux modules ;
* d'introduire de nouveaux produits ;
* d'extraire certains modules en services indépendants lorsque cela devient nécessaire.

---

# 3. Architecture générale

```text
                          SEKUU ECOSYSTEM

 ┌────────────────────────────────────────────────────────────┐
 │                     Sekuu Platform                         │
 │                                                            │
 │  Identity   Verify   Notify   Billing   Storage   AI       │
 │  Search     Analytics                                     │
 └────────────────────────────────────────────────────────────┘
                            ▲
                            │
                SDK & Design System
                            ▲
                            │
 ┌────────────────────────────────────────────────────────────┐
 │                     Produits SaaS                         │
 │                                                            │
 │  Sekuu Learn                                               │
 │  DealerOS                                                  │
 │  Stock Manager                                             │
 │  Tontines                                                  │
 │  ClinicFlow                                                │
 │  ImmigraFlow                                               │
 │  Futurs produits                                           │
 └────────────────────────────────────────────────────────────┘
```

La plateforme fournit les services transverses.

Les produits implémentent uniquement leur logique métier.

---

# 4. Les domaines de Sekuu Platform

Chaque domaine possède une responsabilité clairement définie.

## Identity

Responsable de :

* authentification ;
* SSO ;
* utilisateurs ;
* organisations ;
* workspaces ;
* rôles globaux ;
* accès aux produits.

Identity ne gère ni la facturation et les abonnements (**Billing**), ni l'envoi des messages (**Notify**). Il consomme ces domaines.

Spécification détaillée : [Sekuu Identity](../03-services/identity/01-overview.md).

---

## Verify

Responsable de :

* KYC ;
* KYB ;
* vérification documentaire ;
* licences professionnelles ;
* intégration avec plusieurs fournisseurs.

---

## Notify

Responsable de :

* emails ;
* SMS ;
* notifications push ;
* WhatsApp ;
* templates ;
* files d'attente.

---

## Billing

Responsable de :

* abonnements ;
* plans ;
* paiements ;
* facturation ;
* gestion des licences.

---

## Storage

Responsable de :

* fichiers ;
* images ;
* vidéos ;
* documents ;
* gestion des pièces jointes.

---

## AI

Responsable de :

* génération de texte ;
* traduction ;
* OCR ;
* RAG ;
* embeddings ;
* analyse documentaire ;
* génération de quiz ;
* assistants conversationnels.

---

## Search

Responsable de :

* recherche globale ;
* indexation ;
* recherche plein texte ;
* recherche sémantique.

---

## Analytics

Responsable de :

* métriques ;
* tableaux de bord ;
* événements ;
* rapports.

---

# 5. Architecture de développement

Sekuu Platform est développé sous la forme d'un **Modular Monolith**.

Au démarrage :

* une seule application Laravel ;
* une seule base PostgreSQL ;
* plusieurs modules indépendants.

Chaque module possède :

* ses routes ;
* ses services ;
* ses migrations ;
* ses modèles ;
* ses tests.

Cette approche permet de limiter la complexité tout en préparant une éventuelle extraction en microservices.

---

# 6. Architecture de déploiement

Chaque domaine est exposé via un sous-domaine dédié.

Exemples :

```text
identity.sekuu.com/api/v1/...

verify.sekuu.com/api/v1/...

notify.sekuu.com/api/v1/...

billing.sekuu.com/api/v1/...

storage.sekuu.com/api/v1/...

ai.sekuu.com/api/v1/...

search.sekuu.com/api/v1/...

analytics.sekuu.com/api/v1/...
```

Toutes les routes publiques sont versionnées dès la première version. Aucune API n'est exposée sans numéro de version.

Au départ, tous ces sous-domaines pointent vers la même application Laravel.

Si un module devient suffisamment important, il peut être déployé séparément sans modifier les URL publiques.

---

# 7. Base de données

Il faut distinguer deux périmètres :

* **Sekuu Platform** possède **une seule** base de données, partagée par tous ses modules (Identity, Verify, Notify, Billing…). Chaque module y est propriétaire exclusif de ses tables.
* **Chaque produit SaaS** possède **sa propre** base de données, totalement séparée.

Un module n'accède jamais aux tables d'un autre module, et un produit n'accède jamais à la base de la plateforme ni à celle d'un autre produit.

Toutes les communications passent par des API ou des événements de domaine.

---

# 8. Les packages

En complément des services, Sekuu fournit plusieurs bibliothèques réutilisables.

## SDK

Le SDK encapsule toutes les communications avec Sekuu Platform.

Les produits n'appellent jamais directement les API.

Ils utilisent les méthodes du SDK.

Exemples :

* Authentification
* Vérifications
* Notifications
* Intelligence artificielle

---

## UI

Le Design System fournit :

* composants React ;
* composants React Native ;
* thème ;
* icônes ;
* formulaires ;
* tableaux ;
* navigation.

Tous les produits partagent la même identité visuelle.

---

## Starters

Des starters permettent de créer rapidement de nouveaux projets.

Exemples :

* Laravel Starter
* Next.js Starter
* React Starter
* React Native Starter

Chaque starter est déjà configuré avec :

* l'authentification ;
* le SDK ;
* le Design System ;
* l'internationalisation ;
* les conventions de développement.

---

# 9. Principes d'architecture

Tous les développements doivent respecter les principes suivants :

* une responsabilité par domaine ;
* une API versionnée ;
* des contrats OpenAPI ;
* une internationalisation native ;
* une architecture orientée domaines ;
* des conventions communes ;
* des composants réutilisables ;
* une documentation systématique.

---

# 10. Philosophie

Sekuu Platform privilégie la simplicité.

Les fonctionnalités communes sont développées une seule fois et réutilisées partout.

Les produits restent indépendants.

Les services de la plateforme évoluent progressivement.

Aucun module n'est extrait en microservice tant qu'un besoin réel ne le justifie.

---

# 11. Vision à long terme

À terme, Sekuu doit permettre à un développeur de créer un nouveau produit SaaS en quelques heures.

Le développeur choisit un starter, configure son domaine métier et bénéficie immédiatement de tous les services de la plateforme.

L'objectif n'est pas seulement de construire des applications, mais de bâtir un écosystème cohérent où chaque nouveau produit profite automatiquement des capacités de la plateforme et contribue à son évolution.

Sekuu Platform devient ainsi le socle technique commun de l'ensemble de l'écosystème Sekuu.
