# Sekuu Notify — Contrat d'événements

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Les modules de la plateforme ne s'appellent pas : ils publient des événements. Ce document liste ceux que Notify consomme, et le template que chacun déclenche.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [Architecture § 11](../../01-overview/architecture.md)

---

# 1. Principe

```text
Identity  ──publie──►  UserRegistered  ──consommé par──►  Notify  ──►  email
```

Identity ne connaît ni template, ni canal, ni fournisseur. Il déclare un fait : *un utilisateur vient de s'inscrire*. Notify décide de ce qui part.

Conséquence directe : **ajouter un message ne modifie pas le module émetteur.** Envoyer désormais un SMS de bienvenue en plus de l'email est une décision de configuration côté Notify.

## 1.1 Format

```json
{
  "id": "evt_9f1c2b7a",
  "type": "identity.user.registered",
  "occurred_at": "2026-08-03T13:42:51Z",
  "request_id": "req_8b94d7d0",
  "organization_id": null,
  "data": {
    "user_id": "…",
    "email": "nathan@sekuu.com",
    "first_name": "Nathan",
    "locale": "fr",
    "verification_url": "https://app.sekuu.com/verify-email?token=…"
  }
}
```

Le `type` suit `{module}.{ressource}.{action}`. Le `request_id` est propagé : une notification reste rattachable à la requête HTTP qui l'a déclenchée, à travers deux modules.

## 1.2 Livraison au moins une fois

Un consommateur **doit** être idempotent : le même événement peut être livré plusieurs fois.

Notify utilise `id` comme clé d'idempotence de l'envoi. Rejouer un événement ne produit donc jamais un second message — c'est ce que garantit l'index unique sur `notifications.idempotency_key`.

## 1.3 Ce qui ne doit jamais transiter

| Interdit dans `data` | Pourquoi |
| --- | --- |
| Mot de passe, même haché | Aucun usage légitime |
| Jeton brut de session ou refresh token | Un événement est journalisé et rejoué |
| Secret de fournisseur | — |

Les jetons d'action (réinitialisation, invitation, vérification) font exception : ils sont **nécessaires** au message, puisqu'ils en constituent le lien. Ils transitent donc dans une URL complète, et Notify ne les conserve que dans le corps rendu — jamais dans `payload`, qui est la partie exposée par l'API de consultation.

---

# 2. Événements émis par Identity

Ces huit événements existent déjà côté Identity, sous forme d'actions journalisées. Ils sont la raison d'être immédiate de Notify.

| Événement | Template | Canal | Catégorie |
| --- | --- | --- | --- |
| `identity.user.registered` | `user.welcome` | email | transactional |
| `identity.email.verification_requested` | `email.verification` | email | transactional |
| `identity.password.reset_requested` | `password.reset` | email | transactional |
| `identity.password.changed` | `password.changed` | email | transactional |
| `identity.invitation.sent` | `invitation.sent` | email | operational |
| `identity.organization.created` | `organization.created` | email | operational |
| `identity.session.new_device` | `security.new_device` | email | transactional |
| `identity.membership.removed` | `membership.removed` | email | operational |

## 2.1 Variables attendues

`user.welcome`

```text
first_name        obligatoire
verification_url  obligatoire
```

`email.verification`

```text
first_name        obligatoire
verification_url  obligatoire
expires_in_hours  obligatoire
```

`password.reset`

```text
first_name        obligatoire
reset_url         obligatoire
expires_in_hours  obligatoire
```

`password.changed`

```text
first_name        obligatoire
changed_at        obligatoire
ip_address        facultatif
```

`invitation.sent`

```text
organization_name obligatoire
inviter_name      facultatif
role              obligatoire
accept_url        obligatoire
expires_at        obligatoire
```

`security.new_device`

```text
first_name        obligatoire
device_name       facultatif
ip_address        facultatif
occurred_at       obligatoire
```

## 2.2 Pourquoi `password.changed` et `security.new_device` sont transactionnels

Ce sont des **alertes de sécurité**. Un utilisateur qui reçoit « votre mot de passe a été changé » alors qu'il n'a rien fait vient d'apprendre que son compte est compromis. Rendre ce message désactivable reviendrait à offrir à un attaquant le moyen de faire le silence après une prise de contrôle.

---

# 3. Événements de Billing

Implémentés. Le détail — variables, choix des canaux, résolution du destinataire — fait autorité dans [Billing § 4](../billing/04-events.md).

| Événement | Template | Canaux |
| --- | --- | --- |
| `billing.subscription.activated` | `subscription.activated` | email |
| `billing.subscription.renewed` | `subscription.activated` | email |
| `billing.subscription.expiring` | `subscription.expiring` | email, **SMS à J-1** |
| `billing.subscription.grace_started` | `subscription.grace` | email + SMS |
| `billing.subscription.suspended` | `subscription.suspended` | email |
| `billing.invoice.issued` | `invoice.issued` | email |
| `billing.invoice.paid` | `invoice.paid` | email |
| `billing.payment.failed` | `payment.failed` | email + SMS |

Tous transactionnels : la plateforme ne pouvant pas prélever en Mobile Money, prévenir est la seule chose qu'elle puisse faire pour être payée — voir [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

Deux événements partagent un template. `renewed` et `activated` disent la même chose au client : votre abonnement est actif jusqu'à telle date. Distinguer les deux serait une nuance de vocabulaire interne, sans valeur pour qui lit.

Leur ajout n'a demandé **aucune modification du pipeline** : une ligne de correspondance et des templates, comme prévu.

## 3.1 Événements à venir

| Module | Événement | Template envisagé |
| --- | --- | --- |
| **Verify** | `verify.verification.completed` | `verification.completed` |
| **Verify** | `verify.verification.rejected` | `verification.rejected` |

---

# 4. Événements émis par Notify

Notify publie à son tour, pour Analytics et pour les modules qui ont besoin de savoir.

| Événement | Quand |
| --- | --- |
| `notify.message.sent` | Accepté par le fournisseur |
| `notify.message.delivered` | Remis au destinataire |
| `notify.message.failed` | Échec définitif, après réessais |
| `notify.recipient.suppressed` | Destination ajoutée à la liste de suppression |

`notify.recipient.suppressed` mérite d'être écouté par Identity : une adresse en rebond dur signifie qu'un compte est devenu injoignable. Continuer à considérer cette adresse comme un moyen de récupération de compte serait une illusion.

---

# 5. Transport

Au démarrage, la plateforme étant un monolithe modulaire, les événements passent par les queues Laravel (Redis).

Le contrat décrit ici est **volontairement indépendant du transport** : il ne suppose ni base partagée, ni appel direct. Le jour où Notify est extrait, seul le transport change — un bus de messages remplace la queue locale, et aucun émetteur n'est modifié.

C'est la même logique que pour les sous-domaines exposés dès le premier jour : le contrat public précède l'extraction.

---

# 6. Politique de réessai

| Étape | Comportement |
| --- | --- |
| Consommation d'un événement | 3 tentatives, backoff exponentiel |
| Livraison chez un fournisseur | 5 tentatives : 1 min, 5 min, 30 min, 2 h, 6 h |
| Bascule de fournisseur | Après 2 échecs **infrastructurels** consécutifs |
| Échec définitif | Statut `failed`, cause enregistrée, événement publié |

Un rejet **métier** — numéro invalide, destinataire supprimé — n'est jamais réessayé : il ne réussira pas davantage à la dixième tentative, et chaque tentative coûte.

---

# 7. Ordre et concurrence

Aucun ordre n'est garanti entre deux événements. `password.reset_requested` peut être traité avant `user.registered`.

Les messages doivent donc être **autonomes** : chacun porte toutes les variables dont il a besoin, et aucun ne suppose qu'un autre est déjà parti. C'est aussi pourquoi les événements transportent les données utiles plutôt que de simples identifiants à recharger.
