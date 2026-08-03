# Sekuu Billing — Agrégateurs de paiement

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document consigne ce qui a été **vérifié dans la documentation publique** de chaque agrégateur, et ce qui reste à confirmer au moment de l'intégration.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [API](03-api.md) · [ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md)

> Les informations ci-dessous proviennent de la documentation publique consultée en août 2026. Elles doivent être revérifiées avant l'intégration : une API de paiement évolue, et une hypothèse périmée coûte ici de l'argent réel.

---

# 1. Le constat le plus important

**Aucun des deux agrégateurs documentés n'expose de champ disant « le client a reçu l'invite ».**

C'est exactement la donnée dont dépend la règle de bascule de l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md). Elle n'existe pas telle quelle ; elle doit être **déduite**, et la déduction n'a qu'une seule source fiable :

> L'appel de débit a-t-il été **accepté** par l'agrégateur ?

| Issue de l'appel | `customer_prompted` | Bascule |
| --- | --- | --- |
| Erreur HTTP claire (4xx d'authentification ou de validation) | `false` | **Oui** |
| Erreur réseau, temporisation, 5xx | **`true`** | Non |
| Acceptation (202, ou statut de départ) | `true` | Non |

La deuxième ligne est celle qui compte. Quand l'appel expire, on ne sait pas s'il a atteint l'agrégateur — donc on ne sait pas si l'invite est partie. Le module tranche du côté qui ne débite pas deux fois.

C'est aussi ce qui justifie d'écrire `merchant_reference` **avant** l'appel : c'est la seule clé qui permet ensuite de demander à l'agrégateur ce qu'il est advenu d'une demande dont on n'a jamais reçu la réponse.

Les deux agrégateurs acceptent une référence marchande : `reference` chez Notch Pay, `mchTransactionRef` chez Tranzak. Le modèle de données était donc correct sur ce point.

---

# 2. Notch Pay

> Source : [developer.notchpay.co](https://developer.notchpay.co/)

## 2.1 Ce qui est confirmé

| Élément | Valeur |
| --- | --- |
| Base | `https://api.notchpay.co` |
| Initialisation | `POST /payments` |
| Débit mobile money | `/payments/{reference}` avec `channel` et `data.phone` |
| Consultation | `GET /payments/{reference}` |
| Canaux Cameroun | `cm.mtn`, `cm.orange` |
| Référence marchande | `reference` |
| Montant | En **plus petite unité** de la devise |

Le montant en plus petite unité concorde avec le choix du modèle de données. Pour le XAF, exposant 0 : 45 000 XAF s'envoie `45000`.

## 2.2 Statuts

| Statut Notch Pay | Sens documenté | Traduction Sekuu | Bascule |
| --- | --- | --- | --- |
| `pending` | Initialisé, pas encore traité | `created` | — |
| `processing` | En cours chez le fournisseur | `prompted` | Non |
| `complete` | Terminé | `succeeded` | Non |
| `failed` | Échec | `failed` | Non |
| `canceled` | Annulé par le marchand ou le client | `failed` | Non |
| `expired` | Expiré avant achèvement | `expired` | Non |

`pending` correspond à une session de paiement initialisée mais **avant** l'appel de débit : à ce stade, aucune invite n'est partie. C'est le seul moment où une bascule reste sûre.

Dès `processing`, l'invite est considérée comme partie. La documentation ne le garantit pas explicitement — d'où le choix conservateur.

## 2.3 Webhooks

| Élément | Valeur |
| --- | --- |
| En-tête de signature | `x-notch-signature` |
| Algorithme | HMAC-SHA256 |
| Signé | Le corps **brut** |
| Vérification PHP | `hash_hmac('sha256', $payload, $hash)` puis `hash_equals()` |
| Événements | `payment.created`, `payment.complete`, `payment.failed`, `payment.canceled`, `payment.expired` |
| Déduplication | `data.id` |

Signature HMAC sur le corps brut, comparée en temps constant : c'est exactement le schéma déjà implémenté pour Resend dans Notify. L'adaptateur est donc structurellement connu.

## 2.4 À vérifier

* **Le verbe HTTP du débit** — la documentation le présente en `POST` sur la page Mobile Money et en `PUT` dans la référence d'API. À trancher avant d'écrire l'adaptateur.
* Les noms exacts des en-têtes d'authentification, dont un `X-Sync` évoqué dans les réponses `401`.
* La liste exhaustive des codes d'erreur `422`, pour distinguer une validation refusée d'un opérateur indisponible.
* Le barème de commission — absent de la documentation technique.

Notch Pay recommande les webhooks plutôt que le sondage. **On fera les deux.** Un callback perdu, chez un agrégateur qui déconseille le sondage, produirait exactement la défaillance que ce module doit rendre impossible : un client débité sans accès.

---

# 3. Tranzak

> Source : [docs.developer.tranzak.me](https://docs.developer.tranzak.me/)

## 3.1 Ce qui est confirmé

| Élément | Valeur |
| --- | --- |
| Production | `https://dsapi.tranzak.me` |
| Bac à sable | `https://sandbox.dsapi.tranzak.me` |
| Authentification | `POST /auth/token` avec `appId` + `appKey` → jeton porteur |
| Durée du jeton | ~2 h ; mise en cache recommandée à ~75 % de la validité |
| Débit mobile money | `POST /xp021/v1/request/create-mobile-wallet-charge` |
| Consultation | `GET /xp021/v1/request/details?requestId=…` |
| Rafraîchissement forcé | `POST /xp021/v1/request/refresh-transaction-status` |
| Annulation | `POST /xp021/v1/request/cancel` |
| Remboursement | `POST /xp021/v1/request/void` |
| Référence marchande | `mchTransactionRef` (unique) |
| Numéro | `mobileWalletNumber`, préfixé de l'indicatif pays |
| Services | MTN Cameroon Mobile Money, Orange Money Cameroon |

L'existence d'un **bac à sable** et d'un endpoint de rafraîchissement forcé fait de Tranzak le meilleur candidat pour développer en premier, indépendamment de son rang de priorité.

`refresh-transaction-status` est précisément l'outil du sondage exigé par l'[ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md). Aucun autre agrégateur n'en documente d'équivalent explicite.

## 3.2 Statuts

| Statut Tranzak | Traduction Sekuu | Bascule |
| --- | --- | --- |
| `PENDING` | `prompted` | Non |
| `PAYMENT_IN_PROGRESS` | `processing` | Non |
| `SUCCESSFUL` | `succeeded` | Non |
| `FAILED` | `failed` | Non |
| `CANCELLED_BY_PAYER` | `failed` | Non |
| `CANCELLED` | `failed` | Non |
| `PAYER_REDIRECT_REQUIRED` | `failed` | Non |

`CANCELLED_BY_PAYER` est le seul statut de tout l'écosystème qui **prouve** que le client a été sollicité : il n'aurait pas pu annuler sans avoir reçu l'invite.

`CANCELLED` — annulation par le système — est ambigu. Il pourrait signifier que la demande n'a jamais atteint le client, ce qui autoriserait une bascule. Faute de certitude, il est traité comme `failed`. Le sens exact est à confirmer auprès de Tranzak ; c'est la seule vérification qui pourrait élargir la règle de bascule.

`PAYER_REDIRECT_REQUIRED` concerne le flux de redirection web, pas le débit direct. Le rencontrer sur un `create-mobile-wallet-charge` signalerait une erreur d'intégration, pas un état normal.

## 3.3 Callbacks

| Élément | Valeur |
| --- | --- |
| Vérification | Champ `authKey` **dans le corps** |
| Type d'événement | `eventType`, ex. `REQUEST.COMPLETED` |
| Corrélation | `resourceId`, plus l'objet `resource` complet |
| Journal de livraison | `GET /xp021/v1/api-activity/notifications` |
| Renvoi manuel | `POST /xp021/v1/api-activity/trigger-tpn` |

**`authKey` est un secret partagé transporté dans le corps, pas une signature.**

La différence est réelle et doit être écrite dans l'adaptateur : un secret partagé prouve que l'émetteur connaît le secret, il ne prouve **rien** sur l'intégrité du corps. Un attaquant ayant intercepté un callback légitime peut le rejouer modifié.

Deux conséquences, non négociables :

1. **Le montant d'un callback Tranzak n'est jamais cru.** Il est relu par `GET /request/details`, ou comparé à l'intention enregistrée.
2. La déduplication par `(provider, provider_event_id)` devient une protection de sécurité, pas seulement de propreté.

Ces règles s'appliquent d'ailleurs aussi à Notch Pay. La signature HMAC y rend le rejeu modifié impossible, mais le rejeu à l'identique reste possible — c'est ce que la contrainte d'unicité en base neutralise.

## 3.4 Commissions

Tranzak est le seul à documenter la mécanique :

| Champ | Rôle |
| --- | --- |
| `payerFeePercentage` | Part de commission à la charge du payeur (0–100) |
| `payeeFeePercentage` | Idem pour les décaissements |
| `netAmountReceived` | Montant réellement crédité au marchand |

Cela confirme la séparation `gross_amount` / `fee_amount` / `net_amount` du modèle de données, et la ligne de registre `type = 'fee'`.

Un arbitrage commercial en découle : `payerFeePercentage` permet de faire porter la commission au client. Ce n'est pas une décision technique — mais elle change le montant à débiter, donc le total de la facture. **Par défaut, la commission reste à la charge de la plateforme** : afficher 45 000 XAF puis débiter 45 900 XAF détruit la confiance pour une économie marginale.

## 3.5 À vérifier

* Le sens exact de `CANCELLED` — seul point susceptible d'élargir la règle de bascule.
* La liste complète des `eventType`, au-delà de `REQUEST.COMPLETED`.
* Si `authKey` transite aussi en en-tête, ce qui permettrait de le vérifier avant de désérialiser le corps.
* Le barème de commission appliqué au compte marchand.

---

# 4. Tara

> Source : [taramoney.com/developer](https://taramoney.com/developer)

**Aucune documentation technique publique accessible.** Le portail développeur ne rend qu'une page d'accueil sans contenu exploitable, et aucune référence d'API n'est indexée.

Ce qui est établi : Tara opère au Cameroun sur MTN MoMo et Orange Money, avec une distribution par WhatsApp, Telegram et SMS.

## 4.1 Ce que cela implique

L'adaptateur Tara **ne peut pas être spécifié** aujourd'hui. Les quatre points nécessaires — schéma d'authentification, endpoint de débit, vocabulaire des statuts, vérification des callbacks — sont tous inconnus.

Deux conséquences pratiques :

* **Tara passe en dernier rang de priorité**, ce qui était déjà le cas, et son intégration est repoussée après les deux autres.
* Le module doit fonctionner **avec deux agrégateurs**. La bascule à trois est un objectif, pas un prérequis : deux suffisent à supprimer le point de défaillance unique, qui est la raison d'être de l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md).

Cela ne remet pas en cause la conception : `ProviderRegistry` prend N agrégateurs, et un agrégateur non configuré n'est jamais essayé — exactement comme Postmark dans Notify, présent dans la configuration et jamais sollicité.

## 4.2 À obtenir

La documentation technique doit être demandée **directement à Tara**, avant tout engagement de développement. Sans elle, l'estimation de l'intégration n'a aucun fondement.

---

# 5. Ordre d'intégration recommandé

L'ordre de priorité à l'exécution — NotchPay, Tranzak, Tara — n'est pas l'ordre dans lequel il faut les écrire.

| Rang de développement | Agrégateur | Motif |
| --- | --- | --- |
| 1 | **Tranzak** | Bac à sable documenté, statuts explicites, rafraîchissement de statut, commissions décrites |
| 2 | **Notch Pay** | Signature HMAC déjà maîtrisée, mais pas de bac à sable documenté |
| 3 | **Tara** | Spécification indisponible |

Développer d'abord celui qui possède un bac à sable est ce qui distingue un adaptateur testé d'un adaptateur supposé correct. C'est la leçon du canal SMS de Notify, écrit intégralement et jamais exécuté contre une vraie passerelle.

---

# 6. Ce que chaque adaptateur doit obligatoirement déclarer

Un adaptateur qui ne répond pas à ces trois questions ne peut pas être mis en production :

1. **Quels statuts signifient « l'invite n'est jamais partie » ?** Liste fermée et explicite. Tout le reste vaut `prompted`.
2. **Quelles issues d'appel autorisent une bascule ?** Par défaut : aucune. Seules les erreurs d'authentification et de validation, jamais les temporisations.
3. **Comment retrouver une transaction à partir de notre `merchant_reference` ?** Sans cette capacité, un appel expiré reste à jamais irrésolu.

Ces trois points sont le premier sujet de tests de chaque adaptateur, avant le chemin nominal. C'est là, et nulle part ailleurs, qu'une erreur coûte de l'argent réel à un client.
