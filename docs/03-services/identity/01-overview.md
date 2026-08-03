# Sekuu Identity — Spécifications Techniques

> **Version :** 1.0
> **Statut :** Draft Architecture
> **Projet :** Sekuu Ecosystem
> **Composant :** Sekuu Identity Service

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
* utiliser un abonnement commun.

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
* Les workspaces.
* Les invitations.
* Les sessions.
* Les authentifications.
* Les accès aux produits.
* Les rôles globaux.
* Les permissions plateforme.
* Les abonnements.
* Les connexions externes.

---

## 3.2 Objectifs techniques

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

Données :

```
User

id
email
password
firstname
lastname
phone
avatar
language
timezone
created_at
updated_at
```

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
GET /identity/me

      |

Sekuu Identity

      |

Utilisateur connecté
```

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

JWT

Refresh Tokens
```

---

# 14. Hors périmètre

Sekuu Identity ne doit pas gérer :

* patients ;
* produits commerciaux ;
* factures métier ;
* stocks ;
* cours ;
* commandes ;
* rendez-vous.

Ces données appartiennent aux applications concernées.

---

# Résumé

Sekuu Identity est le fournisseur d'identité de tout l'écosystème.

Il fournit :

✓ Une identité unique
✓ Un système SSO
✓ Les organisations
✓ Les accès produits
✓ Les rôles plateforme
✓ Les abonnements

Mais chaque produit reste responsable de :

✓ Sa base métier
✓ Ses données
✓ Ses rôles métier
✓ Ses règles métier
