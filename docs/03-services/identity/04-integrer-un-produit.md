# Intégrer un produit à Sekuu

> **Public :** l'équipe qui construit un produit de l'écosystème — DealerOS,
> Sekuu Stock, ClinicFlow.
> **Statut :** Guide d'intégration. Fait autorité sur ce qu'un produit doit
> implémenter.
> **Dernière mise à jour :** Août 2026

Ce document décrit l'intégration d'un produit **maison**, c'est-à-dire édité par
Sekuu. Un service tiers, qui ne partage ni la marque ni l'éditeur, passe par les
clés d'API et les documents `07-external-api.md` de chaque module — le modèle est
différent, et volontairement plus fermé.

---

# 1. Ce que Sekuu vous donne, et ce qu'il ne fait pas

| Sekuu s'occupe de | Vous vous occupez de |
| --- | --- |
| Comptes, mots de passe, sessions, rotation | Vos données métier |
| Organisations, membres, invitations, rôles | Ce que chaque rôle autorise **chez vous** |
| Abonnements, factures, encaissement | Rien — vous ne facturez pas |
| Quotas publiés par plan | Compter **votre** ressource si vous en plafonnez une |
| Emails et SMS, fichiers, IA | Les appeler quand vous en avez besoin |

**Vous n'aurez pas de table `users`.** C'est le point de départ, et tout le reste
en découle.

---

# 2. Vérifier un jeton

## 2.1 Hors ligne, toujours

Sekuu publie ses clés de signature :

```
https://platform.sekuu.com/.well-known/jwks.json
```

Vous les récupérez, vous les mettez en cache, et vous vérifiez chaque jeton
**sans appeler Sekuu**. Un produit qui interrogerait Sekuu à chaque requête
ajouterait un aller-retour réseau à chaque page, et tomberait avec lui.

Quatre contrôles, aucun facultatif :

| | |
| --- | --- |
| Signature | `RS256`, contre la clé dont le `kid` figure dans l'en-tête |
| `iss` | `https://identity.sekuu.com` |
| `aud` | contient `sekuu-platform` |
| `exp` | non dépassé |

Vérifier la signature sans vérifier `aud` laisserait entrer un jeton émis pour
un autre destinataire — signé par la même clé, donc valide en apparence.

## 2.2 Ce que le jeton porte

```json
{
  "iss": "https://identity.sekuu.com",
  "aud": ["sekuu-platform"],
  "sub": "019fd4a1-…",
  "sid": "019fd4a2-…",
  "lang": "fr",
  "org": "019fd4b0-…",
  "roles": ["owner"],
  "scopes": ["organization.manage", "users.invite", "…"],
  "products": ["dealeros", "stock"],
  "exp": 1785312000
}
```

| Claim | Toujours là | Ce que vous en faites |
| --- | --- | --- |
| `sub` | oui | Votre identifiant d'utilisateur. **Ne le dupliquez pas** dans une table à vous. |
| `sid` | oui | La session Sekuu. Utile pour journaliser, jamais pour autoriser. |
| `lang` | oui | La langue de vos réponses, si vous en avez plusieurs. |
| `org` | **non** | Le client. Votre frontière d'isolation. |
| `roles` | non | `owner`, `admin`, `billing_manager`, `member`. |
| `scopes` | non | Permissions de plateforme — voir §5. |
| `products` | non | Ce à quoi l'organisation a droit. |

**Les quatre derniers n'apparaissent que si une organisation est active.** Un
jeton sans `org` n'ouvre, chez Sekuu, que les routes de profil. Chez vous, il ne
doit rien ouvrir du tout.

## 2.3 En PHP

```php
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

$jwks = Cache::remember('sekuu.jwks', now()->addHour(), fn () => Http::get(
    'https://platform.sekuu.com/.well-known/jwks.json'
)->json());

$claims = (array) JWT::decode($token, JWK::parseKeySet($jwks));

abort_unless($claims['iss'] === 'https://identity.sekuu.com', 401);
abort_unless(in_array('sekuu-platform', (array) $claims['aud'], true), 401);
abort_unless(isset($claims['org']), 403);
```

Le cache d'une heure est un compromis : assez long pour ne pas peser, assez
court pour qu'une rotation de clé se propage sans intervention. Prévoyez une
relecture immédiate si un `kid` inconnu se présente — c'est le signe d'une
rotation, pas d'une attaque.

---

# 3. Le contrôle d'abonnement tient en une ligne

```php
abort_unless(in_array('dealeros', (array) ($claims['products'] ?? []), true), 403);
```

**C'est tout.** Vous ne parlez jamais à Billing, vous ne connaissez ni plan, ni
facture, ni échéance. Un abonnement suspendu se traduit par un claim absent au
prochain jeton.

## 3.1 La latence de révocation est de quinze minutes

Un jeton d'accès vit 900 secondes. Un abonnement suspendu à 10 h 00 laisse donc
entrer jusqu'à 10 h 15, avec les jetons déjà émis.

C'est assumé, et c'est le prix d'une vérification hors ligne. Ne l'allongez pas :
la durée de vie du jeton **est** votre fenêtre d'exposition, pour une suspension
comme pour un vol.

Si une opération chez vous est irréversible ou coûteuse — un export massif, une
suppression en masse — relisez l'état auprès de Sekuu à ce moment-là plutôt que
de vous fier au claim.

---

# 4. Cloisonner sur `org`, et jamais sur autre chose

Toutes vos tables portent `organization_id`, et sa valeur vient **du jeton**.

```php
Vehicle::where('organization_id', $claims['org'])->get();
```

Jamais du corps de la requête, jamais d'un paramètre d'URL, jamais d'un en-tête.
Un identifiant d'organisation fourni par l'appelant est un identifiant qu'il peut
changer.

Le test à écrire dès le premier jour, avant la première fonctionnalité : **un
jeton de l'organisation A obtient `404` sur une ressource de B.** Sur `GET`, sur
`PATCH`, sur `DELETE`, et sur les sous-ressources. C'est la règle appliquée à
chaque module de la plateforme, et celle dont la régression ne se voit pas.

`404`, pas `403` : distinguer les deux dirait à qui essaie des identifiants au
hasard lesquels existent.

---

# 5. Les rôles sont à vous d'interpréter

Sekuu vous dit qu'un utilisateur est `admin` de son organisation. **Il ne dit pas
ce qu'un `admin` peut faire chez vous** — c'est votre métier, pas le sien.

| Rôle | Ce qu'il signifie côté Sekuu |
| --- | --- |
| `owner` | A créé l'organisation, ou l'a reçue. Il en reste toujours un. |
| `admin` | Administre l'organisation, sans la facturation |
| `billing_manager` | Abonnement et factures |
| `member` | Appartient, sans droit d'administration |

Les `scopes` sont des permissions **de plateforme** — `organization.manage`,
`users.invite`, `subscription.manage`, `products.install`, `audit.read`. Elles
gouvernent Sekuu, pas vous. Ne les réutilisez pas pour vos propres droits : le
jour où Sekuu en ajoutera une, votre autorisation changerait sans que vous
l'ayez décidé.

Faites votre propre table de correspondance, explicite :

```php
private const CAN_DELETE_VEHICLE = ['owner', 'admin'];
```

---

# 6. La connexion

## 6.1 L'état actuel, dit franchement

**Il n'existe pas de « Se connecter avec Sekuu ».** Identity n'est pas encore un
fournisseur d'identité au sens OAuth : il n'y a ni point d'autorisation, ni
consentement, ni jeton délivré à une application.

Votre interface appelle donc directement les routes de Sekuu :

| | |
| --- | --- |
| `POST /api/v1/auth/login` | Rend un jeton d'accès et un jeton de rafraîchissement |
| `POST /api/v1/auth/switch-organization` | **Obligatoire** — voir §7 |
| `POST /api/v1/auth/refresh` | Avant l'expiration des 15 minutes |
| `POST /api/v1/auth/logout` | Cet appareil · `logout-all` pour tous |

Cela veut dire que votre interface **voit le mot de passe** de l'utilisateur.
C'est acceptable entre produits du même éditeur, sous la même marque. **Ce ne
l'est pas** pour un produit que Sekuu n'édite pas, et c'est précisément la
raison pour laquelle le flux délégué existe — voir
[l'analyse](../../05-analyses/identity-comme-fournisseur.md).

Ne construisez rien qui rendrait difficile de basculer plus tard : gardez
l'appel de connexion isolé dans un seul module de votre code.

## 6.2 Le rafraîchissement, et le vol

Un jeton de rafraîchissement **ne se rejoue pas**. Le rejouer révoque la session
entière, y compris le jeton légitime — c'est la détection de vol, et elle est
volontairement brutale.

Conséquence pratique : si deux onglets rafraîchissent en même temps, l'un des
deux déconnecte l'utilisateur. Sérialisez vos rafraîchissements.

---

# 7. Le piège numéro un

**Un jeton fraîchement obtenu par `login` ne porte pas d'organisation.**

Il faut appeler `switch-organization`, qui rend un **nouveau** jeton — c'est
celui-là qui porte `org`, `roles`, `products`.

Sans cette étape, votre produit voit un jeton valide, signé, non expiré, et
refuse tout. Le développeur cherchera un bug d'autorisation pendant une heure.

```
login  →  jeton sans org  →  /auth/me pour lister les organisations
       →  switch-organization  →  jeton utilisable
```

Si l'utilisateur n'a qu'une organisation, enchaînez les deux appels sans rien lui
demander. S'il en a plusieurs, c'est un choix à lui présenter — et à conserver
d'une session à l'autre.

---

# 8. Appeler les autres modules

Pour ce que votre **serveur** fait de sa propre initiative — un rappel de
rendez-vous à 8 h, un PDF, un résumé — le jeton de l'utilisateur n'existe pas.
Il vous faut une clé d'API, émise pour votre organisation.

| Besoin | Scope | Document |
| --- | --- | --- |
| Envoyer email/SMS | `notifications.send` | [notify/03-api.md](../notify/03-api.md) |
| Stocker un fichier | `storage.write`, `storage.read` | [storage/07-external-api.md](../storage/07-external-api.md) |
| Exécuter une tâche d'IA | `ai.run`, `ai.read` | [ai/07-external-api.md](../ai/07-external-api.md) |

Deux règles pour les demander :

**Le minimum.** Une clé qui envoie des SMS n'a pas besoin de lire des fichiers.

**Un périmètre.** Storage exige `subject_types` — les types d'objets que la clé
peut manipuler, `dealeros.vehicule` par exemple. AI exige `ai_tasks` — la liste
blanche des tâches. Ce ne sont pas des formalités : le scope dit que la clé peut
agir, le périmètre dit **sur quoi**, et sans le second le premier est le plus
large possible.

---

# 9. Ce que vous ne devez pas faire

**Copier les utilisateurs dans une table à vous.** Une copie diverge : un email
changé chez Sekuu ne l'est plus chez vous, et le jour où quelqu'un demande
l'effacement de ses données, vous ne saurez pas que vous en détenez.

Si vous avez besoin d'attributs propres à votre produit, faites une table qui
porte le `sub` en clé étrangère logique et **rien d'autre** de l'utilisateur.

**Réutiliser les `scopes` de Sekuu pour vos droits.** Voir §5.

**Faire confiance à un `organization_id` fourni par l'appelant.** Voir §4.

**Allonger la durée de vie du jeton pour éviter le rafraîchissement.** C'est
votre fenêtre d'exposition que vous allongez.

**Supposer que `products` sera là.** Un jeton sans organisation active n'en a
pas, et un produit qui ferait `$claims['products']` sans garde tomberait sur une
clé absente au lieu de refuser proprement.

---

# 10. Liste de contrôle

- [ ] JWKS récupéré et mis en cache, relecture sur `kid` inconnu
- [ ] `iss`, `aud`, `exp` vérifiés — pas seulement la signature
- [ ] Absence de `org` → refus
- [ ] `products` contient votre slug → sinon `403`
- [ ] Toutes les tables portent `organization_id`, lu du jeton
- [ ] Test d'isolation A/B écrit, sur les quatre verbes
- [ ] Correspondance rôles → droits, explicite et à vous
- [ ] `switch-organization` enchaîné après `login`
- [ ] Rafraîchissement sérialisé, un seul à la fois
- [ ] Clé d'API si appels serveur, scopes minimaux et périmètre déclaré
- [ ] Aucune table `users`
