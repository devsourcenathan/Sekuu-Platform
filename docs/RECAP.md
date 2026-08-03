# Récapitulatif — état de la plateforme

> **Dernière mise à jour :** Août 2026
> **Portée :** ce document décrit ce qui est **réellement implémenté**, par opposition aux spécifications qui décrivent la cible.

---

# 1. En une page

| | |
| --- | --- |
| Application | Monolithe modulaire Laravel 13, PHP 8.3, PostgreSQL 18 |
| Modules livrés | **Identity** (complet) · **Notify** (email, SMS, interne) · **Billing** (Tranzak + Notch Pay) |
| Modules non démarrés | Verify, Storage, AI, Search, Analytics |
| Endpoints | 85 sous `/api/v1` + `/.well-known/jwks.json` |
| Migrations | 26 |
| Tests | 437, sur PostgreSQL |
| Contrats | `Modules/*/openapi.yaml`, vérifiés par test |
| Collection de test | `postman/` |

---

# 2. Ce qui existe

## 2.1 Socle de plateforme

Dans `app/Platform/`, commun à tous les futurs modules :

| Élément | Rôle |
| --- | --- |
| `ApiResponse` | Enveloppe de réponse unique (`success` / `data` / `meta`) |
| `RequestId` + middleware | Identifiant de requête en corps, en en-tête et dans les logs |
| `DomainException` | Exception métier portant un code du catalogue |
| `ApiExceptionRenderer` | Traduit **toute** exception en réponse normalisée |
| `ModuleServiceProvider` | Socle de module : routes versionnées, sous-domaine, migrations, traductions |
| `DomainEvent` | Événement générique — le type est une chaîne, pas une classe : aucune dépendance de compilation entre modules |
| `IdentityContract` | Lecture synchrone d'Identity par les autres modules |
| `BillingContract` | Limites du plan courant d'une organisation |
| `QuotaGuard` | Refus d'une écriture dépassant le quota — le comptage reste au module appelant |

Conséquence : un module ne formate jamais une erreur lui-même, et n'a rien à câbler pour exposer ses routes.

Ces contrats sont le seul moyen dont dispose un module pour en interroger un autre — jamais son modèle Eloquent, jamais sa table. Le jour où l'un est extrait, seule l'implémentation change : l'appel local devient un appel HTTP, et les appelants ne sont pas modifiés.

## 2.2 Module Identity

**Authentification** — inscription, connexion, rotation des jetons, déconnexion (un appareil ou tous), profil.

**Contexte d'organisation** — création d'organisation, changement d'organisation active, rôles et permissions globaux.

**Workspaces** — création, modification, suppression, appartenance explicite.

**Invitations** — émission, consultation publique, acceptation avec création de compte, révocation.

**Mots de passe** — réinitialisation par lien, changement depuis le profil, historique des 5 derniers.

**Vérification d'adresse** — jeton à l'inscription, renvoi de lien.

**OAuth** — Google, Microsoft, GitHub via Socialite, derrière une interface `OAuthGateway`.

**Sessions** — liste des appareils connectés, révocation ciblée.

**Journal d'audit** — 24 actions, append-only, pagination par curseur.

## 2.3 Module Notify

**Implémenté** — pipeline d'envoi complet (déduplication, résolution, rendu, filtrage, mise en file, livraison), **canaux email et SMS**, diffusion multi-canal, 10 templates de plateforme traduits fr/en, préférences par catégorie, liste de suppression, **webhooks de retour de livraison**, consultation de l'historique.

**Branché sur Identity** — six événements produisent aujourd'hui de vrais messages : inscription, vérification d'adresse, réinitialisation, changement de mot de passe, invitation, création d'organisation.

**Fournisseurs** — email : **Resend** en premier rang, Postmark en bascule, mailer Laravel en dernier recours. SMS : passerelle locale en premier rang, Twilio en bascule. Webhooks : Resend (signature Svix), Postmark, passerelle locale (DLR SMS).

Un fournisseur non configuré n'est jamais essayé : en développement, Resend est ignoré et le mailer Laravel prend la main sans configuration particulière.

**API d'envoi** — `POST /notifications`, `/bulk` et `/{id}/cancel`, protégées par une **clé d'API** portant `notifications.send`. L'organisation vient de la clé, jamais du corps.

**Désabonnement par lien** — public, jeton signé sans expiration. Selon que le destinataire a un compte, l'effet est une préférence désactivée ou une suppression de la destination.

**Canal interne** — `/inbox`, sans aucun fournisseur externe : le repli qui reste disponible quand tout le reste échoue.

**Purge** — `php artisan notify:purge`, planifiée quotidiennement par le module, avec conservation d'un agrégat par jour, canal, catégorie et statut.

**Plafond de dépense** — contrôle mensuel par organisation sur les canaux facturés, et endpoint de consommation. C'est ce qui donne un usage au coût enregistré à chaque livraison.

**Templates par API** — `GET/POST /templates`, `GET/PATCH/DELETE /templates/{id}`, `POST /templates/{id}/preview`. Les templates de plateforme restent en lecture seule ; une organisation en crée des variantes qui prennent le pas.

La catégorie d'une variante est **héritée** du template de plateforme, et `transactional` est refusé sur une clé inédite : sans cette règle, une organisation requalifierait ses invitations en transactionnel et contournerait le désabonnement — l'[ADR-0006](04-decisions/adr-0006-transactional-vs-marketing.md) serait contournable par une simple requête.

`DELETE` archive au lieu de supprimer : des messages déjà envoyés référencent le template. La prévisualisation n'envoie ni n'enregistre rien.

**Suppressions par API** — `GET/POST /suppressions`, `DELETE /suppressions/{id}`. La liste est globale à la plateforme, et le `DELETE` est journalisé : réhabiliter une adresse qui rebondit dégrade la réputation de tout le domaine.

C'est aussi le seul recours contre un faux positif de fournisseur, qui bloquait jusqu'ici définitivement une adresse valide — y compris son lien de réinitialisation — sans autre issue qu'une requête SQL.

**Non implémenté** — canaux WhatsApp et push.

## 2.4 Module Billing

**Implémenté** — catalogue de plans, abonnements prépayés, factures numérotées avec TVA figée, paiements Mobile Money via **Notch Pay puis Tranzak**, registre append-only, callbacks et réconciliation par sondage.

**Ce qui alimente enfin `organization_products`.** Cette table existait, était lue à chaque requête, et se modifiait à la main. Identity consomme désormais les événements de Billing et applique un **état cible** — jamais un delta, puisqu'un même événement peut être livré deux fois.

Le consommateur ne touche jamais les lignes `source = 'manual'` : une activation commerciale accordée par un humain ne se révoque pas au motif qu'aucun abonnement ne la justifie.

**Le modèle est prépayé** ([ADR-0007](04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)) : il n'existe aucun moyen technique de prélever un client en Mobile Money. Le renouvellement est un acte volontaire, précédé de rappels à J-7, J-3 et J-1, suivi d'une grâce de 7 jours puis d'une suspension — jamais d'une suppression.

**La bascule entre agrégateurs est volontairement étroite** ([ADR-0008](04-decisions/adr-0008-payment-aggregators-failover.md)) : on ne réessaie ailleurs que si l'invite n'est jamais partie sur le téléphone du client. Une temporisation compte comme « invite partie ». Ne pas encaisser est un incident réparable ; encaisser deux fois est une faute que le client découvre sur son relevé.

**Le sondage n'est pas optionnel.** `billing:reconcile` interroge les agrégateurs toutes les 5 minutes. Un callback perdu retarde une confirmation, il ne la fait pas disparaître — sans quoi un client peut être débité sans obtenir son accès.

**Le montant d'un callback n'est jamais cru** : le statut est relu chez l'agrégateur. Notch Pay signe en HMAC-SHA256 sur le corps brut ; Tranzak se contente d'un `authKey` transporté dans le corps, ce qui prouve que l'émetteur connaît le secret mais rien sur l'intégrité du corps.

**Les deux adaptateurs ont été exécutés contre leur bac à sable**, et chacun a démenti deux hypothèses — jamais les mêmes. Le détail est dans [05-providers.md](03-services/billing/05-providers.md) ; le résumé est qu'aucune de ces erreurs n'était visible en test unitaire, puisque les fixtures reproduisaient les suppositions.

**Ce qui a changé ailleurs** — la table `products` d'Identity n'était seedée nulle part ; elle l'est désormais (6 produits). Sans elle, aucun plan n'avait rien à ouvrir.

**Non implémenté** — Tara (aucune documentation publique), PDF de facture (appartient à Storage, renvoie `503`), facturation à l'usage.

**Branché sur Notify** — huit événements produisent de vrais messages : activation, renouvellement, rappels d'échéance, entrée en grâce, suspension, facture émise, facture réglée, paiement échoué. Tous transactionnels ; trois portent aussi un SMS, aux seuls moments où une action du client est attendue.

Billing ne connaissant ni utilisateurs ni adresses, il obtient le destinataire d'Identity par son **contrat public** — premier usage de la couche `app/Platform/Contracts/`, et le cas exact pour lequel elle était prévue.

**Quotas appliqués** — sièges et workspaces côté Identity, volume de SMS côté Notify. Billing publie la limite, chaque module compte sa ressource. Une limite a **trois** états — plafonnée, illimitée, non couverte — et une organisation sans abonnement n'est pas bloquée : un quota borne un usage autorisé, il ne décide pas de l'autorisation.

Le plafond de dépense de Notify n'est pas supprimé pour autant. Il était un substitut aux quotas par plan ; il redevient ce qu'il aurait dû être d'emblée, un garde-fou absolu contre une boucle ou une clé fuitée — sans lui, une organisation au plan illimité n'aurait plus aucune borne.

**Callbacks vérifiés chez les deux agrégateurs** — chaîne complète éprouvée à travers un tunnel public : authentification, déduplication, rattachement à la tentative, facture réglée, registre écrit.

Trois enseignements que seuls de vrais callbacks pouvaient donner.

Chez Notch Pay, le corps porte `event` et un `id` de premier niveau, là où la documentation annonce `type` et `data.id` — la déduplication retombait donc sur une empreinte du corps, ce qui marchait par accident mais aurait laissé passer deux fois un renvoi.

Un paiement produit **trois** livraisons dans un ordre variable : croire le statut annoncé aurait fait régresser un paiement encaissé vers « en attente ». La règle « le corps ne décide jamais de l'issue » s'est justifiée en conditions réelles.

Les deux agrégateurs n'utilisent **pas la même clé de déduplication**, et c'est délibéré. Notch Pay signe ses callbacks : son identifiant de livraison suffit. Tranzak n'authentifie que par un secret dans le corps, donc rejouable avec un identifiant forgé — sa clé ne dépend que du fait rapporté.

Le paiement Tranzak a produit la ligne `fee −3 XAF` attendue : la séparation brut / net est éprouvée contre du réel, ce que le bac à sable de Notch Pay ne permet pas.

---

# 3. Décisions structurantes

Les quatre ADR de [`04-decisions/`](04-decisions/) portent les décisions d'architecture. En complément, voici les arbitrages pris pendant l'implémentation.

## 3.1 Frontières entre domaines

| Sujet | Propriétaire | Ce qu'Identity fait |
| --- | --- | --- |
| Plans, abonnements, paiements | **Billing** | Consomme des événements, maintient `organization_products` comme cache de droits |
| Envoi de messages | **Notify** | Publiera des événements ; n'envoie rien |
| Permissions métier | **Chaque produit** | Ne les connaît pas |

## 3.2 Authentification

* Access token JWT **RS256**, 15 minutes. Les consommateurs ne détiennent que la clé publique : aucun produit ne peut forger un token.
* Refresh token opaque, 30 jours, stocké haché, **rotation à chaque usage** avec détection de rejeu.
* Le token ne porte **aucune donnée personnelle** — uniquement des identifiants, rôles et scopes globaux.
* Un token sans claim `org` n'ouvre que les routes de profil.

## 3.3 Isolation entre organisations

La règle appliquée partout : **une ressource hors périmètre renvoie `404`, jamais `403`** — un `403` confirmerait son existence.

Seule exception délibérée : un workspace de sa **propre** organisation dont on n'est pas membre renvoie `403 WORKSPACE_ACCESS_DENIED`. Entre collègues, l'existence n'est pas un secret.

L'organisation provient **toujours** du token. Lorsqu'elle apparaît aussi dans l'URL, les deux doivent correspondre : une URL ne peut jamais élargir la portée d'un token.

---

# 4. Écarts assumés par rapport aux spécifications

Ces points divergent des documents de spécification. Ils y sont signalés, et sont rappelés ici pour éviter qu'ils ne se perdent.

| Écart | Raison | Réversible ? |
| --- | --- | --- |
| Table `user_sessions`, pas `sessions` | Laravel réserve `sessions` à son driver de session web ; la collision aurait été silencieuse | Non — renommage coûteux |
| Révocation via la base, pas Redis | Redis pas déployé. Sémantique identique, révocation même immédiate, mais une lecture par requête | Oui — bascule prévue |
| Refresh token en cookie **et** dans le corps | Aucun signal fiable ne distingue un client web d'un client natif | Oui — nécessite d'identifier les clients |
| Réinitialisation marque l'adresse vérifiée | Recevoir le lien prouve la maîtrise de la boîte, comme pour une invitation | Oui |
| Jetons exposés en réponse API | Les messages partent désormais réellement ; l'exposition ne subsiste que par **confort de développement**, limitée à `local` et `testing` | Oui — supprimable dès qu'une boîte de test est en place |

---

# 5. Ce qui est garanti par des tests

Les tests ne couvrent pas seulement le chemin nominal ; ils verrouillent les propriétés qui, si elles régressaient, ne se verraient pas.

**Conventions d'API** — enveloppe standard, `request_id` en corps et en en-tête, `404` sur route inconnue, identifiant client rejeté s'il est malformé.

**Non-énumération des comptes** — email inconnu et mot de passe faux renvoient un code **et un message identiques** ; `forgot-password` répond `202` dans tous les cas, y compris pour un compte suspendu.

**Contenu du token** — le payload est décodé et vérifié : ni email, ni nom, ni permission métier.

**Isolation** — pour chaque ressource, un token de l'organisation A obtient `404` sur une ressource de B. Vérifié sur `GET`, `PATCH`, `DELETE` et les sous-ressources.

**Rotation et vol de jeton** — rejouer un refresh token révoque toute la session, y compris le jeton légitime.

**Journal d'audit** — immuable (`update` et `delete` lèvent une exception), filtrage récursif des secrets vérifié sur l'ensemble des entrées d'un scénario complet.

**Schéma PostgreSQL** — `citext` (unicité insensible à la casse), contrainte `CHECK` sur `status`, index uniques partiels (un compte supprimé libère son adresse).

**Contrat OpenAPI** — parité exacte avec les routes réelles, références résolues, codes d'erreur présents au catalogue.

---

# 6. Deux bugs que les tests ont révélés

Ils méritent d'être mentionnés parce qu'ils étaient invisibles à la lecture.

**La révocation anti-vol était annulée par le rollback.** À la détection d'un rejeu de refresh token, la session était révoquée *à l'intérieur* de la transaction, puis l'exception provoquait un rollback qui effaçait la révocation. Le vol restait donc sans conséquence.

**La clé étrangère auto-référencée ne se créait pas sur PostgreSQL.** Laravel émet les contraintes `FOREIGN KEY` avant la `PRIMARY KEY` ; Postgres refusait l'auto-référence. SQLite laissait passer, ce qui est précisément l'argument pour tester sur le moteur de production.

---

# 7. Utiliser l'API

## 7.1 Démarrer

```bash
composer install && cp .env.example .env && php artisan key:generate
```

```bash
php artisan migrate && php artisan serve
```

## 7.2 Tester

Suite automatisée (PostgreSQL requis, base `sekuu_testing`) :

```bash
php artisan test
```

Exploration manuelle : importer [`postman/Sekuu-Platform.postman_collection.json`](../postman/Sekuu-Platform.postman_collection.json) et l'environnement associé. 77 requêtes couvrant les 55 routes des deux modules ; les jetons sont capturés automatiquement d'une requête à l'autre.

## 7.3 Parcours minimal

```text
POST /auth/register              →  access_token
POST /organizations              →  organization_id
POST /auth/switch-organization   →  access_token contextualisé
POST /workspaces                 →  workspace_id
GET  /audit-logs                 →  trace des quatre étapes
```

---

# 8. Ce qui reste

## 8.1 Bloquant pour la production

* **La mise en service du domaine expéditeur** — `sekuu.com` doit être vérifié chez Resend (DKIM, Return-Path, DMARC), puis `RESEND_API_KEY` et `RESEND_WEBHOOK_SECRET` renseignés. Sans cela les messages partent par le mailer Laravel, qui ne rapporte aucun rebond : le service paraît fonctionner tout en accumulant une dette de délivrabilité invisible. Procédure dans [Notify § 8.2](03-services/notify/01-overview.md).
* **Redis** — pour les queues, le cache et la liste de révocation. Les envois passent aujourd'hui par la file `database`.
* **Clés de signature** en gestionnaire de secrets, et procédure de rotation à 90 jours.
* **CI** — la suite existe, rien ne l'exécute automatiquement.

## 8.2 Prochaines étapes

Par ordre décroissant de valeur :

* **Les callbacks, réellement reçus.** Dernière branche du chemin de paiement jamais éprouvée contre du réel ; suppose une URL publique.
* **Les comptes marchands de production**, Notch Pay et Tranzak. Administratif et long, à engager en parallèle.
* **La documentation Tara**, à réclamer directement — elle n'est pas publique.

Puis Verify, et Storage — dont dépend le PDF de facture, qui renvoie `503` aujourd'hui.

Le canal WhatsApp reste le plus attendu au Cameroun ; il suppose un compte Business vérifié et des modèles approuvés par Meta, donc un délai externe qu'il vaut mieux engager tôt.

## 8.3 Dette identifiée

* Aucun endpoint de listing des rôles globaux — la collection Postman doit lire l'identifiant en base.
* `GET /users` et `PATCH /users/{id}` sont spécifiés mais pas implémentés.
* Pas de MFA ni de passkeys — prévus au modèle, non développés.
* Le plafond de dépense est global : le même pour toutes les organisations. Des quotas par plan viendront avec Billing.
* Internationalisation limitée à `en` et `fr`. Ajouter une langue suppose de traduire les 93 clés et les 10 templates de Notify ; un test échoue tant qu'une clé manque.
