# Sécurité, authentification et tokens

> **Version :** 1.0
> **Statut :** Standard applicable
> **Dernière mise à jour :** Août 2026

Ce document définit le fonctionnement de l'authentification dans tout l'écosystème Sekuu : format des tokens, durées de vie, rotation des clés, révocation, isolation des données.

Il s'applique à Sekuu Identity (émetteur) et à tous les consommateurs — modules de la plateforme et produits SaaS.

Documents liés : [API Guidelines](api-guidelines.md) · [Sekuu Identity](../03-services/identity/01-overview.md) · [ADR-0004](../04-decisions/adr-0004-jwt-stateless-tokens.md)

---

# 1. Principe

**Sekuu Identity est le seul émetteur de tokens de l'écosystème.**

Aucun produit ne gère de mot de passe, ne signe de token, ni ne maintient sa propre table d'utilisateurs.

```text
Utilisateur  ──►  Identity  ──►  access_token (JWT)  ──►  Produit / Module
```

Les produits **vérifient** les tokens, ils ne les émettent jamais.

---

# 2. Types de tokens

| Type | Format | Durée de vie | Usage |
| --- | --- | --- | --- |
| **Access token** | JWT signé (RS256) | **15 minutes** | Toutes les requêtes API |
| **Refresh token** | Chaîne opaque aléatoire (256 bits) | **30 jours** | Obtenir un nouvel access token |
| **API key** | Chaîne opaque préfixée `sk_live_` / `sk_test_` | Illimitée jusqu'à révocation | Intégrations serveur à serveur |
| **Jeton d'action** | Chaîne opaque à usage unique | 1 h (reset), 7 j (invitation) | Réinitialisation, vérification email, invitation |

L'access token est **stateless** : il est vérifié par signature, sans appel à Identity.
Tous les autres jetons sont **stateful** : ils sont stockés hachés et vérifiés en base.

---

# 3. Access token (JWT)

## 3.1 Claims

```json
{
  "iss": "https://identity.sekuu.com",
  "aud": ["clinicflow", "sekuu-platform"],
  "sub": "550e8400-e29b-41d4-a716-446655440000",
  "sid": "9f1c2b7a-4d5e-4f6a-8b9c-0d1e2f3a4b5c",
  "org": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "ws": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "roles": ["owner"],
  "scopes": ["organization.manage", "users.invite"],
  "products": ["clinicflow", "sekuu-learn"],
  "lang": "fr",
  "iat": 1754225400,
  "exp": 1754226300,
  "jti": "8b94d7d0-1a2b-3c4d-5e6f-7a8b9c0d1e2f"
}
```

| Claim | Rôle |
| --- | --- |
| `iss` | Émetteur — toujours `https://identity.sekuu.com` |
| `aud` | Destinataires autorisés. Un produit **doit** rejeter un token dont il n'est pas destinataire |
| `sub` | UUID de l'utilisateur |
| `sid` | UUID de la session — permet la révocation ciblée d'un appareil |
| `org` | **Organisation active**. Absent tant que l'utilisateur n'a pas choisi d'organisation |
| `ws` | Workspace actif, optionnel |
| `roles` | Rôles **globaux** dans l'organisation active (plateforme uniquement) |
| `scopes` | Permissions globales dérivées des rôles |
| `products` | Produits actifs pour l'organisation, au moment de l'émission |
| `lang` | Langue préférée, utilisée par défaut pour `Accept-Language` |
| `jti` | Identifiant unique du token, utilisé par la liste de révocation |

## 3.2 Ce que le token ne contient jamais

* Les permissions **métier** d'un produit (`patient.create`, `dealer.edit`…). Elles sont résolues par le produit, à partir de sa propre base — voir [ADR-0003](../04-decisions/adr-0003-two-level-roles.md).
* Des données personnelles au-delà de l'identifiant : ni email, ni téléphone, ni nom.
* Un secret, une clé, ou une donnée non destinée au client — **un JWT est signé, pas chiffré**. N'importe qui peut le décoder.

## 3.3 Vérification par le consommateur

Un consommateur doit, dans cet ordre :

1. Vérifier la signature avec la clé publique correspondant au `kid` de l'en-tête (section 5).
2. Vérifier `iss`.
3. Vérifier que son propre identifiant figure dans `aud`.
4. Vérifier `exp` et `nbf`, avec une tolérance d'horloge maximale de 60 secondes.
5. Vérifier que `org` correspond bien à l'organisation de la ressource demandée (section 8).

Un token qui échoue à l'une de ces étapes produit `401` / `INVALID_TOKEN`.

---

# 4. Organisation active et changement de contexte

Un utilisateur peut appartenir à plusieurs organisations. Le token n'en porte **qu'une seule** à la fois.

```text
POST /api/v1/auth/login
   → access_token sans claim `org`
   → la réponse liste les organisations de l'utilisateur

POST /api/v1/auth/switch-organization  { "organization_id": "..." }
   → nouvel access_token portant `org`, `roles`, `scopes`, `products`
```

Règles :

* Un token sans `org` ne donne accès qu'aux routes de profil (`/auth/me`, `/organizations`).
* Changer d'organisation **n'invalide pas** le refresh token : la session reste la même, seul le contexte change.
* Un token émis pour l'organisation A ne peut jamais lire une ressource de l'organisation B, même si l'utilisateur est membre des deux.

---

# 5. Rotation des clés de signature

Identity signe en **RS256** et publie ses clés publiques :

```text
GET https://identity.sekuu.com/.well-known/jwks.json
```

* Chaque clé porte un `kid` ; l'en-tête du JWT indique le `kid` utilisé.
* Les consommateurs mettent le JWKS en cache **1 heure**, et le rafraîchissent immédiatement s'ils rencontrent un `kid` inconnu.
* Rotation planifiée : tous les **90 jours**.
* L'ancienne clé reste publiée **24 heures** après la rotation, le temps que les derniers tokens émis expirent.
* En cas de compromission, la clé est retirée immédiatement du JWKS et **toutes** les sessions sont révoquées.

La clé privée n'existe que dans Identity, injectée par variable d'environnement ou gestionnaire de secrets. Elle n'est jamais versionnée.

---

# 6. Refresh tokens

* Générés aléatoirement sur 256 bits, jamais dérivés de l'utilisateur.
* Stockés **hachés en SHA-256**. La base ne contient jamais la valeur en clair.
* **Rotation à chaque usage** : `/auth/refresh` révoque le token présenté et en émet un nouveau.
* **Détection de rejeu** : si un token déjà révoqué est présenté, toute la famille de tokens de la session est révoquée et un événement de sécurité est journalisé. C'est le signe d'un vol de token.
* Liés à une session (`sessions.id`), donc à un appareil.

---

# 7. Révocation

L'access token étant stateless, sa révocation repose sur deux mécanismes complémentaires.

## 7.1 Durée de vie courte

15 minutes : c'est la fenêtre maximale pendant laquelle un token révoqué reste techniquement valide pour un consommateur qui ne consulte pas la liste de révocation.

## 7.2 Liste de révocation

Identity maintient dans Redis une liste des `sid` et `jti` révoqués, avec un TTL égal à la durée de vie restante du token.

```text
GET /api/v1/auth/revocations?since=1754225400
```

Les modules de la plateforme consultent Redis directement. Les produits externes interrogent cet endpoint toutes les 60 secondes.

> **État de l'implémentation.** Tant que Redis n'est pas déployé, la table
> `user_sessions` tient ce rôle : chaque requête authentifiée vérifie que la
> session portée par le claim `sid` n'est ni révoquée ni expirée. La sémantique
> est identique — et la révocation est même immédiate plutôt que différée de
> 60 secondes — mais elle coûte une lecture par requête, ce qui n'est
> soutenable que parce que la plateforme est encore un monolithe. Le passage à
> Redis devient obligatoire à la première extraction d'un module.

## 7.3 Événements déclenchant une révocation

| Événement | Portée |
| --- | --- |
| Déconnexion | La session courante |
| Déconnexion de tous les appareils | Toutes les sessions de l'utilisateur |
| Changement de mot de passe | Toutes les sessions, sauf celle en cours |
| Réinitialisation de mot de passe | Toutes les sessions, sans exception |
| Suspension du compte | Toutes les sessions |
| Retrait d'un membre d'une organisation | Les tokens portant cette `org` |
| Suspension de l'organisation | Tous les tokens portant cette `org` |
| Compromission d'une clé | Toutes les sessions de l'écosystème |

---

# 8. Isolation multi-tenant

C'est la garantie la plus critique de la plateforme : **une organisation ne doit jamais voir les données d'une autre**.

## 8.1 Règles

1. Toute table contenant des données rattachées à un tenant porte une colonne `organization_id` **non nullable**, indexée.
2. L'`organization_id` provient **exclusivement** du claim `org` du token. Il n'est jamais lu depuis le corps de la requête, un paramètre d'URL ou un header.
3. Un filtre global (Laravel global scope) applique la contrainte sur toutes les requêtes. L'oublier doit être impossible par construction, pas par discipline.
4. Toute requête écrite à la main (`DB::raw`, rapport, export) doit être revue explicitement pour ce point.
5. Les identifiants sont des UUID v4 : deviner l'identifiant d'une ressource voisine est impossible.
6. Une ressource appartenant à une autre organisation renvoie `404` / `RESOURCE_NOT_FOUND`, jamais `403` — un `403` confirmerait son existence.

## 8.2 Tests obligatoires

Chaque module fournit, pour chaque ressource exposée, un test vérifiant qu'un token de l'organisation A obtient bien `404` sur une ressource de l'organisation B.

---

# 9. Mots de passe

* Hachage **Argon2id** (repli bcrypt coût 12 si indisponible).
* Longueur minimale **12 caractères**. Pas de règle de composition imposée — elle produit des mots de passe prévisibles.
* Vérification contre une liste de mots de passe compromis. En cas de correspondance : `422` / `PASSWORD_TOO_WEAK`.
* Les 5 derniers hachages sont conservés pour empêcher la réutilisation immédiate.
* Limitation : 10 tentatives par minute et par IP, puis verrouillage progressif du compte.
* La réponse à `/auth/login` ne distingue jamais « email inconnu » de « mot de passe incorrect » : `INVALID_CREDENTIALS` dans les deux cas, avec un temps de réponse constant.
* La réponse à `/auth/forgot-password` est identique que l'email existe ou non.

---

# 10. API keys

Destinées aux intégrations serveur à serveur, sans utilisateur.

* Format : `sk_live_` ou `sk_test_` suivi de 32 octets aléatoires encodés.
* Affichées **une seule fois** à la création. Stockées hachées en SHA-256.
* Rattachées à une organisation, avec une liste de scopes explicite.
* Rotation recommandée tous les 12 mois ; date de dernière utilisation exposée pour repérer les clés dormantes.
* Une clé détectée dans un dépôt public est révoquée automatiquement.

Les API keys ne doivent jamais être utilisées depuis un client web ou mobile.

---

# 11. Transport et en-têtes

* **HTTPS obligatoire.** HTTP est refusé, jamais redirigé.
* TLS 1.2 minimum, TLS 1.3 recommandé.
* `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
* `X-Content-Type-Options: nosniff`
* `X-Frame-Options: DENY`
* `Referrer-Policy: strict-origin-when-cross-origin`
* CORS : liste blanche explicite d'origines par produit. Jamais `*` sur une route authentifiée.

## 11.1 Stockage des tokens côté client

| Client | Access token | Refresh token |
| --- | --- | --- |
| Web | Mémoire uniquement | Cookie `HttpOnly` + `Secure` + `SameSite=Lax` |
| Mobile | Mémoire uniquement | Keychain (iOS) / Keystore (Android) |
| Serveur | Mémoire ou cache chiffré | Gestionnaire de secrets |

Jamais de token dans `localStorage`, ni dans une URL, ni dans un log.

> **État de l'implémentation.** `/auth/login`, `/auth/register` et
> `/auth/refresh` posent le refresh token en cookie `HttpOnly` **et** le
> renvoient dans le corps, faute de signal fiable permettant de distinguer un
> client web d'un client natif. Un client web doit donc ignorer le champ
> `refresh_token` du corps et s'appuyer uniquement sur le cookie — que
> `/auth/refresh` lit en priorité. Restreindre le corps aux clients natifs
> suppose de les identifier explicitement (en-tête dédié ou client_id) ; c'est
> la prochaine évolution prévue sur ce point.

---

# 12. Journalisation et audit

* Toute action sensible est écrite dans `audit_logs` : connexion, échec de connexion, changement de mot de passe, création ou suppression d'organisation, invitation, changement de rôle, révocation de session, création d'API key.
* Les logs applicatifs ne contiennent **jamais** : mot de passe, token, refresh token, API key, secret de webhook, numéro de pièce d'identité.
* Le `request_id` relie une entrée de log, une réponse API et une trace distribuée.
* Rétention des `audit_logs` : **24 mois**.

---

# 13. Données personnelles

* Les documents d'identité collectés par Verify sont chiffrés au repos et supprimés dès que la vérification est finalisée et sa décision archivée.
* La suppression d'un compte est **différée de 30 jours**, puis exécutée : anonymisation des `audit_logs` (l'action est conservée, l'identité est effacée) et suppression des données personnelles.
* Un export des données personnelles d'un utilisateur est disponible à la demande.
* La résidence des données suit la juridiction de l'organisation lorsque la réglementation l'impose.

---

# 14. Évolutions prévues

Le modèle est conçu pour accueillir sans rupture :

* MFA / 2FA (TOTP puis SMS) — le claim `amr` sera ajouté au JWT ;
* Passkeys (WebAuthn) ;
* SSO d'entreprise (SAML, OIDC) et provisionnement SCIM ;
* Politiques de sécurité par organisation (durée de session, MFA obligatoire, domaines email autorisés) ;
* Scopes délégués pour les intégrations tierces (OAuth 2.0 authorization code).
