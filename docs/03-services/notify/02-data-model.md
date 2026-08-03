# Sekuu Notify — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document fait **autorité** sur le modèle de données de Sekuu Notify.

Documents liés : [Vision & périmètre](01-overview.md) · [API](03-api.md) · [Événements](04-events.md) · [API Guidelines](../../02-standards/api-guidelines.md)

---

# 1. Conventions

Identiques à celles d'Identity — voir [Identity § 2](../identity/02-data-model.md).

En résumé : UUID v4 en clé primaire, `snake_case`, `timestamptz` en UTC, contrainte de clé étrangère explicite, index sur toute colonne filtrée.

Les colonnes `created_at` / `updated_at` ne sont pas répétées dans les blocs qui suivent.

---

# 2. Vue d'ensemble

```text
notification_templates ── notification_template_contents
          │
          │
   notifications ──┬── notification_deliveries ── notification_events
                   │
                   └── (organization_id, référence logique vers Identity)

notification_preferences        suppressions
```

Notify ne possède **aucune** table d'utilisateurs ni d'organisations. Il stocke des identifiants et des adresses, jamais des profils.

---

# 3. Templates

## 3.1 Définition

```text
notification_templates

id                uuid         PK
key               varchar(100) NOT NULL   -- ex. invitation.sent
channel           varchar(20)  NOT NULL   -- email | sms | whatsapp | push | in_app
category          varchar(20)  NOT NULL   -- transactional | operational | marketing
organization_id   uuid         NULL       -- NULL = template de plateforme
provider_ref      varchar(255) NULL       -- identifiant du template chez le fournisseur
variables         jsonb        DEFAULT '[]'
status            varchar(20)  DEFAULT 'active'
version           integer      DEFAULT 1
```

**Contraintes**

* `UNIQUE (key, channel, organization_id)` — un template par clé, canal et organisation.

**Une clé peut exister sur plusieurs canaux.** `password.changed` est défini en email **et** en SMS : le message part alors par les deux, si l'on dispose des deux coordonnées. C'est une diffusion multi-canal, pas un choix.

L'appelant fournit les coordonnées dont il dispose ; un canal sans coordonnée est simplement ignoré, ce n'est pas une erreur. Un canal bloqué — préférence ou suppression — n'empêche pas les autres de partir. L'appel n'échoue que si **rien** n'a pu partir.
* `category ∈ { transactional, operational, marketing }`.
* `channel ∈ { email, sms, whatsapp, push, in_app }`.

**Personnalisation par organisation.** Un `organization_id` non nul remplace le template de plateforme portant la même clé et le même canal. La résolution cherche d'abord le template de l'organisation, puis retombe sur celui de la plateforme. C'est ce qui permettra à une entreprise d'habiller ses invitations sans dupliquer le catalogue.

**`provider_ref`** existe pour WhatsApp : Meta impose des templates pré-approuvés, identifiés côté fournisseur. Le contenu local sert alors à la journalisation et à la prévisualisation, pas à l'envoi.

**`variables`** liste les variables attendues, avec leur caractère obligatoire. C'est ce qui permet de rejeter un envoi incomplet **avant** la mise en file, plutôt que de produire un message trafiqué.

```json
[
  { "name": "organization_name", "required": true },
  { "name": "inviter_name", "required": false }
]
```

## 3.2 Contenus traduits

```text
notification_template_contents

id            uuid         PK
template_id   uuid         FK → notification_templates(id) ON DELETE CASCADE
locale        varchar(10)  NOT NULL
subject       text         NULL       -- email uniquement
body          text         NOT NULL
```

**Contraintes** : `UNIQUE (template_id, locale)`.

La résolution de langue suit cet ordre : langue demandée → langue de l'utilisateur → locale de l'organisation → `APP_FALLBACK_LOCALE`. Un template sans aucun contenu utilisable est une erreur de configuration, pas un cas d'envoi dégradé : il produit `TEMPLATE_NOT_FOUND`.

---

# 4. Notifications

Une ligne = une intention d'envoi acceptée.

```text
notifications

id                uuid         PK
organization_id   uuid         NULL       -- référence logique vers Identity, sans FK
user_id           uuid         NULL       -- destinataire connu, si applicable
template_id       uuid         FK → notification_templates(id) ON DELETE RESTRICT
template_key      varchar(100) NOT NULL   -- copie, pour survivre à la suppression du template
channel           varchar(20)  NOT NULL
category          varchar(20)  NOT NULL
locale            varchar(10)  NOT NULL
recipient         varchar(320) NOT NULL   -- adresse, numéro, ou jeton d'appareil
rendered_subject  text         NULL
rendered_body     text         NOT NULL
payload           jsonb        DEFAULT '{}'
status            varchar(20)  DEFAULT 'queued'
idempotency_key   varchar(255) NULL
source_event_id   varchar(100) NULL       -- identifiant de l'événement déclencheur
request_id        varchar(64)  NULL
scheduled_for     timestamptz  NULL
failed_reason     varchar(100) NULL
```

**Contraintes**

* `UNIQUE (idempotency_key) WHERE idempotency_key IS NOT NULL` — index partiel.
* `status ∈ { queued, sending, sent, delivered, failed, suppressed, cancelled }`.

**Index** : `(organization_id, created_at DESC)`, `(status, scheduled_for)`, `(user_id, created_at DESC)`, `template_key`.

## 4.1 Pourquoi le contenu rendu est stocké

`rendered_subject` et `rendered_body` figent le message au moment de l'acceptation.

Sans cela, un template corrigé pendant qu'un message attend en file changerait le contenu réellement envoyé, et le journal ne correspondrait plus à ce que le destinataire a reçu. Pour un message transactionnel — un lien de réinitialisation, une facture — c'est inacceptable.

C'est aussi ce qui permet de rejouer un envoi à l'identique après un incident fournisseur.

## 4.2 Cycle de vie

```text
queued  ──►  sending  ──►  sent  ──►  delivered
   │            │                        │
   │            └──► failed              └──► bounced / complained
   │                                          (via notification_events)
   └──►  suppressed        (destinataire filtré, aucun envoi tenté)
   └──►  cancelled         (annulé avant envoi)
```

`sent` signifie « accepté par le fournisseur ». `delivered` signifie « remis au destinataire ». Confondre les deux revient à croire son taux de délivrabilité meilleur qu'il n'est.

## 4.3 `payload`

Les variables fournies par l'appelant. Elles subissent le **même filtrage récursif des secrets** que le journal d'audit d'Identity : aucun mot de passe, jeton ou secret n'y est conservé.

C'est une contrainte forte et volontaire : les liens de réinitialisation et d'invitation contiennent un jeton. Il apparaît dans `rendered_body` — c'est inévitable, c'est le message — mais jamais dans `payload`, qui est la partie exposée par l'API de consultation.

---

# 5. Livraisons

Une notification peut donner lieu à plusieurs tentatives, éventuellement chez des fournisseurs différents.

```text
notification_deliveries

id                   uuid         PK
notification_id      uuid         FK → notifications(id) ON DELETE CASCADE
provider             varchar(50)  NOT NULL
attempt              integer      NOT NULL DEFAULT 1
status               varchar(20)  NOT NULL
provider_message_id  varchar(255) NULL
error_code           varchar(100) NULL
error_message        text         NULL
cost_amount          numeric(12,4) NULL
cost_currency        char(3)      NULL
sent_at              timestamptz  NULL
```

**Contraintes** : `UNIQUE (notification_id, attempt)`, `status ∈ { pending, accepted, rejected, failed }`.

**Index** : `provider_message_id` — c'est la clé de rapprochement des webhooks entrants.

`cost_amount` est renseigné lorsque le fournisseur le communique. Le coût unitaire du SMS le rend nécessaire dès le premier jour : sans lui, aucune refacturation ni aucun plafond par organisation n'est possible.

---

# 6. Événements de livraison

Ce que les fournisseurs rapportent après coup.

```text
notification_events

id                uuid         PK
notification_id   uuid         FK → notifications(id) ON DELETE CASCADE
delivery_id       uuid         FK → notification_deliveries(id) ON DELETE SET NULL
type              varchar(30)  NOT NULL
provider          varchar(50)  NOT NULL
provider_event_id varchar(255) NULL
payload           jsonb        DEFAULT '{}'
occurred_at       timestamptz  NOT NULL
created_at        timestamptz  NOT NULL
```

**Contraintes**

* `type ∈ { delivered, bounced, complained, rejected, opened, clicked, unsubscribed }`.
* `UNIQUE (provider, provider_event_id) WHERE provider_event_id IS NOT NULL` — les fournisseurs rejouent leurs webhooks ; la déduplication est structurelle.

Table **append-only**, comme le journal d'audit : ni `UPDATE`, ni `DELETE`, ni `updated_at`.

`opened` et `clicked` ne sont collectés que pour les catégories `operational` et `marketing`. Pister l'ouverture d'un message transactionnel n'apporte rien et pose un problème de proportionnalité.

---

# 7. Préférences

```text
notification_preferences

id                uuid         PK
user_id           uuid         NOT NULL   -- référence logique vers Identity
organization_id   uuid         NULL       -- NULL = préférence globale de l'utilisateur
category          varchar(20)  NOT NULL
channel           varchar(20)  NOT NULL
enabled           boolean      NOT NULL
```

**Contraintes** : `UNIQUE (user_id, organization_id, category, channel)`.

**Résolution** : préférence pour l'organisation → préférence globale → défaut de la catégorie.

Défauts : `transactional` toujours actif et **non modifiable**, `operational` actif, `marketing` inactif.

Une préférence enregistrée sur une catégorie transactionnelle est refusée (`422`) plutôt qu'ignorée silencieusement : accepter puis ne pas appliquer serait un mensonge à l'utilisateur.

---

# 8. Liste de suppression

La table la plus souvent oubliée, et celle qui protège la capacité même à envoyer.

```text
suppressions

id            uuid         PK
channel       varchar(20)  NOT NULL
destination   varchar(320) NOT NULL   -- adresse email ou numéro, normalisé
reason        varchar(30)  NOT NULL
source        varchar(50)  NULL       -- fournisseur ayant signalé
notification_id uuid       NULL       -- FK → notifications(id) ON DELETE SET NULL
expires_at    timestamptz  NULL       -- NULL = permanent
```

**Contraintes**

* `UNIQUE (channel, destination) WHERE expires_at IS NULL` — une suppression permanente par destination et par canal.
* `reason ∈ { hard_bounce, complaint, unsubscribe, manual, invalid }`.

**Règles**

| Motif | Durée | Origine |
| --- | --- | --- |
| `hard_bounce` | Permanente | Webhook fournisseur |
| `complaint` | Permanente | Signalement « spam » |
| `unsubscribe` | Permanente | Lien de désabonnement |
| `invalid` | Permanente | Adresse ou numéro syntaxiquement invalide |
| `manual` | Selon `expires_at` | Intervention humaine |

Un rebond **temporaire** (`soft bounce` : boîte pleine, serveur indisponible) ne crée pas de suppression : il déclenche un réessai.

La suppression s'applique à **toutes** les catégories, y compris transactionnelles. Une adresse qui rebondit durablement n'est plus une adresse ; continuer à écrire dégrade la réputation du domaine expéditeur et finit par empêcher l'acheminement des messages légitimes vers les autres destinataires.

Retirer une entrée de la liste est une action administrative, journalisée.

---

# 9. Ce que Notify ne stocke pas

| Donnée | Où elle vit |
| --- | --- |
| Utilisateurs, organisations, memberships | **Identity** |
| Pièces jointes | **Storage** (Notify ne stocke que des références) |
| Identifiants de fournisseurs | Gestionnaire de secrets |
| Jetons d'action (réinitialisation, invitation) | **Identity** — Notify les reçoit en variable, ne les conserve pas hors du corps rendu |

---

# 10. Rétention

| Table | Durée | Justification |
| --- | --- | --- |
| `notifications` | 12 mois | Support et réconciliation |
| `notification_deliveries` | 12 mois | Suit la notification |
| `notification_events` | 12 mois | Suit la notification |
| `suppressions` | **Illimitée** | Une adresse qui rebondit ne redevient pas valide avec le temps |

La purge des notifications conserve un agrégat par jour, canal et statut : les statistiques historiques survivent à la suppression des messages.

---

# 11. Évolutions futures

* Envois groupés (`campaigns`) pour la catégorie marketing.
* Fenêtres horaires de remise, par fuseau du destinataire.
* Agrégation (« 5 nouvelles invitations » plutôt que 5 messages).
* Prévisualisation et test d'un template avant publication.
* Signature DKIM par organisation, pour les domaines expéditeurs personnalisés.
