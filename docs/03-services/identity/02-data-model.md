# Sekuu Identity — Architecture technique & Modèle de données

> **Version :** 2.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document fait **autorité** sur le modèle de données de Sekuu Identity.

En cas de divergence avec un autre document, c'est celui-ci qui prévaut.

Documents liés : [Vision & périmètre](01-overview.md) · [API](03-api.md) · [Sécurité](../../02-standards/security.md) · [API Guidelines](../../02-standards/api-guidelines.md)

---

# 1. Architecture technique

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

Identity est la source de vérité ("Single Source of Truth") pour :

* les utilisateurs ;
* les organisations ;
* les workspaces ;
* les rôles et permissions globaux ;
* les sessions et les tokens ;
* les **droits d'accès** aux produits.

Identity n'est **pas** la source de vérité pour les abonnements : c'est Billing (section 8.3).

---

# 2. Conventions

Ces règles s'appliquent à **toutes** les tables décrites ci-dessous.

| Règle | Détail |
| --- | --- |
| Clés primaires | `uuid` v4, jamais d'auto-incrément exposé |
| Nommage | `snake_case`, tables au pluriel — conforme aux [API Guidelines](../../02-standards/api-guidelines.md) |
| Horodatage | `created_at` et `updated_at` (`timestamptz`) sur toutes les tables |
| Suppression | *Soft delete* (`deleted_at`) sur `users`, `organizations`, `workspaces`, `products` ; suppression réelle ailleurs |
| Fuseau | Tout est stocké en **UTC** |
| Clés étrangères | Contrainte explicite systématique, avec `ON DELETE` défini |
| Index | Toute colonne utilisée en filtre ou en jointure est indexée |

Les colonnes `created_at` / `updated_at` ne sont pas répétées dans les blocs qui suivent.

---

# 3. Vue d'ensemble

```text
users ──┬── memberships ── organizations ──┬── workspaces ── workspace_members
        │        │                         │
        │   membership_roles               ├── organization_products ── products
        │        │                         │
        │   global_roles ── role_permissions ── global_permissions
        │                                  │
        ├── sessions ── refresh_tokens     └── invitations
        │
        ├── oauth_accounts
        │
        └── audit_logs
```

---

# 4. Utilisateurs

```text
users

id                  uuid         PK
first_name          varchar(100)
last_name           varchar(100)
email               citext       NOT NULL
phone               varchar(32)  NULL
password_hash       varchar(255) NULL
avatar_url          text         NULL
email_verified_at   timestamptz  NULL
phone_verified_at   timestamptz  NULL
language            varchar(10)  DEFAULT 'fr'
timezone            varchar(64)  DEFAULT 'UTC'
status              varchar(20)  DEFAULT 'active'
last_login_at       timestamptz  NULL
deleted_at          timestamptz  NULL
```

Un utilisateur existe **une seule fois** dans tout l'écosystème.

**Contraintes**

* `UNIQUE (email) WHERE deleted_at IS NULL` — le type `citext` rend la comparaison insensible à la casse.
* `status ∈ { active, pending, suspended, deleted }`.
* `password_hash` est nullable : un compte créé via OAuth peut ne jamais avoir de mot de passe.

**Index** : `email`, `status`, `last_login_at`.

**Note.** Les champs s'appellent `first_name` / `last_name`, et non `firstname` / `lastname` : la règle `snake_case` s'applique aux colonnes comme aux clés JSON.

---

# 5. Organisations

```text
organizations

id             uuid         PK
name           varchar(200) NOT NULL
slug           varchar(100) NOT NULL
country        char(2)      NULL          -- ISO 3166-1 alpha-2
currency       char(3)      NULL          -- ISO 4217
timezone       varchar(64)  DEFAULT 'UTC'
locale         varchar(10)  DEFAULT 'fr'
logo_url       text         NULL
status         varchar(20)  DEFAULT 'active'
deleted_at     timestamptz  NULL
```

Une organisation représente une entreprise.

**Contraintes**

* `UNIQUE (slug) WHERE deleted_at IS NULL`.
* `status ∈ { active, suspended, deleted }`.
* Une organisation doit conserver **au moins un membre actif de rôle `owner`** — vérifié applicativement, erreur `LAST_OWNER_CANNOT_LEAVE`.

**Index** : `slug`, `status`.

---

# 6. Memberships

Un utilisateur peut appartenir à plusieurs organisations. La relation passe par une table intermédiaire.

```text
memberships

id                uuid        PK
user_id           uuid        FK → users(id)          ON DELETE CASCADE
organization_id   uuid        FK → organizations(id)  ON DELETE CASCADE
status            varchar(20) DEFAULT 'active'
invited_by        uuid        FK → users(id)          ON DELETE SET NULL
joined_at         timestamptz NULL
```

**Contraintes**

* `UNIQUE (user_id, organization_id)` — un utilisateur ne peut être membre deux fois de la même organisation.
* `status ∈ { active, pending, suspended }`.

**Index** : `(organization_id, status)`, `user_id`.

## 6.1 Rôles d'un membership

Un membre peut cumuler plusieurs rôles globaux — par exemple `admin` **et** `billing_manager`. La relation est donc portée par une table pivot, et non par une colonne `global_role_id` sur `memberships`.

```text
membership_roles

membership_id    uuid  FK → memberships(id)   ON DELETE CASCADE
global_role_id   uuid  FK → global_roles(id)  ON DELETE RESTRICT

PRIMARY KEY (membership_id, global_role_id)
```

Exemple :

```text
Nathan  →  SOS Clinique  →  owner

Nathan  →  Sekuu SARL    →  member + billing_manager
```

---

# 7. Workspaces

Un workspace est un espace de travail à l'intérieur d'une organisation (une agence, une ville, une filiale).

```text
workspaces

id                uuid         PK
organization_id   uuid         FK → organizations(id) ON DELETE CASCADE
name              varchar(200) NOT NULL
slug              varchar(100) NOT NULL
settings          jsonb        DEFAULT '{}'
status            varchar(20)  DEFAULT 'active'
deleted_at        timestamptz  NULL
```

**Contraintes** : `UNIQUE (organization_id, slug)`.

## 7.1 Membres d'un workspace

L'appartenance à un workspace est **explicite**. Être membre d'une organisation ne donne pas accès à tous ses workspaces.

```text
workspace_members

id             uuid     PK
workspace_id   uuid     FK → workspaces(id)   ON DELETE CASCADE
membership_id  uuid     FK → memberships(id)  ON DELETE CASCADE
is_default     boolean  DEFAULT false
```

**Contraintes**

* `UNIQUE (workspace_id, membership_id)`.
* La référence pointe vers `membership_id`, pas `user_id` : cela garantit par construction qu'on ne peut pas rattacher au workspace d'une organisation un utilisateur qui n'en est pas membre.
* Au plus un `is_default = true` par membership.

**Index** : `membership_id`.

Sans cette table, l'exemple « SOS Clinique → Douala / Yaoundé / Bafoussam » n'est pas implémentable.

---

# 8. Produits et droits d'accès

## 8.1 Catalogue des produits

```text
products

id             uuid         PK
name           varchar(200) NOT NULL
slug           varchar(100) NOT NULL
description    text         NULL
icon_url       text         NULL
status         varchar(20)  DEFAULT 'active'
deleted_at     timestamptz  NULL
```

**Contraintes** : `UNIQUE (slug)`.

Exemples : `dealeros`, `stock`, `clinicflow`, `sekuu-learn`, `tontines`, `immigraflow`.

## 8.2 Produits activés pour une organisation

```text
organization_products

id                 uuid        PK
organization_id    uuid        FK → organizations(id) ON DELETE CASCADE
product_id         uuid        FK → products(id)      ON DELETE RESTRICT
status             varchar(20) DEFAULT 'active'
source             varchar(20) DEFAULT 'subscription'
activated_at       timestamptz NOT NULL
expires_at         timestamptz NULL
subscription_id    uuid        NULL   -- référence logique vers Billing, sans FK
```

Cette table répond à **une seule** question :

> Cette organisation peut-elle utiliser ce produit, aujourd'hui ?

**Contraintes**

* `UNIQUE (organization_id, product_id)`.
* `status ∈ { active, suspended, expired }`.
* `source ∈ { subscription, trial, manual }` — `manual` couvre les activations commerciales hors abonnement.

**Index** : `(organization_id, status)`.

## 8.3 Articulation avec Billing — qui fait foi ?

C'est le point d'ambiguïté le plus coûteux du modèle ; il doit être tranché sans détour :

| Question | Source de vérité |
| --- | --- |
| Quel plan cette organisation paie-t-elle ? | **Billing** (`subscriptions`, `plans`) |
| Cette organisation a-t-elle le droit d'ouvrir ClinicFlow ? | **Identity** (`organization_products`) |

`organization_products` est un **cache de droits d'accès dérivé**, jamais une source de vérité financière. Il est alimenté par les événements de Billing :

```text
SubscriptionActivated   →  activation des produits du plan

SubscriptionUpgraded    →  ajustement des produits

SubscriptionExpired     →  status = 'expired'

SubscriptionCanceled    →  status = 'suspended'
```

En cas de désaccord, **Billing fait foi**. Une commande de réconciliation reconstruit `organization_products` à partir des abonnements.

Le champ `subscription_id` est une référence **logique** : pas de clé étrangère, afin que Billing reste extractible sans contrainte inter-schémas.

> Les tables `plans`, `subscriptions`, `invoices` et `transactions` **n'appartiennent pas à Identity**. Elles sont spécifiées par le module Billing, et Identity n'expose aucune route `/subscriptions` ni `/plans`.

---

# 9. Rôles et permissions

## 9.1 Rôles globaux

```text
global_roles

id            uuid         PK
name          varchar(50)  NOT NULL
slug          varchar(50)  NOT NULL
description   text         NULL
is_system     boolean      DEFAULT true
```

**Contraintes** : `UNIQUE (slug)`.

Rôles fournis par défaut :

```text
owner             Contrôle total, y compris la suppression de l'organisation

admin             Gestion des membres, des workspaces et des produits

billing_manager   Gestion de l'abonnement et de la facturation

member            Accès simple, sans droit d'administration
```

## 9.2 Permissions globales

```text
global_permissions

id            uuid         PK
code          varchar(100) NOT NULL
description   text         NULL
```

**Contraintes** : `UNIQUE (code)`.

Exemples :

```text
organization.manage

organization.delete

subscription.manage

workspace.create

workspace.manage

users.invite

users.remove

roles.assign

products.install

audit.read
```

## 9.3 Relation

```text
role_permissions

global_role_id        uuid  FK → global_roles(id)        ON DELETE CASCADE
global_permission_id  uuid  FK → global_permissions(id)  ON DELETE CASCADE

PRIMARY KEY (global_role_id, global_permission_id)
```

## 9.4 Permissions métier — hors périmètre

Les permissions métier ne sont **pas** stockées dans Identity. Elles restent dans chaque produit.

DealerOS

```text
dealer.create

dealer.edit

dealer.delete
```

ClinicFlow

```text
patient.create

patient.read

patient.update
```

Stock

```text
stock.transfer

stock.inventory

stock.adjust
```

Identity n'a pas besoin de connaître ces permissions — voir [ADR-0003](../../04-decisions/adr-0003-two-level-roles.md).

---

# 10. Sessions

```text
sessions

id              uuid         PK
user_id         uuid         FK → users(id) ON DELETE CASCADE
device_name     varchar(200) NULL
platform        varchar(50)  NULL
browser         varchar(50)  NULL
ip_address      inet         NULL
last_activity   timestamptz  NOT NULL
expires_at      timestamptz  NOT NULL
revoked_at      timestamptz  NULL
```

Permet la liste des appareils connectés, la déconnexion à distance et la détection d'activité anormale.

L'identifiant de session est porté par le claim `sid` du JWT, ce qui permet de révoquer un appareil précis.

**Index** : `(user_id, revoked_at)`, `expires_at`.

---

# 11. Refresh tokens

```text
refresh_tokens

id            uuid        PK
session_id    uuid        FK → sessions(id) ON DELETE CASCADE
user_id       uuid        FK → users(id)    ON DELETE CASCADE
token_hash    char(64)    NOT NULL          -- SHA-256, jamais la valeur en clair
parent_id     uuid        FK → refresh_tokens(id) NULL
expires_at    timestamptz NOT NULL
revoked_at    timestamptz NULL
```

L'access token JWT reste de courte durée (15 min). Le refresh token prolonge la session (30 j).

**Règles**

* La valeur en clair n'est **jamais** stockée : seul son hachage SHA-256 l'est.
* **Rotation à chaque usage** : le token présenté est révoqué et un nouveau est émis, avec `parent_id` pointant vers le précédent.
* **Détection de rejeu** : si un token déjà révoqué est présenté, toute la chaîne (`parent_id`) est révoquée et un événement de sécurité est journalisé.

**Contraintes** : `UNIQUE (token_hash)`. **Index** : `session_id`.

Détails complets dans [security.md](../../02-standards/security.md).

---

# 12. Comptes OAuth

```text
oauth_accounts

id             uuid         PK
user_id        uuid         FK → users(id) ON DELETE CASCADE
provider       varchar(50)  NOT NULL
provider_id    varchar(255) NOT NULL
email          citext       NULL
```

Fournisseurs : Google, Microsoft, GitHub, Apple.

**Contraintes**

* `UNIQUE (provider, provider_id)` — un compte externe ne peut être rattaché qu'à un seul utilisateur (`OAUTH_ACCOUNT_ALREADY_LINKED`).
* `UNIQUE (user_id, provider)` — un utilisateur ne lie qu'un compte par fournisseur.

Aucun jeton d'accès du fournisseur n'est conservé après la connexion.

---

# 13. Invitations

```text
invitations

id                uuid         PK
organization_id   uuid         FK → organizations(id) ON DELETE CASCADE
global_role_id    uuid         FK → global_roles(id)  ON DELETE RESTRICT
email             citext       NOT NULL
token_hash        char(64)     NOT NULL
invited_by        uuid         FK → users(id) ON DELETE SET NULL
expires_at        timestamptz  NOT NULL
accepted_at       timestamptz  NULL
revoked_at        timestamptz  NULL
```

**Le rôle est une clé étrangère**, pas une chaîne libre : une invitation ne peut pas accorder un rôle inexistant.

**Contraintes**

* `UNIQUE (organization_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL` — une seule invitation en attente par adresse et par organisation.
* Le jeton est stocké **haché** (SHA-256), comme les refresh tokens.
* Durée de validité par défaut : **7 jours**.

Flux :

```text
Nathan invite john@gmail.com

        ↓

Identity crée l'invitation et publie InvitationSent

        ↓

Notify envoie l'email

        ↓

John accepte  →  création du compte si nécessaire  →  membership actif
```

---

# 14. Journal d'audit

```text
audit_logs

id                uuid         PK
user_id           uuid         FK → users(id) ON DELETE SET NULL
organization_id   uuid         FK → organizations(id) ON DELETE SET NULL
action            varchar(100) NOT NULL
target_type       varchar(100) NULL
target_id         uuid         NULL
ip_address        inet         NULL
user_agent        text         NULL
request_id        varchar(64)  NULL
payload           jsonb        DEFAULT '{}'
created_at        timestamptz  NOT NULL
```

Actions journalisées : connexion, échec de connexion, déconnexion, changement de mot de passe, création et suppression d'organisation, invitation, changement de rôle, révocation de session, activation de produit.

**Règles**

* Table **append-only** : ni `UPDATE`, ni `DELETE`, ni `updated_at`.
* Le `payload` ne contient jamais de mot de passe, de token ni de secret.
* Rétention **24 mois**. À la suppression d'un compte, les lignes sont anonymisées (`user_id → NULL`) et non supprimées.
* Collection volumineuse : l'API l'expose en **pagination par curseur**.

**Index** : `(organization_id, created_at DESC)`, `(user_id, created_at DESC)`, `action`.

---

# 15. Notifications déclenchées

Identity **n'envoie aucun message**. Il publie des événements que Notify consomme.

| Événement Identity | Message envoyé par Notify |
| --- | --- |
| `UserRegistered` | Bienvenue + lien de vérification d'email |
| `PasswordResetRequested` | Lien de réinitialisation |
| `PasswordChanged` | Confirmation de changement |
| `InvitationSent` | Invitation à rejoindre l'organisation |
| `OrganizationCreated` | Confirmation de création |
| `NewDeviceLogin` | Alerte de connexion depuis un nouvel appareil |
| `MembershipRemoved` | Notification de retrait |

Identity ne contient donc ni configuration SMTP, ni compte SMS, ni template de message.

---

# 16. Pourquoi cette architecture ?

Chaque produit reste totalement autonome.

Identity connaît uniquement :

* qui est connecté ;
* dans quelle organisation et quel workspace ;
* quels produits sont accessibles ;
* quels sont les rôles globaux.

Le produit décide ensuite :

* quelles permissions métier appliquer ;
* quelles données charger ;
* quelles actions autoriser.

Cela permet :

* d'ajouter un nouveau SaaS sans modifier Identity ;
* de changer la technologie d'un produit (Laravel, NestJS, Supabase…) ;
* de vendre un produit séparément ;
* de faire évoluer chaque produit indépendamment.

---

# 17. Évolutions futures

Le modèle est conçu pour accueillir progressivement :

* Authentification multifacteur (MFA/2FA) — tables `mfa_methods`, `mfa_challenges`.
* Passkeys (WebAuthn) — table `webauthn_credentials`.
* API Keys pour les intégrations — table `api_keys`.
* SCIM (provisionnement d'utilisateurs pour les entreprises).
* Synchronisation avec Microsoft Entra ID / Google Workspace.
* Politiques de sécurité par organisation — table `organization_security_policies`.
* Marketplace de produits Sekuu.

Aucune de ces évolutions ne nécessite de rupture du modèle actuel.
