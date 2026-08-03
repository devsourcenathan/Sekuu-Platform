# Sekuu Identity — Partie 2

# Architecture technique & Modèle de données

> Version : 1.0

---

# 15. Architecture technique

Sekuu Identity est un **Identity Provider (IdP)**.

Tous les produits Sekuu lui délèguent l'authentification et une partie de la gestion des accès.

```text
                        Internet
                            │
                    identity.sekuu.com
                            │
      ┌─────────────────────┼─────────────────────┐
      │                     │                     │
  DealerOS             ClinicFlow          Sekuu Learn
      │                     │                     │
      └────────────── API HTTPS ──────────────────┘
```

Identity devient la source de vérité ("Single Source of Truth") pour :

* les utilisateurs ;
* les organisations ;
* les workspaces ;
* les abonnements ;
* les produits disponibles ;
* les rôles globaux.

---

# 16. Architecture de la base de données

Je recommande PostgreSQL.

La base peut être organisée en plusieurs domaines fonctionnels.

```text
Users

Organizations

Memberships

Products

Subscriptions

Security

Audit

OAuth
```

---

# 17. Utilisateurs

```text
users

id (uuid)

firstname

lastname

email

phone

password

avatar

email_verified_at

language

timezone

status

last_login_at

created_at

updated_at
```

Un utilisateur existe une seule fois dans tout l'écosystème.

---

# 18. Organisations

```text
organizations

id (uuid)

name

slug

country

currency

timezone

locale

logo

status

created_at

updated_at
```

Une organisation représente une entreprise.

---

# 19. Memberships

Au lieu de relier directement un utilisateur à une organisation, on utilise une table intermédiaire.

```text
memberships

id

user_id

organization_id

global_role_id

status

joined_at

created_at
```

Pourquoi ?

Parce qu'un utilisateur peut appartenir à plusieurs entreprises.

Exemple :

Nathan

↓

SOS Clinique

↓

Owner

Puis :

Nathan

↓

Sekuu SARL

↓

Member

---

# 20. Workspaces

```text
workspaces

id

organization_id

name

slug

status
```

Une organisation peut posséder plusieurs espaces de travail.

---

# 21. Produits

Tous les produits connus par Sekuu.

```text
products

id

name

slug

description

status

icon

version
```

Exemple :

```text
DealerOS

Stock

ClinicFlow

Sekuu Learn

Tontines

ImmigraFlow
```

---

# 22. Produits activés

```text
organization_products

id

organization_id

product_id

status

activated_at

expires_at
```

Cette table indique simplement :

Cette entreprise peut-elle utiliser ce produit ?

---

# 23. Abonnements

```text
subscriptions

id

organization_id

plan_id

status

starts_at

ends_at
```

---

Plans

```text
plans

id

name

price

currency

billing_cycle

features
```

Le plan est attaché à l'organisation, pas à un utilisateur.

---

# 24. Gestion des rôles

## Rôles globaux

```text
global_roles

id

name

description
```

Exemples :

```text
Owner

Admin

Member

Billing Manager
```

---

Permissions globales

```text
global_permissions

id

code

description
```

Exemple :

```text
organization.manage

subscription.manage

workspace.create

users.invite

products.install
```

---

Relation

```text
role_permissions

role_id

permission_id
```

---

# 25. Produits et permissions métier

Les permissions métier ne sont PAS stockées dans Identity.

Elles restent dans chaque produit.

DealerOS :

```text
dealer.create

dealer.edit

dealer.delete
```

ClinicFlow :

```text
patient.create

patient.read

patient.update
```

Stock :

```text
stock.transfer

stock.inventory

stock.adjust
```

Identity n'a pas besoin de connaître ces permissions.

---

# 26. Sessions

```text
sessions

id

user_id

device

browser

ip

last_activity

expires_at
```

Permet :

* déconnexion d'un appareil ;
* liste des appareils connectés ;
* sécurité.

---

# 27. Refresh Tokens

```text
refresh_tokens

id

user_id

token

expires_at

revoked_at
```

Le token JWT reste de courte durée.

Le Refresh Token permet de prolonger la session.

---

# 28. OAuth Providers

```text
oauth_accounts

id

user_id

provider

provider_id

email
```

Exemple :

Google

Microsoft

GitHub

Apple

---

# 29. Invitations

```text
invitations

id

organization_id

email

role

token

expires_at

accepted_at
```

Exemple :

Nathan invite :

[john@gmail.com](mailto:john@gmail.com)

↓

Identity envoie l'invitation.

↓

John rejoint automatiquement l'organisation.

---

# 30. Audit Logs

```text
audit_logs

id

user_id

organization_id

action

ip

user_agent

payload

created_at
```

Exemples :

Connexion

Création organisation

Invitation utilisateur

Suppression workspace

---

# 31. Notifications

Identity doit pouvoir envoyer :

* email
* SMS
* notifications internes

Exemples :

Bienvenue

Mot de passe oublié

Invitation

Organisation créée

---

# 32. API

Endpoints principaux

```text
POST /auth/login

POST /auth/logout

POST /auth/refresh

GET /auth/me

POST /auth/register

POST /auth/forgot-password

POST /auth/reset-password
```

---

Utilisateurs

```text
GET /users

GET /users/{id}

POST /users

PATCH /users/{id}
```

---

Organisations

```text
GET /organizations

POST /organizations

PATCH /organizations/{id}

DELETE /organizations/{id}
```

---

Invitations

```text
POST /organizations/{id}/invite

POST /invitations/accept
```

---

Produits

```text
GET /products

POST /products

GET /organization-products
```

---

Abonnements

```text
GET /subscriptions

POST /subscriptions

PATCH /subscriptions
```

---

# 33. Pourquoi cette architecture ?

Chaque produit reste totalement autonome.

Identity connaît uniquement :

* qui est connecté ;
* dans quelle organisation ;
* quels produits sont disponibles ;
* quel est le rôle global.

Le produit décide ensuite :

* quelles permissions métier appliquer ;
* quelles données charger ;
* quelles actions autoriser.

Cela permet :

* d'ajouter un nouveau SaaS sans modifier Identity ;
* de changer la technologie d'un produit (Laravel, NestJS, Supabase...) ;
* de vendre un produit séparément si besoin ;
* de faire évoluer chaque produit indépendamment.

---

# Évolutions futures

Le modèle est conçu pour accueillir progressivement :

* Authentification multifacteur (MFA/2FA).
* Passkeys (WebAuthn).
* Gestion des équipes avancée.
* API Keys pour les intégrations.
* SCIM (provisionnement d'utilisateurs pour les entreprises).
* Synchronisation avec Microsoft Entra ID / Google Workspace.
* Marketplace de produits Sekuu.
* Journal de conformité et politiques de sécurité.
