# Sekuu Platform - Vision & Architecture

> **Version :** 1.0
> **Auteur :** Nathan Tchinda
> **Statut :** Draft

---

# Vision

L'objectif de **Sekuu Platform** est de construire une plateforme de développement permettant de créer rapidement des applications SaaS professionnelles.

Contrairement à une simple suite d'applications, Sekuu Platform est composée de deux parties :

* une **plateforme technique interne** (Core) réutilisable ;
* plusieurs **produits SaaS** destinés à différents marchés.

L'objectif est de ne jamais repartir de zéro lors de la création d'un nouveau produit.

---

# Philosophie

## Construire une seule fois

Toutes les fonctionnalités communes doivent être développées une seule fois.

Exemples :

* Authentification
* Gestion des utilisateurs
* Paiements
* Notifications
* Permissions
* Internationalisation
* Dashboard
* Upload de fichiers
* Emails
* Logging

Tous les produits utiliseront ces fonctionnalités.

---

# Objectifs

* Réduire le temps de développement.
* Standardiser les applications.
* Mutualiser le code.
* Simplifier la maintenance.
* Faciliter l'ajout de nouveaux SaaS.
* Offrir une expérience utilisateur cohérente.
* Permettre à chaque produit d'être totalement indépendant.

---

# Architecture générale

```
                    Sekuu Platform
                           │
      ┌────────────────────┴────────────────────┐
      │                                         │
  Sekuu Core                               Sekuu UI
      │                                         │
      ├──────────────┬──────────────────────────┤
      │              │                          │
 Sekuu SDK     Shared Services             Mobile SDK
      │
      ├───────────────────────────────────────────────┐
      │                                               │
 Sekuu Learn                                   Sekuu Suite
                                                    │
                                              ├── Invoice
                                              ├── Stock
                                              ├── CRM
                                              ├── HR
                                              ├── Booking
                                              ├── Support
                                              └── Forms

      │
      ├───────────────────────────────────────────────┐
      │                                               │
 DealerOS     Stock Manager     Tontines     ClinicFlow
```

---

# Les couches

## 1. Sekuu Core

Le Core est le moteur de tous les produits.

Il ne contient **aucune logique métier spécifique**.

### Modules

* Authentication
* Organizations
* Users
* Teams
* Workspaces
* Roles
* Permissions
* Billing
* Subscription
* Notifications
* Emails
* File Storage
* Settings
* Audit Logs
* API Keys
* Webhooks
* Jobs
* Queue
* Search
* Feature Flags
* Internationalization

---

## 2. Sekuu UI

Bibliothèque de composants réutilisables.

### Composants

* Button
* Input
* Select
* Checkbox
* Modal
* Drawer
* Tabs
* Card
* Badge
* Avatar
* Alert
* Toast
* Loader
* Empty State

### Composants métier

* DataTable
* ResourceTable
* ResourceForm
* ResourceDetails
* Filters
* Search
* Pagination
* CSV Import
* Excel Export
* PDF Export
* Charts
* File Upload

---

## 3. Sekuu SDK

Bibliothèque TypeScript commune.

Elle contient :

* API Client
* Authentication
* Validators
* Money Helpers
* Date Helpers
* Notification Client
* Storage Client
* Utilities

Tous les projets React utiliseront cette bibliothèque.

---

## 4. Shared Services

Services partagés entre tous les produits.

* Email
* SMS
* WhatsApp
* Push Notifications
* PDF
* Search
* OCR
* AI Services (plus tard)
* Payments
* Monitoring

---

# Internationalisation

Tous les produits doivent être internationaux dès leur création.

## Langues

* Français
* Anglais
* Espagnol
* Portugais

Ajout d'autres langues sans modification du code métier.

---

## Devises

* EUR
* USD
* CAD
* GBP
* XAF

Toutes les devises doivent être configurables.

---

## Formats

* Date
* Heure
* Fuseau horaire
* Nombre
* Monnaie

Aucune valeur ne doit être codée en dur.

---

# Multi-tenant

Chaque entreprise possède :

* ses utilisateurs
* ses rôles
* ses données
* ses paramètres
* son abonnement
* sa devise
* sa langue
* son fuseau horaire

Les données des entreprises sont totalement isolées.

---

# Authentification

Prévoir :

* Email / Password
* Google
* Microsoft
* GitHub
* 2FA
* Magic Link (plus tard)

À terme, mise en place d'un **SSO** pour permettre à un utilisateur d'accéder à plusieurs produits Sekuu avec une seule connexion.

---

# Produits

## Sekuu Learn

Plateforme de formation.

Fonctionnalités :

* Cours
* Quiz
* Certificats
* Paiements
* Progression

---

## Sekuu Suite

Suite destinée aux PME.

Modules :

* Facturation
* Stock
* CRM
* RH
* Booking
* Support
* Forms

Tous les modules utilisent le même workspace.

---

## Produits indépendants

Les produits suivants restent autonomes.

### DealerOS

Gestion de revendeurs.

Technologie actuelle :

* Laravel
* React + Vite

Migration progressive vers les composants communs.

---

### Stock Manager

Gestion de stock.

Technologie actuelle :

* NestJS
* React + Vite

Aucune migration immédiate.

Réutilisation progressive de :

* Sekuu UI
* Sekuu SDK
* Shared Services

---

### Tontines

Technologie actuelle :

* Supabase
* React + Vite

Migration uniquement si elle apporte une vraie valeur métier.

---

## Futurs produits

* ClinicFlow
* ImmigraFlow
* GarageFlow
* SchoolFlow
* VetFlow

Tous utiliseront directement Sekuu Core.

---

# Principe de migration

Les produits existants ne seront pas réécrits immédiatement.

Règles :

* Ne migrer que lorsqu'un gain réel est identifié.
* Prioriser le partage des composants et services plutôt que la réécriture complète.
* Maintenir la compatibilité avec les technologies existantes.

---

# Technologies

## Backend

* Laravel (référence pour les nouveaux projets)
* NestJS (projets existants)
* Supabase (projets existants)

---

## Frontend

* React
* Next.js
* Vite

---

## Mobile

* React Native
* Expo

---

## Infrastructure

* Docker
* GitHub Actions
* Cloudflare
* PostgreSQL / MySQL
* Redis
* Object Storage (S3 compatible)

---

# Règles du Core

Une fonctionnalité entre dans le Core uniquement si :

* elle est utilisée par au moins deux produits ;
* elle est générique ;
* elle n'est pas liée à un métier spécifique.

Sinon, elle reste dans le produit concerné.

---

# Roadmap

## Phase 1

* Sekuu Core
* Sekuu UI
* Sekuu SDK

---

## Phase 2

* Amélioration de Sekuu Learn
* Développement de Sekuu Suite
* Module Facturation

---

## Phase 3

* CRM
* Stock
* Booking

---

## Phase 4

* Shared Services
* SSO
* Marketplace de modules

---

## Phase 5

Migration progressive des produits existants.

* DealerOS
* Stock Manager
* Tontines

---

# Vision à 5 ans

Créer un écosystème de produits SaaS capables de partager :

* la même identité visuelle ;
* les mêmes composants ;
* les mêmes services ;
* la même authentification ;
* les mêmes standards de qualité.

Chaque produit reste indépendant, peut être commercialisé séparément et évoluer à son propre rythme, tout en bénéficiant de la puissance de **Sekuu Platform**.
