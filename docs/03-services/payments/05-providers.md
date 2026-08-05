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

## 2.0 Ce que le bac à sable a démenti

**`transaction` est un objet, pas la chaîne que montre la documentation.**

L'exemple documenté de l'initialisation donne `"transaction": "81fca0c3-…"`. La réponse réelle donne un objet :

```json
"transaction": {
  "reference": "trx.test_N1d3HSS8n7C2oyUICJ6kK86t",
  "merchant_reference": "SKUB4ED89758696",
  "trxref": "SKUB4ED89758696",
  "status": "pending",
  "amount": 100,
  "sandbox": true
}
```

C'était **le piège le plus coûteux de cet adaptateur**. Un code n'attendant que la chaîne aurait lu une absence de référence — traitée comme un rejet, donc comme un cas **basculable**, alors qu'un paiement existe. La tolérance aux deux formes avait été écrite par précaution ; elle s'est avérée nécessaire.

**La commission n'est pas un scalaire.** `fees` est un **tableau**, vide en bac à sable, et il n'existe aucun champ de montant net :

```json
"amount": 100,
"fees": [],
"amounts": { "total": 100, "converted": 100, "currency": "XAF", "rate": null },
"charge": "business"
```

La première version lisait `fee` et `amount_received` — aucun des deux n'existe. La forme des entrées de `fees` en production reste **non vérifiée** : la lecture est au mieux, l'échec est journalisé, et le net est **déduit** plutôt qu'inventé. Une commission inconnue n'affecte ni le client ni la facture, qui se règle sur le brut.

`charge: "business"` indique que la commission est à la charge du marchand.

## 2.0.2 Le corps des callbacks contredit la documentation

Callback authentique reçu à travers un tunnel public :

```json
{
  "id": "whc_test.RBbtPFQbBiIXebt7",
  "event": "payment.complete",
  "data": {
    "status": "complete",
    "reference": "trx.test_xRV6AHATJGClY8RGngx3efVK",
    "merchant_reference": "SKU57352EA63744B5D4",
    "trxref": "SKU57352EA63744B5D4",
    "amount": 100, "fees": [], "charge": "business"
  }
}
```

La documentation annonce un champ `type` et un `data.id`. Le corps réel porte **`event`** et un **`id` de premier niveau**.

La première version retombait donc systématiquement sur l'empreinte du corps comme clé de déduplication. Cela fonctionnait *par accident* — deux livraisons distinctes ont des corps distincts —, mais un **renvoi** de la même livraison, avec ne serait-ce qu'un horodatage différent, aurait été traité deux fois. `id` est un identifiant par livraison : c'est la bonne clé.

## 2.0.3 Un paiement produit trois callbacks, dans un ordre variable

Un seul paiement a déclenché `pending`, `processing` et `complete`. **L'ordre observé a changé d'un essai à l'autre** — une fois `processing` puis `pending`, une fois l'inverse.

C'est la démonstration en conditions réelles de la règle « le corps ne décide jamais de l'issue » : croire le statut annoncé aurait fait régresser un paiement encaissé vers « en attente ». Le statut est relu chez l'agrégateur, et une intention réussie n'est jamais rétrogradée.

## 2.0.4 Vérification de bout en bout

Chaîne complète éprouvée contre le bac à sable, callbacks compris :

| Étape | Constat |
| --- | --- |
| Signature HMAC-SHA256 sur le corps brut | Valide |
| Déduplication par `id` de livraison | Trois livraisons, trois clés |
| Rattachement à la tentative par `data.reference` | Trouvé |
| Issue | Tentative et intention `succeeded`, facture `paid` 100/100 |
| Registre | `charge +100 XAF` |

Aucune ligne `fee` : le bac à sable renvoie `fees: []`. La commission reste le seul point non vérifié de cet adaptateur.

Les callbacks arrivés sans tentative correspondante — sondes antérieures appelant l'adaptateur sans passer par `InitiatePayment` — ont été enregistrés, signalés `unknown_reference`, et **laissés visibles**. C'est le comportement voulu : un callback qu'on ne sait pas rattacher signale une erreur de configuration entre environnements, et doit se voir.

## 2.0.5 URL de rappel par paiement

Notch Pay accepte un champ `callback` à l'initialisation, qui prime sur celle du tableau de bord.

C'est ce qui permet à plusieurs environnements de partager un compte marchand : le tableau de bord n'accepte qu'une URL. `NOTCHPAY_CALLBACK_URL` reste **vide par défaut** — une URL figée dans des transactions passées survivrait à un changement d'hébergement.

## 2.0.1 Le bac à sable a des numéros déterministes

Ils ne sont pas dans la documentation — l'API les renvoie dans le message d'erreur d'un numéro non conforme.

| Numéro | Cas | Statut final observé | Traduction Sekuu |
| --- | --- | --- | --- |
| `+237670000000` | succès | `complete` | `succeeded` |
| `+237670000001` | solde insuffisant | `complete` | `succeeded` |
| `+237670000002` | échec | `failed` | `failed` |
| `+237670000003` | temporisation | `expired` | `failed` |
| `+237670000004` | annulé | `canceled` | `failed` |

Les six statuts documentés ont donc tous été observés. **Aucun n'autorise de bascule** : à ce stade le traitement a été accepté, donc le client a été sollicité.

Deux réserves honnêtes : le numéro « solde insuffisant » aboutit en `complete` — le bac à sable ne l'honore pas —, et les cas `expired` et `canceled` mettent plusieurs secondes à converger, ce qui confirme que le sondage n'est pas optionnel.

Le montant minimum est de **5 XAF**.

## 2.4 Deux étapes, et pourquoi cela compte

Le débit se fait en **deux appels** : `POST /payments` initialise, `POST /payments/{reference}` traite.

C'est une différence structurelle avec Tranzak, et elle **rétrécit la fenêtre dangereuse** :

| Appel | Le client peut-il avoir été sollicité ? | Temporisation |
| --- | --- | --- |
| Initialisation | **Non**, rien ne lui est présenté | **Basculable** |
| Traitement | Oui — c'est lui qui déclenche l'invite | Non basculable |

Une temporisation à l'initialisation reste donc sûre : au pire elle laisse un paiement orphelin chez Notch Pay, sur lequel aucun argent ne bouge. Chez Tranzak, l'appel unique rend toute temporisation incertaine.

La documentation est par ailleurs explicite sur le moment de l'invite : après le traitement, *« the customer receives a prompt on their mobile device »*. C'est le seul agrégateur à le dire noir sur blanc.

## 2.5 Authentification

| En-tête | Contenu | Requis pour |
| --- | --- | --- |
| `Authorization` | Clé **publique**, **sans** préfixe `Bearer` | Tous les appels |
| `X-Grant` | Clé privée | Soldes, transferts, bénéficiaires, gestion des webhooks |
| `X-Sync` | Identifiant de compte synchronisé | Comptes synchronisés |

Les paiements n'exigent que `Authorization`. Une clé de test est préfixée `test_`.

## 2.6 Le champ `transaction` change de forme

À l'initialisation, `transaction` est une **chaîne**. Au traitement, c'est un **objet** portant `reference`, `trxref` et `status`.

Un adaptateur qui n'attend qu'une forme lirait l'autre comme une absence de référence — et une réponse acceptée sans référence est traitée comme un rejet, donc **basculable à tort**. Les deux formes sont donc reconnues.

`trxref` renvoie notre `reference` d'initialisation : c'est la clé de corrélation exigée par l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md), équivalente au `mchTransactionRef` de Tranzak.

## 2.7 À vérifier

* **La forme des entrées de `fees`** en production. Vide en bac à sable, donc jamais observée. C'est le seul point encore supposé de cet adaptateur.
* Le barème réel de commission.

Notch Pay recommande les webhooks plutôt que le sondage. **On fera les deux.** Un callback perdu, chez un agrégateur qui déconseille le sondage, produirait exactement la défaillance que ce module doit rendre impossible : un client débité sans accès.

---

# 3. Tranzak

> Source : [docs.developer.tranzak.me](https://docs.developer.tranzak.me/), **plus des appels réels au bac à sable** (août 2026).

## 3.0 Ce que le bac à sable a démenti

**Le statut HTTP ne fait pas autorité.** Tranzak signale ses refus par
`success: false` **dans le corps**, avec un `HTTP 200`.

```json
{ "data": [], "success": false, "errorMsg": "Mobile phone number is invalid: +237000000000", "errorCode": 1002 }
{ "data": [], "success": false, "errorMsg": "Amount the must be greater than zero.", "errorCode": null }
{ "data": [], "success": false, "errorMsg": "Authentication Error", "errorCode": 40022 }
```

Seul un jeton invalide produit un vrai `401`.

La première version de l'adaptateur classait les refus par code HTTP. Tous
tombaient donc dans « issue inconnue », donc « invite partie », donc **bascule
interdite** : la mécanique de l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md)
était inerte. Le défaut penchait du bon côté — on ne double-débitait pas — mais
les trois agrégateurs n'auraient servi à rien.

**Les codes d'erreur ne peuvent pas servir de critère** : `1002`, `40022`,
`401`, `0`, `null`. Une liste blanche incomplète échouerait « ouvert », c'est-à-dire
en autorisant une bascule à tort.

La règle retenue est **structurelle** : Tranzak ne peut pas avoir sollicité un
client pour une demande qu'il a refusé de créer. Un `success: false` **sans
référence de transaction** signifie qu'aucune transaction n'existe — donc aucune
invite. Avec référence, la bascule reste interdite.

Au **sondage**, le même `success: false` ne veut pas dire la même chose : il
signifie que Tranzak ne sait pas répondre sur cette transaction, pas qu'il a
refusé une demande. Une tentative n'y est jamais rétrogradée en `rejected`.

## 3.0.2 La commission n'est pas là où la documentation le laissait croire

Sur un paiement abouti, les montants sont **imbriqués** :

```json
"amount": 100,
"payer":    { "amount": 100, "fee": 0, "netAmountPaid": 100 },
"merchant": { "amount": 100, "fee": 3, "netAmountReceived": 97 }
```

La première version lisait `merchantFee` et `netAmountReceived` **à la racine**. Les deux ressortaient nuls, donc la ligne `fee` du registre n'était jamais écrite : toute la séparation brut / net du module restait inerte, sans qu'aucun test ne le voie.

`payer.fee` et `merchant.fee` sont deux chiffres différents — 0 et 3 sur ce paiement. Lire l'un pour l'autre enregistrerait un montant faux au registre. La commission observée est de **3 %**.

## 3.0.3 `CANCELLED` ne se produit pas sur ce flux

C'était le seul point susceptible d'élargir la règle de bascule. Vérification faite : une annulation marchande ressort en

```json
{ "status": "FAILED", "errorCode": 3008, "errorMessage": "TXN_CANCELLED", "success": true }
```

Donc `FAILED`, pas `CANCELLED`. Et `success` vaut `true` au sommet — un échec **métier** n'est pas un refus de demande, la distinction tient.

La correspondance de `CANCELLED` est conservée par prudence : un statut documenté qu'on n'a jamais observé reste un statut possible.

Le motif se lit dans `errorMessage`. `statusMessage`, que lisait la première version, n'existe pas.

## 3.0.1 Ce que le bac à sable a confirmé

| Supposition | Vérifiée |
| --- | --- |
| Enveloppe `data` sur toutes les réponses | Oui, plus un `success` booléen au sommet |
| `POST /auth/token` renvoie `data.token` | Oui, avec `expiresIn: 7200` et `scope` |
| Mise en cache du jeton à 75 % de sa validité | 90 min sur 120 : exact |
| Montants en unité brute, sans conversion | Oui — soldes en `XAF` entiers, exposant 0 |
| Compte de collecte distinct du compte de reversement | Oui, `type` les distingue |
| `mchTransactionRef` renvoyé tel quel | Oui — la corrélation marchande fonctionne |
| Premier statut après un débit accepté | `PAYMENT_IN_PROGRESS`, pas `PENDING` |

**Le bac à sable valide automatiquement** : le payeur y apparaît en `isGuest: true` sous un nom généré, et aucune invite n'atteint le téléphone. Le chemin « client sollicité » est donc simulé, jamais éprouvé — seul un compte de production le vérifiera.

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

## 3.3.1 Le corps réel d'un callback

Callback authentique reçu à travers un tunnel public :

```json
{
  "name": "Tranzak Payment Notification (TPN)",
  "eventType": "REQUEST.COMPLETED",
  "webhookId": "WHXBE74T9H7BCMHURDMQJG",
  "resourceId": "REQ2608031551VZY4IDS",
  "authKey": "…",
  "resource": {
    "amount": 100,
    "status": "SUCCESSFUL",
    "payer":    { "fee": 0, "amount": 100, "netAmountPaid": 100, "isGuest": true },
    "merchant": { "fee": 3, "amount": 100, "netAmountReceived": 97 }
  }
}
```

Il porte un `webhookId` **par livraison** — le choix naturel comme clé de déduplication, et celui retenu pour Notch Pay.

**Il n'est délibérément pas utilisé ici.** Notch Pay signe ses callbacks : un rejeu modifié y est impossible. Tranzak n'authentifie que par un secret partagé dans le corps ; un callback capté peut donc être rejoué avec un `webhookId` forgé, et serait traité une seconde fois. La clé retenue — `eventType` + ressource — ne dépend que du fait rapporté, et résiste à cela.

Deux agrégateurs, deux clés, parce qu'ils n'offrent pas les mêmes garanties.

## 3.3.2 Vérification de bout en bout

| Étape | Constat |
| --- | --- |
| `authKey` dans le corps | Accepté |
| Rattachement à la tentative par `resourceId` | Trouvé |
| Relecture du statut chez Tranzak | `SUCCESSFUL` |
| Issue | Tentative et intention `succeeded`, facture `paid` 100/100 |
| Registre | `charge +100 XAF` **et `fee −3 XAF`** |

La ligne de commission apparaît — ce que le bac à sable de Notch Pay ne peut pas produire, `fees` y étant toujours vide. La séparation brut / net est donc éprouvée **une fois**, contre Tranzak.

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

| Agrégateur | État |
| --- | --- |
| **Tranzak** | Écrit, **vérifié contre le bac à sable** |
| **Notch Pay** | Écrit, **vérifié contre le bac à sable** |
| **Tara** | Spécification indisponible |

Développer d'abord celui qui possède un bac à sable est ce qui distingue un adaptateur testé d'un adaptateur supposé correct. Le choix s'est justifié dès le premier appel.

**Chaque bac à sable a démenti deux hypothèses, et jamais les mêmes** :

| | Tranzak | Notch Pay |
| --- | --- | --- |
| Détection des refus | Classée par code HTTP — **la bascule était inerte** | Correcte d'emblée |
| Forme de la référence | Correcte d'emblée | Chaîne supposée, **objet en réalité** |
| Montants | Cherchés à la racine, **imbriqués dans `merchant`** | Scalaires supposés, **`fees` est un tableau** |
| Motif d'échec | `statusMessage` lu, `errorMessage` réel | Correct d'emblée |

Aucune de ces erreurs n'était visible en test unitaire : les fixtures reproduisaient les suppositions. C'est l'argument entier pour ne jamais mettre un adaptateur de paiement en production sans l'avoir exécuté contre un vrai environnement.

---

# 6. Ce que les deux agrégateurs ont en commun, et ce qui les sépare

| | Notch Pay | Tranzak |
| --- | --- | --- |
| Signalement d'un refus | **Code HTTP** (`422`, `401`) | **`success: false` dans le corps**, en `HTTP 200` |
| Nombre d'appels pour débiter | **Deux** — l'initialisation ne sollicite personne | Un seul |
| Temporisation basculable | **Oui à l'initialisation**, non au traitement | Jamais |
| Authentification des callbacks | **HMAC-SHA256** sur le corps brut | Secret partagé dans le corps |
| Corrélation marchande | `reference` → `trxref` | `mchTransactionRef` |
| Bac à sable | Non documenté | Oui |

Rien de tout cela n'est factorisable. C'est exactement pourquoi la traduction vit dans un adaptateur par agrégateur, et pourquoi elle est le premier sujet de tests de chacun : **c'est le seul endroit du module où une approximation coûte de l'argent réel à un tiers.**

---

# 6. Ce que chaque adaptateur doit obligatoirement déclarer

Un adaptateur qui ne répond pas à ces trois questions ne peut pas être mis en production :

1. **Quels statuts signifient « l'invite n'est jamais partie » ?** Liste fermée et explicite. Tout le reste vaut `prompted`.
2. **Quelles issues d'appel autorisent une bascule ?** Par défaut : aucune. Seules les erreurs d'authentification et de validation, jamais les temporisations.
3. **Comment retrouver une transaction à partir de notre `merchant_reference` ?** Sans cette capacité, un appel expiré reste à jamais irrésolu.

Ces trois points sont le premier sujet de tests de chaque adaptateur, avant le chemin nominal. C'est là, et nulle part ailleurs, qu'une erreur coûte de l'argent réel à un client.

---

# Le nom affiché au client

`description` est transmis à l'agrégateur, mais **ce n'est pas le nom qui
s'affiche sur l'invite Mobile Money**.

Ce nom est celui du marchand de référence auprès de l'opérateur. En passant par
un agrégateur, c'est le sien. Constaté en production : une invite Notch Pay
s'annonce `MAPLERAD LIMITED`, l'infrastructure sur laquelle il s'appuie.

Aucun paramètre du module n'agit dessus, et il n'y en aura pas : la question se
règle chez l'agrégateur, ou pas du tout.

**À demander aux deux :** peut-on, après validation KYB, provisionner un nom
marchand propre apparaissant sur l'invite ? Certains agrégateurs le font, la
documentation publique n'en parle pas.

Si aucun ne le permet, la seule voie est un compte marchand **direct** chez MTN
et Orange — ce que l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md)
a écarté, au prix de deux intégrations et de l'absence de repli. L'arbitrage se
reprend avec du volume, pas avant.
