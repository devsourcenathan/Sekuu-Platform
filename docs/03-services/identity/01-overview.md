# Sekuu Identity — Vision & Périmètre

> **Version :** 1.1
> **Statut :** Spécification de référence
> **Projet :** Sekuu Ecosystem
> **Composant :** Sekuu Identity Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu Identity.

* Le modèle de données fait autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md).
* L'authentification et les tokens sont définis dans [security.md](../../02-standards/security.md).

---

# 1. Introduction

## 1.1 Contexte

Sekuu est conçu comme un écosystème de produits SaaS indépendants partageant une infrastructure commune.

Les produits peuvent évoluer avec des technologies différentes :

* Laravel
* NestJS
* Supabase
* React
* Next.js
* React Native

Afin d'éviter la duplication des systèmes d'authentification et de gestion des utilisateurs, Sekuu Identity devient le service central responsable de l'identité numérique des utilisateurs et des organisations.

---

# 2. Vision

Sekuu Identity est le système central permettant à un utilisateur de posséder une identité unique dans tout l'écosystème Sekuu.

Un utilisateur doit pouvoir :

* créer un compte une seule fois ;
* accéder à plusieurs produits ;
* utiliser les mêmes informations personnelles ;
* gérer ses organisations ;
* recevoir des invitations ;
* bénéficier des produits couverts par l'abonnement de son organisation.

Exemple :

```
Nathan

        Sekuu Identity

             |
   -------------------------
   |           |           |
Sekuu Learn  DealerOS  ClinicFlow
```

Nathan possède une seule identité mais plusieurs contextes d'utilisation.

---

# 3. Objectifs

## 3.1 Objectifs fonctionnels

Sekuu Identity doit gérer :

* Les utilisateurs.
* Les organisations.
* Les workspaces et leurs membres.
* Les invitations.
* Les sessions.
* Les authentifications.
* Les accès aux produits (`organization_products`).
* Les rôles globaux.
* Les permissions plateforme.
* Les connexions externes (OAuth).

---

## 3.2 Ce qu'Identity ne gère pas

| Domaine | Responsable | Rôle d'Identity |
| --- | --- | --- |
| Plans, abonnements, paiements, factures | **Billing** | Identity **consomme** l'information et en dérive l'accès aux produits |
| Envoi d'emails, SMS, push | **Notify** | Identity **déclenche** des notifications, il n'en envoie aucune |
| Vérification d'identité (KYC/KYB) | **Verify** | Identity référence le statut de vérification |
| Permissions métier | **Chaque produit** | Identity ne les connaît pas |

Cette frontière est le point le plus important de la spécification. Elle est détaillée en section 14.

---

## 3.3 Objectifs techniques

Le service doit être :

* indépendant des produits ;
* consommable par plusieurs technologies ;
* sécurisé ;
* scalable ;
* compatible API-first ;
* compatible Web et Mobile.

---

# 4. Principes d'architecture

## 4.1 Séparation des responsabilités

Sekuu Identity ne gère PAS la logique métier des produits.

Exemple :

Identity connaît :

```
Nathan possède un compte.
Nathan appartient à SOS Clinique.
SOS Clinique utilise ClinicFlow.
```

Mais Identity ne connaît pas :

```
Nathan est médecin.
Nathan peut modifier un patient.
Nathan peut valider une ordonnance.
```

Ces règles appartiennent à ClinicFlow.

---

# 5. Architecture globale

```
                         Sekuu Identity

                              |
        ------------------------------------------------
        |                    |                         |
    Sekuu Learn          DealerOS                ClinicFlow
        |                    |                         |
    DB Learn            DB Dealer              DB Clinic
```

Chaque produit possède :

* sa base de données ;
* sa logique métier ;
* ses permissions métier.

---

# 6. Les responsabilités de Sekuu Identity

## 6.1 Gestion des utilisateurs

Identity est responsable de :

* création utilisateur ;
* modification profil ;
* suppression compte ;
* vérification email ;
* récupération mot de passe ;
* sessions.

Données principales :

```
User

id
email
first_name
last_name
phone
avatar
language
timezone
status
```

> Ce bloc est indicatif. Le modèle complet et faisant autorité — colonnes, types, contraintes et index — se trouve dans [02-data-model.md](02-data-model.md).

---

# 7. Gestion des organisations

Sekuu est pensé pour des applications professionnelles.

Un utilisateur peut appartenir à plusieurs organisations.

Exemple :

```
Nathan

|
|-- Sekuu SARL
|
|-- SOS Clinique
|
|-- Cabinet Immigration
```

---

Modèle :

```
Organization

id
name
slug
country
currency
timezone
created_at
```

---

Relation :

```
User

   belongs to many

Organization
```

---

# 8. Workspace

Un workspace représente un espace de travail dans une organisation.

Exemple :

```
Organisation :

SOS Clinique


Workspaces :

- Douala
- Yaoundé
- Bafoussam
```

Modèle :

```
Workspace

id
organization_id
name
slug
settings
```

L'appartenance à un workspace est **explicite** : être membre d'une organisation ne donne pas automatiquement accès à tous ses workspaces.

```
workspace_members

id
workspace_id
user_id
```

Exemple : un médecin rattaché au workspace Douala ne voit pas les données du workspace Yaoundé, bien qu'il soit membre de la même organisation.

---

# 9. Accès aux produits

Identity doit savoir quels produits sont disponibles pour une organisation.

Exemple :

```
SOS Clinique

Produits actifs :

✓ ClinicFlow

✓ Sekuu Learn

✗ DealerOS
```

---

Modèle :

```
organization_products

id

organization_id

product_id

status

activated_at
```

---

# 10. Gestion des rôles — Architecture hybride

Sekuu utilise une séparation en deux niveaux.

## Niveau 1 : Rôles plateforme

Gérés par Identity.

Ils existent pour tous les produits.

Exemples :

```
Owner

Admin

Member

Billing Manager
```

Ils contrôlent :

* gestion organisation ;
* utilisateurs ;
* abonnement ;
* paramètres globaux.

---

## Niveau 2 : Rôles métier

Gérés par chaque produit.

Exemple :

ClinicFlow :

```
Doctor

Nurse

Receptionist
```

DealerOS :

```
Sales Agent

Dealer Manager

Warehouse Manager
```

Stock :

```
Stock Manager

Inventory Agent
```

---

# 11. Pourquoi cette séparation ?

Parce qu'un même utilisateur peut avoir plusieurs rôles.

Exemple :

```
Nathan

Sekuu Identity :

Owner


ClinicFlow :

Doctor


DealerOS :

Administrator


Sekuu Learn :

Instructor
```

Le rôle dépend donc du contexte.

---

# 12. Communication avec les produits

Les produits communiquent avec Identity via API.

Exemple :

```
ClinicFlow

      |
      |
GET https://identity.sekuu.com/api/v1/auth/me

      |

Sekuu Identity

      |

Utilisateur connecté
```

Toutes les routes sont versionnées. L'inventaire complet des endpoints se trouve dans [03-api.md](03-api.md).

---

# 13. Technologies recommandées

Backend :

```
Laravel
```

Pourquoi :

* mature ;
* excellent support OAuth ;
* facile à maintenir ;
* cohérent avec l'écosystème Sekuu.

---

Base de données :

```
PostgreSQL
```

Pour :

* robustesse ;
* JSON support ;
* scalabilité.

---

Authentification :

```
OAuth 2.0

JWT (RS256)

Refresh Tokens
```

Le format des tokens, leurs claims, leur durée de vie, la rotation des clés et la révocation sont spécifiés dans [security.md](../../02-standards/security.md).

---

# 14. Hors périmètre

## 14.1 Données métier des produits

Sekuu Identity ne doit pas gérer :

* patients ;
* produits commerciaux ;
* factures métier ;
* stocks ;
* cours ;
* commandes ;
* rendez-vous.

Ces données appartiennent aux applications concernées.

## 14.2 Abonnements et facturation → Billing

Identity ne possède ni les plans, ni les abonnements, ni les paiements, ni les factures. Ces tables appartiennent au module **Billing**.

Identity possède uniquement `organization_products`, qui répond à une seule question :

> Cette organisation peut-elle utiliser ce produit, aujourd'hui ?

Cette table est un **cache de droits d'accès**, alimenté par les événements émis par Billing :

```text
Billing   publie  SubscriptionActivated  →  Identity  active   les produits du plan
Billing   publie  SubscriptionExpired    →  Identity  suspend  les produits du plan
Billing   publie  SubscriptionUpgraded   →  Identity  ajuste   les produits du plan
```

En cas de désaccord entre les deux, **Billing fait foi**. Une commande de réconciliation permet de reconstruire `organization_products` à partir des abonnements.

Conséquence : Identity n'expose **aucun** endpoint `/subscriptions` ni `/plans`. Ces routes vivent sur `billing.sekuu.com`.

## 14.3 Envoi de messages → Notify

Identity doit provoquer l'envoi de plusieurs messages :

* bienvenue après inscription ;
* vérification d'adresse email ;
* mot de passe oublié ;
* invitation à rejoindre une organisation ;
* alerte de connexion depuis un nouvel appareil.

Dans tous les cas, Identity **publie un événement** ; c'est Notify qui possède les templates, les canaux, les files d'attente et les fournisseurs.

Identity ne contient aucune configuration SMTP, aucun compte SMS, aucun template de message.

---

# Résumé

Sekuu Identity est le fournisseur d'identité de tout l'écosystème.

Il fournit :

✓ Une identité unique
✓ Un système SSO
✓ Les organisations et les workspaces
✓ Les droits d'accès aux produits
✓ Les rôles et permissions plateforme
✓ Les sessions et les tokens

Mais il ne gère pas :

✗ Les abonnements et la facturation (Billing)
✗ L'envoi des notifications (Notify)
✗ La vérification d'identité (Verify)

Et chaque produit reste responsable de :

✓ Sa base métier
✓ Ses données
✓ Ses rôles métier
✓ Ses règles métier
