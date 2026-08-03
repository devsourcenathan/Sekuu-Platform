# Récapitulatif — état de la plateforme

> **Dernière mise à jour :** Août 2026
> **Portée :** ce document décrit ce qui est **réellement implémenté**, par opposition aux spécifications qui décrivent la cible.

---

# 1. En une page

| | |
| --- | --- |
| Application | Monolithe modulaire Laravel 13, PHP 8.3, PostgreSQL 18 |
| Modules livrés | **Identity** (complet) · **Notify** (canal email) |
| Modules non démarrés | Verify, Billing, Storage, AI, Search, Analytics |
| Endpoints | 40 sous `/api/v1` + `/.well-known/jwks.json` |
| Migrations | 17 |
| Tests | 178, sur PostgreSQL |
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

Conséquence : un module ne formate jamais une erreur lui-même, et n'a rien à câbler pour exposer ses routes.

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

**Non implémenté** — canaux WhatsApp, push et interne ; API d'envoi (`POST /notifications`) ; gestion des templates par API ; liens de désabonnement ; envois groupés.

Le déclenchement se fait donc **uniquement par événement de domaine** pour l'instant. C'est suffisant pour tous les besoins actuels, puisque aucun produit externe n'existe encore.

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

Exploration manuelle : importer [`postman/Sekuu-Identity.postman_collection.json`](../postman/Sekuu-Identity.postman_collection.json) et l'environnement associé. Les jetons sont capturés automatiquement d'une requête à l'autre.

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

**Compléter Notify** : webhooks fournisseur (c'est ce qui alimente la liste de suppression), API d'envoi pour les produits externes, canal SMS.

Puis **Billing** — son contrat d'événements avec Identity est déjà défini — et Verify.

## 8.3 Dette identifiée

* Aucun endpoint de listing des rôles globaux — la collection Postman doit lire l'identifiant en base.
* `GET /users` et `PATCH /users/{id}` sont spécifiés mais pas implémentés.
* Pas de MFA, de passkeys, ni d'API keys — prévus au modèle, non développés.
* Pas de traductions : les messages sont en anglais dans le code, `Accept-Language` n'est pas encore exploité.
