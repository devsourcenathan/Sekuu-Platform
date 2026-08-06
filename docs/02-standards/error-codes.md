# Catalogue des codes d'erreur

> **Version :** 1.0
> **Statut :** Standard applicable
> **Dernière mise à jour :** Août 2026

Les [API Guidelines](api-guidelines.md) imposent aux consommateurs de baser leur logique sur le champ `error.code`, jamais sur `error.message` qui est traduit.

Ce document est la **liste de référence** de ces codes. Un code absent de ce catalogue ne peut pas être renvoyé par une API Sekuu.

---

# 1. Règles

* Format : `MAJUSCULES_AVEC_UNDERSCORES`.
* Un code est **stable à vie**. On n'en change jamais le sens ; on en ajoute un nouveau.
* Un code décrit une **cause**, pas un message. `USER_NOT_FOUND`, pas `SOMETHING_WENT_WRONG`.
* Les codes transverses (section 3) sont communs à tous les modules.
* Les codes propres à un domaine sont préfixés par ce domaine (section 4) et déclarés par le module qui les émet.
* Ajouter un code est un changement **compatible**. Le supprimer ou le renommer exige une nouvelle version majeure.

---

# 2. Structure de la réponse

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "details": {
      "email": [
        "The email field is required."
      ]
    }
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

`details` n'est présent que lorsqu'il apporte une information exploitable par le client (erreurs de validation champ par champ, principalement).

---

# 3. Codes transverses

## 3.1 Requête — 400

| Code | Description |
| --- | --- |
| `BAD_REQUEST` | Requête malformée, non couverte par un code plus précis |
| `INVALID_JSON` | Corps de requête JSON illisible |
| `UNSUPPORTED_PARAMETER` | Paramètre de requête inconnu ou non supporté par cet endpoint |
| `INVALID_CURSOR` | Curseur de pagination invalide ou expiré |
| `INVALID_FILTER` | Filtre inconnu ou valeur de filtre invalide |
| `INVALID_SORT` | Champ de tri non autorisé |
| `INVALID_INCLUDE` | Relation non incluable, ou profondeur dépassée |

## 3.2 Authentification — 401

| Code | Description |
| --- | --- |
| `UNAUTHENTICATED` | Aucun token fourni |
| `INVALID_TOKEN` | Token illisible, mal signé ou destiné à une autre audience |
| `TOKEN_EXPIRED` | Token expiré — l'appelant doit le rafraîchir |
| `TOKEN_REVOKED` | Token révoqué (déconnexion, changement de mot de passe, suspension) |
| `INVALID_CREDENTIALS` | Email ou mot de passe incorrect |
| `MFA_REQUIRED` | Second facteur nécessaire pour terminer l'authentification |
| `MFA_INVALID` | Code de second facteur incorrect |

## 3.3 Autorisation — 403

| Code | Description |
| --- | --- |
| `FORBIDDEN` | Accès refusé, sans détail supplémentaire |
| `INSUFFICIENT_PERMISSIONS` | Le rôle global de l'appelant ne couvre pas cette action |
| `ORGANIZATION_MISMATCH` | La ressource appartient à une autre organisation que celle du token |
| `PRODUCT_NOT_ACTIVATED` | Le produit n'est pas actif pour cette organisation |
| `SUBSCRIPTION_REQUIRED` | Aucun abonnement actif ne couvre cette fonctionnalité |
| `EMAIL_NOT_VERIFIED` | Adresse email non vérifiée |
| `ACCOUNT_SUSPENDED` | Compte utilisateur suspendu |
| `ORGANIZATION_SUSPENDED` | Organisation suspendue |
| `PLATFORM_ACCESS_DENIED` | Route réservée à l'administration de la plateforme — voir [ADR-0018](../04-decisions/adr-0018-platform-operator.md) |

## 3.4 Ressource — 404 / 409 / 410

| Code | HTTP | Description |
| --- | --- | --- |
| `RESOURCE_NOT_FOUND` | 404 | Ressource inexistante ou invisible pour l'appelant |
| `ENDPOINT_NOT_FOUND` | 404 | Route inconnue |
| `RESOURCE_CONFLICT` | 409 | Conflit d'état générique |
| `DUPLICATE_RESOURCE` | 409 | Violation d'unicité (email, slug…) |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Clé d'idempotence réutilisée avec un corps différent |
| `RESOURCE_GONE` | 410 | Ressource définitivement supprimée |

Une erreur `404` ne doit jamais permettre de deviner l'existence d'une ressource appartenant à une autre organisation : dans ce cas, `RESOURCE_NOT_FOUND` est préféré à `ORGANIZATION_MISMATCH`.

## 3.5 Validation — 422

| Code | Description |
| --- | --- |
| `VALIDATION_ERROR` | Une ou plusieurs règles de validation ont échoué — voir `details` |
| `UNPROCESSABLE_STATE` | La ressource n'est pas dans un état permettant cette action |

## 3.6 Débit — 429

| Code | Description |
| --- | --- |
| `RATE_LIMIT_EXCEEDED` | Quota de requêtes dépassé — voir `Retry-After` |
| `QUOTA_EXCEEDED` | Quota du plan épuisé (crédits AI, volume de stockage, SMS…) |

## 3.7 Serveur — 500 / 503

| Code | HTTP | Description |
| --- | --- | --- |
| `INTERNAL_ERROR` | 500 | Erreur interne — `request_id` à fournir au support |
| `SERVICE_UNAVAILABLE` | 503 | Service temporairement indisponible |
| `UPSTREAM_ERROR` | 503 | Un fournisseur externe est en échec |
| `UPSTREAM_TIMEOUT` | 503 | Un fournisseur externe n'a pas répondu à temps |

Un `INTERNAL_ERROR` ne doit jamais exposer de trace, de requête SQL ou de nom de classe dans `message`.

---

# 4. Codes par domaine

## 4.1 Identity

| Code | HTTP | Description |
| --- | --- | --- |
| `USER_NOT_FOUND` | 404 | Utilisateur inexistant |
| `EMAIL_ALREADY_USED` | 409 | Cette adresse est déjà rattachée à un compte |
| `PASSWORD_TOO_WEAK` | 422 | Mot de passe non conforme à la politique |
| `PASSWORD_RECENTLY_USED` | 422 | Mot de passe déjà utilisé récemment |
| `RESET_TOKEN_INVALID` | 400 | Jeton de réinitialisation invalide ou expiré |
| `ORGANIZATION_NOT_FOUND` | 404 | Organisation inexistante |
| `ORGANIZATION_SLUG_TAKEN` | 409 | Slug d'organisation déjà pris |
| `ORGANIZATION_REQUIRED` | 403 | Le token ne porte aucune organisation active — appeler `/auth/switch-organization` |
| `MEMBERSHIP_NOT_FOUND` | 404 | L'utilisateur n'appartient pas à cette organisation |
| `ALREADY_MEMBER` | 409 | L'utilisateur est déjà membre de l'organisation |
| `LAST_OWNER_CANNOT_LEAVE` | 409 | Une organisation doit conserver au moins un `Owner` |
| `INVITATION_NOT_FOUND` | 404 | Invitation inexistante |
| `INVITATION_EXPIRED` | 410 | Invitation expirée |
| `INVITATION_ALREADY_ACCEPTED` | 409 | Invitation déjà acceptée |
| `INVITATION_EMAIL_MISMATCH` | 403 | L'invitation vise une autre adresse que celle du compte connecté |
| `WORKSPACE_NOT_FOUND` | 404 | Workspace inexistant |
| `WORKSPACE_ACCESS_DENIED` | 403 | L'utilisateur n'est pas membre de ce workspace |
| `PRODUCT_NOT_FOUND` | 404 | Produit inconnu de la plateforme |
| `API_KEY_INVALID` | 401 | Clé d'API inconnue, expirée ou révoquée — les trois cas sont indiscernables |
| `OAUTH_ACCOUNT_ALREADY_LINKED` | 409 | Ce compte externe est déjà rattaché à un utilisateur |
| `OAUTH_EMAIL_TAKEN` | 409 | Un compte utilise déjà cette adresse ; lier le fournisseur depuis le profil |
| `OAUTH_STATE_INVALID` | 400 | Paramètre `state` absent, expiré, rejoué, ou émis pour un autre fournisseur |
| `OAUTH_PROVIDER_NOT_SUPPORTED` | 422 | Fournisseur non activé sur la plateforme |
| `OAUTH_PROVIDER_ERROR` | 503 | Le fournisseur OAuth a répondu en erreur, ou n'a pas renvoyé d'adresse email |

## 4.2 Verify

| Code | HTTP | Description |
| --- | --- | --- |
| `VERIFICATION_NOT_FOUND` | 404 | Demande de vérification inexistante |
| `VERIFICATION_ALREADY_COMPLETED` | 409 | Vérification déjà finalisée |
| `VERIFICATION_PROVIDER_ERROR` | 503 | Le fournisseur KYC/KYB est en échec |
| `DOCUMENT_UNREADABLE` | 422 | Document illisible ou de qualité insuffisante |
| `DOCUMENT_TYPE_UNSUPPORTED` | 422 | Type de document non pris en charge |
| `WEBHOOK_SIGNATURE_INVALID` | 401 | Signature du webhook entrant invalide |

## 4.3 Notify

| Code | HTTP | Description |
| --- | --- | --- |
| `TEMPLATE_NOT_FOUND` | 404 | Template inexistant, ou sans contenu dans une langue utilisable |
| `TEMPLATE_VARIABLE_MISSING` | 422 | Variable obligatoire absente du payload |
| `TEMPLATE_RENDER_FAILED` | 422 | Le rendu du template a échoué |
| `TEMPLATE_READ_ONLY` | 403 | Template de plateforme : non modifiable via l'API |
| `CHANNEL_NOT_AVAILABLE` | 422 | Le destinataire n'a pas de coordonnée pour ce canal |
| `CHANNEL_NOT_CONFIGURED` | 503 | Aucun fournisseur actif pour ce canal |
| `RECIPIENT_INVALID` | 422 | Adresse email ou numéro invalide |
| `RECIPIENT_OPTED_OUT` | 403 | Le destinataire a désactivé cette catégorie |
| `RECIPIENT_SUPPRESSED` | 403 | Destination sur liste de suppression (rebond dur, plainte, désabonnement) |
| `TRANSACTIONAL_CANNOT_BE_DISABLED` | 422 | Une catégorie transactionnelle ne peut pas être désactivée |
| `NOTIFICATION_NOT_FOUND` | 404 | Notification inexistante |
| `NOTIFICATION_NOT_CANCELLABLE` | 409 | Envoi déjà parti ou déjà terminé |
| `SUPPRESSION_NOT_FOUND` | 404 | Entrée de suppression inexistante |
| `UNSUBSCRIBE_TOKEN_INVALID` | 400 | Jeton de désabonnement inconnu ou expiré |
| `PROVIDER_ERROR` | 503 | Le fournisseur d'envoi est en échec |

## 4.4 Billing

| Code | HTTP | Description |
| --- | --- | --- |
| `PLAN_NOT_FOUND` | 404 | Plan inexistant |
| `SUBSCRIPTION_NOT_FOUND` | 404 | Abonnement inexistant |
| `SUBSCRIPTION_ALREADY_ACTIVE` | 409 | Un abonnement actif existe déjà pour cette organisation |
| `SUBSCRIPTION_EXPIRED` | 403 | Abonnement expiré |
| `PLAN_ARCHIVED` | 409 | Plan retiré du catalogue |
| `INVOICE_NOT_FOUND` | 404 | Facture inexistante |
| `INVOICE_ALREADY_PAID` | 409 | Facture déjà réglée |
| `INVOICE_VOIDED` | 409 | Facture annulée, non payable |
| `CURRENCY_NOT_SUPPORTED` | 422 | Devise non prise en charge |
| `DOWNGRADE_NOT_ALLOWED` | 409 | L'usage courant dépasse les limites du plan visé |

## 4.5 Payments

Produits par la couche d'encaissement, quel que soit ce qui est payé. Ils
remontent tels quels à travers la route du module propriétaire — `POST /payments`
côté Billing en est le point d'entrée, pas l'auteur.

| Code | HTTP | Description |
| --- | --- | --- |
| `PAYMENT_FAILED` | 402 | Paiement refusé — solde insuffisant, code erroné, annulation |
| `PAYMENT_PENDING` | 202 | Paiement en cours, issue encore inconnue |
| `PAYMENT_ALREADY_PENDING` | 409 | Une intention de paiement est déjà en cours sur cette facture |
| `PAYMENT_METHOD_REQUIRED` | 422 | Aucun moyen de paiement enregistré — **inutilisé** : le Mobile Money n'en conserve aucun |
| `INVALID_MSISDN` | 422 | Numéro de téléphone invalide, ou opérateur non reconnu |
| `PROVIDER_UNAVAILABLE` | 503 | Aucun fournisseur de paiement configuré pour cet opérateur |
| `PAYABLE_TYPE_UNKNOWN` | 422 | `subject_type` absent de `config/payments.php` |
| `NOTHING_DUE` | 409 | Cet objet est déjà réglé, ou gratuit |
| `WEBHOOK_SIGNATURE_INVALID` | 401 | Signature du callback d'un agrégateur invalide |

### Remboursement

| Code | HTTP | Description |
| --- | --- | --- |
| `REFUND_NOT_SUPPORTED` | 409 | Le propriétaire de cet objet ne rembourse pas — un trop-perçu y devient un crédit |
| `REFUND_EXCEEDS_PAYMENT` | 422 | Le montant dépasse ce qui reste remboursable sur ce paiement |
| `PAYMENT_NOT_SETTLED` | 409 | On ne rembourse que ce qui a été encaissé |
| `CURRENCY_MISMATCH` | 422 | La devise du remboursement diffère de celle du paiement |
| `REFUND_TRANSFER_FAILED` | — | Motif d'échec porté par le remboursement, jamais renvoyé par l'API |

`REFUND_NOT_SUPPORTED` est la réponse de **Billing**, et ce n'est pas une
lacune : un remboursement Mobile Money est lent et coûteux, alors qu'un client
d'abonnement repassera à la caisse le mois suivant. Voir
[ADR-0007](../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

Un propriétaire l'obtient en **ne portant pas** `RefundableSource`. Le défaut est
donc le refus, et il échoue durement plutôt que silencieusement.

### API externe

| Code | HTTP | Description |
| --- | --- | --- |
| `SUBJECT_TYPE_NOT_ALLOWED` | 403 | La clé d'API ne porte pas ce type d'objet, ou le type n'est pas servi par l'API externe |
| `PAYER_TYPE_NOT_ALLOWED` | 422 | Un produit externe ne peut pas désigner un compte de la plateforme comme payeur |
| `CHARGE_NOT_FOUND` | 409 | Aucune charge en attente sur cet objet, ou elle appartient à un autre payeur |

`SUBJECT_TYPE_NOT_ALLOWED` couvre deux causes distinctes sous un seul code,
délibérément : deux réponses différentes permettraient d'énumérer les types
d'objet servis par la plateforme.

`CHARGE_NOT_FOUND` répond de même à « inexistante » et à « pas à vous ». C'est la
règle déjà posée pour les factures — distinguer transformerait l'endpoint en
oracle.

## 4.6 Storage

| Code | HTTP | Description |
| --- | --- | --- |
| `FILE_NOT_FOUND` | 404 | Fichier inexistant **ou** hors de portée de l'appelant |
| `FILE_TOO_LARGE` | 422 | Taille supérieure à la limite autorisée |
| `MIME_TYPE_NOT_ALLOWED` | 422 | Type de fichier interdit |
| `UPLOAD_INCOMPLETE` | 422 | Les octets ne sont pas dans le magasin |
| `STORAGE_QUOTA_EXCEEDED` | 429 | Quota de stockage de l'organisation atteint |
| `FILE_OWNER_TYPE_UNKNOWN` | 422 | `owner_type` absent du registre |
| `FILE_ATTACH_FORBIDDEN` | 403 | Le propriétaire refuse le rattachement |
| `FILE_NOT_READY` | 409 | Fichier déclaré, octets jamais constatés |
| `FILE_RETAINED` | 409 | Conservation obligatoire non expirée |
| `FILE_RETENTION_TOO_LONG` | 422 | Rétention demandée au-delà du plafond de la clé |
| `FILE_POLICY_INCOHERENT` | 422 | Le propriétaire déclare un repli sans destination principale |
| `STORAGE_DRIVER_UNKNOWN` | 422 | Pilote ou préréglage inconnu |
| `STORAGE_DESTINATION_NOT_FOUND` | 404 | Magasin inexistant, ou hors de portée |
| `STORAGE_DESTINATION_FORBIDDEN` | 403 | Magasin nommé, mais pas à cet appelant |
| `STORAGE_DESTINATION_UNVERIFIED` | 409 | Magasin jamais éprouvé, ou retombé en échec |
| `STORAGE_DESTINATION_IN_USE` | 409 | Suppression refusée : le magasin porte des fichiers |
| `STORAGE_DESTINATION_UNAVAILABLE` | 503 | Le magasin refuse ou ne répond pas |

`FILE_NOT_FOUND` répond de même à « inexistant » et à « pas à vous » — la règle
déjà posée pour les factures et les charges.

`FILE_ATTACH_FORBIDDEN` est un `403` assumé, et ne contredit pas la règle
précédente : l'appelant a donné lui-même l'objet auquel il veut rattacher un
fichier, donc il en connaît déjà l'existence. Il n'y a rien à lui apprendre.

## 4.7 AI

| Code | HTTP | Description |
| --- | --- | --- |
| `MODEL_NOT_AVAILABLE` | 422 | Modèle demandé indisponible |
| `CONTEXT_LENGTH_EXCEEDED` | 422 | Entrée trop longue pour le modèle |
| `CONTENT_FLAGGED` | 422 | Contenu rejeté par la modération |
| `AI_TASK_UNKNOWN` | 422 | Tâche absente du catalogue de la plateforme |
| `AI_QUOTA_EXCEEDED` | 429 | Crédits IA de l'organisation épuisés |
| `AI_SPEND_CAP_REACHED` | 429 | Plafond absolu de la plateforme atteint |
| `AI_ACCOUNT_FORBIDDEN` | 403 | Compte d'IA nommé, mais pas à cet appelant |
| `AI_ACCOUNT_UNVERIFIED` | 409 | Compte jamais éprouvé, ou retombé en échec |
| `AI_ACCOUNT_CAP_REACHED` | 429 | Plafond propre au compte atteint |
| `AI_ACCOUNT_IN_USE` | 409 | Le nom court demandé est déjà pris |
| `AI_ALREADY_STARTED` | 409 | Annulation demandée après le départ de la requête |
| `AI_PROVIDER_ERROR` | 503 | Le fournisseur d'IA est en échec |
| `AI_PROVIDER_TIMEOUT` | 503 | Le fournisseur d'IA n'a pas répondu à temps |
| `AI_PROVIDER_UNREACHABLE` | 503 | Aucune connexion établie — DNS, refus, certificat |
| `AI_TASK_OUT_OF_SCOPE` | 403 | Tâche connue, mais hors de la liste blanche de la clé |
| `AI_OUTPUT_INVALID` | 502 | Sortie hors schéma, deux fois de suite |
| `AI_CREDENTIALS_REJECTED` | 503 | Clé refusée par le fournisseur |
| `AI_CREDIT_EXHAUSTED` | 503 | Crédit du compte épuisé **chez le fournisseur** |
| `AI_RATE_LIMITED` | 503 | Débit refusé par le fournisseur |
| `AI_ACCOUNT_NOT_FOUND` | 404 | Compte nommé inexistant |
| `AI_DRIVER_UNKNOWN` | 422 | Pilote ou préréglage inconnu |

`AI_CREDIT_EXHAUSTED` est distinct d'`AI_RATE_LIMITED` alors que les
fournisseurs rendent souvent le même statut pour les deux. Les confondre fait
réessayer indéfiniment chez un compte à sec, et envoie régénérer une clé qui n'a
rien de cassé : l'un se résout en quelques secondes, l'autre demande une carte
bancaire.

`AI_SPEND_CAP_REACHED` est distinct d'`AI_QUOTA_EXCEEDED`, et la distinction
compte : le premier dit « la plateforme s'est protégée », le second « votre plan
est épuisé ». Le premier n'est pas la faute du client, et l'inviter à passer au
plan supérieur serait mensonger.

## 4.8 Search

| Code | HTTP | Description |
| --- | --- | --- |
| `QUERY_INVALID` | 400 | Requête de recherche mal formée |
| `INDEX_NOT_READY` | 503 | Index en cours de reconstruction |

---

# 5. Ajouter un code

1. Vérifier qu'aucun code transverse existant ne couvre déjà le cas.
2. Ajouter la ligne dans la section du domaine concerné.
3. Déclarer le code dans le contrat `openapi.yaml` du service.
4. Ajouter la traduction du message dans les langues supportées.

Le catalogue est versionné avec le code. Toute divergence entre ce document et une implémentation est un bug de l'implémentation.
