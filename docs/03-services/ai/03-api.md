# Sekuu AI — API

> **Version :** 1.0
> **Statut :** Spécification de référence — fait autorité sur les routes
> **Dernière mise à jour :** Août 2026

Préfixe `/api/v1`. Enveloppe, pagination et erreurs suivent
[api-guidelines.md](../../02-standards/api-guidelines.md). Le contrat OpenAPI
(`Modules/AI/openapi.yaml`) fait foi sur les schémas, et un test vérifie que
routes et contrat ne divergent pas.

---

# 1. Les routes

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/ai/tasks` | Le catalogue : ce que la plateforme sait faire |
| `POST` | `/ai/tasks` | Demander une exécution |
| `GET` | `/ai/tasks/{id}` | L'issue, et la sortie |
| `POST` | `/ai/tasks/{id}/cancel` | Annuler, tant que rien n'est parti |
| `GET` | `/ai/usage` | Ce que l'organisation a consommé ce mois-ci |
| `GET` | `/ai/health` | Les comptes réellement configurés |

Et cinq pour administrer les comptes — leur raison d'être est dans
[05-providers.md](05-providers.md).

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/ai/accounts` | Les comptes utilisables par l'appelant |
| `POST` | `/ai/accounts` | Enregistrer une clé, et l'éprouver |
| `POST` | `/ai/accounts/{id}/verify` | Rejouer l'épreuve |
| `PUT` | `/ai/accounts/{id}/credentials` | Rotation, éprouvée avant remplacement |
| `PATCH` | `/ai/accounts/{id}` | `active`, `paused`, `disabled`, plafond |

Il n'y a **pas** de `DELETE`. Un compte qui porte des générations ne se supprime
pas : le registre dit qui a payé quoi, et la ligne disparue, il ne le dirait
plus. On met en pause.

Aucune de ces routes n'administre un compte **de la plateforme** : il porte nos
identifiants et sert toutes les organisations. Il se pose par `ai:account`, à la
main, ou par l'environnement là où il n'y a pas de shell — la leçon de Storage.

Onze routes, et **aucune ne porte de champ `model`**. C'est l'invariant du
module, tranché dans
[ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md).

---

# 2. Le catalogue

```http
GET /api/v1/ai/tasks
```

```json
{
  "success": true,
  "data": [
    {
      "task": "summarize",
      "inputs": { "input": "required|string", "language": "nullable|string|size:2" },
      "output": "text",
      "synchronous": true,
      "accepts_history": false,
      "retains_content": false,
      "max_input_tokens": 100000,
      "max_output_tokens": 1000,
      "estimated_cost_micros": 12500
    },
    {
      "task": "extract",
      "inputs": { "input": "required|string", "fields": "required|array" },
      "output": "json",
      "synchronous": false,
      "accepts_history": false,
      "retains_content": false,
      "max_input_tokens": 100000,
      "max_output_tokens": 4000,
      "estimated_cost_micros": 60000
    }
  ]
}
```

Cette route existe pour une raison précise : sans elle, un intégrateur devrait
lire notre documentation pour savoir ce qu'il peut demander, et découvrirait
qu'une tâche a changé de forme en production.

`estimated_cost_micros` est un ordre de grandeur, pas un prix. Il permet à un
produit de décider s'il vaut la peine d'appeler — et il ne l'engage à rien : le
coût réel dépend de l'entrée.

**Il n'y a pas de champ `description`**, et l'absence est délibérée. Elle vivrait
dans `config/ai.php`, donc en une seule langue, dans un catalogue consommé par
des produits qui servent en français et en anglais. Les règles de validation
sont rendues telles quelles : elles disent la même chose sans avoir de langue.

**Aucun modèle n'apparaît**, pas même en lecture. L'exposer inviterait un produit
à en dépendre — à brancher une condition dessus, à l'afficher à ses
utilisateurs — et rendrait impossible d'en changer sans prévenir, ce qui est
exactement ce que l'ADR-0015 achète.

Avec une **clé d'API**, la liste est réduite à ce que la clé peut demander.
Montrer le reste inviterait à écrire du code contre une tâche qui rendra
`AI_TASK_OUT_OF_SCOPE`.

---

# 3. Demander une exécution

```http
POST /api/v1/ai/tasks
Idempotency-Key: 019fd4a1-…

{
  "task": "summarize",
  "input": "…",
  "language": "fr",
  "max_words": 120
}
```

```json
{
  "success": true,
  "data": {
    "id": "019fd4b2-…",
    "task": "summarize",
    "status": "queued",
    "poll_after_ms": 1500
  }
}
```

`202`, jamais `201`. Ce qui est créé est une **demande**, pas un résultat — la
même distinction que côté paiement, où `202` dit qu'une intention existe mais
que le client n'a rien validé.

Une tâche déclarée `synchronous` peut répondre `200` avec la sortie, sous une
échéance stricte. **C'est un confort, jamais une garantie** : un appelant qui
suppose le synchrone casse le jour où la tâche s'allonge, et ce jour arrivera
sans qu'on l'annonce.

## 3.1 Ce que l'appelant ne peut pas envoyer

| Refusé | Pourquoi |
| --- | --- |
| `model` | [ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md) |
| Un compte qui n'est pas le sien | `AI_ACCOUNT_FORBIDDEN` — ce serait faire payer autrui |
| `temperature`, `max_tokens`, `top_p` | Réglages du modèle, donc du coût et de la stabilité |
| `system` | Un prompt système fourni par l'appelant contourne les bornes de la tâche |
| Un champ hors de `inputs` | `UNSUPPORTED_PARAMETER` — un champ ignoré en silence est un champ dont l'appelant croit qu'il agit |

Le troisième mérite l'explication. Une tâche porte ses propres instructions —
format, ton, refus de produire certaines choses. Laisser l'appelant en ajouter
lui donnerait le moyen de les défaire, et transformerait le catalogue en
décoration.

## 3.2 Erreurs

| Code | HTTP | Quand |
| --- | --- | --- |
| `AI_TASK_UNKNOWN` | 422 | Tâche absente du catalogue |
| `CONTEXT_LENGTH_EXCEEDED` | 422 | Entrée plus longue que ce que la tâche accepte |
| `CONTENT_FLAGGED` | 422 | Refus de modération du fournisseur |
| `AI_QUOTA_EXCEEDED` | 429 | Crédits de l'organisation épuisés |
| `AI_SPEND_CAP_REACHED` | 429 | Plafond absolu de la plateforme atteint |
| `AI_PROVIDER_ERROR` | 503 | Tous les fournisseurs éligibles ont échoué |
| `AI_PROVIDER_TIMEOUT` | 503 | Aucun n'a répondu à temps |
| `AI_ACCOUNT_FORBIDDEN` | 403 | Compte nommé, mais pas à cet appelant |
| `AI_ACCOUNT_UNVERIFIED` | 409 | Compte jamais éprouvé, ou retombé en échec |
| `AI_ACCOUNT_CAP_REACHED` | 429 | Plafond propre au compte atteint |

`AI_SPEND_CAP_REACHED` est distinct de `AI_QUOTA_EXCEEDED`, et la distinction
compte : le premier dit « la plateforme s'est protégée », le second « votre plan
est épuisé ». Le premier n'est pas la faute du client, et l'inviter à passer au
plan supérieur serait mensonger.

## 3.3 Idempotence

`Idempotency-Key` rend la génération existante plutôt que d'en lancer une
seconde. Sans clé, l'empreinte de l'entrée sert de garde-fou sur une courte
fenêtre — assez pour absorber un double-clic, pas assez pour empêcher deux
demandes légitimes identiques à une heure d'intervalle.

Une génération n'est **jamais facturée deux fois** pour la même clé.

---

# 4. L'issue

```http
GET /api/v1/ai/tasks/019fd4b2-…
```

```json
{
  "success": true,
  "data": {
    "id": "019fd4b2-…",
    "task": "summarize",
    "status": "succeeded",
    "output": "…",
    "usage": { "input_tokens": 1840, "output_tokens": 210, "cost_micros": 1180 },
    "completed_at": "2026-08-05T10:12:04Z"
  }
}
```

Tant que le statut est `queued` ou `running`, la réponse porte `Retry-After` et
pas de sortie.

**La sortie n'est lisible qu'une fois.** Elle n'est pas conservée, sauf si la
tâche le déclare : passé la lecture, le corps ne porte plus que les métriques.
Un produit doit donc écrire ce qu'il reçoit — et c'est le comportement qu'on
veut, puisqu'il est le seul à savoir où cela a sa place.

`usage` est rendu même en échec, avec le coût réel. Un modèle qui a produit une
réponse hors schéma a consommé des jetons, et les cacher reviendrait à s'offrir
les échecs.

---

# 5. Annuler

```http
POST /api/v1/ai/tasks/{id}/cancel
```

N'aboutit que sur `queued`. Une fois la requête partie chez un fournisseur, il
n'y a rien à annuler : les jetons seront consommés et facturés, que la réponse
nous intéresse encore ou non.

Prétendre le contraire — rendre `200` et jeter le résultat — donnerait
l'illusion d'économiser, et masquerait une dépense réelle.

---

# 6. La consommation

```http
GET /api/v1/ai/usage
```

```json
{
  "success": true,
  "data": {
    "period": "2026-08",
    "generations": 412,
    "platform": { "cost_micros": 1840500, "estimated": false },
    "own_accounts": { "cost_micros": 920000, "estimated": true },
    "limit": { "covered": true, "credits": 5000000, "remaining": 3159500 },
    "by_task": [ { "task": "summarize", "generations": 380, "cost_micros": 910000 } ]
  }
}
```

`by_task` est le seul endroit où un client voit **où** part son budget. C'est ce
qui rend une conversation possible quand une facture surprend, et ce qui permet
à un produit de découvrir qu'une tâche appelée en boucle lui coûte plus que tout
le reste.

Les deux natures de coût sont **séparées, jamais additionnées**. Celui de nos
comptes est exact et compte pour le quota ; celui des comptes du client est
estimé à partir des prix publics, et ne lui est pas opposé — il le paie à son
fournisseur. Un total les mêlant ne voudrait rien dire.

`limit.covered` à faux signifie que le plan n'ouvre pas la ressource — ce qui
n'est pas un refus. Un quota borne un usage autorisé, il ne décide pas de
l'autorisation.

---

# 7. Les routes qui n'existent pas

**`POST /ai/completions`.** L'API générique du marché, avec `model` et
`messages`. C'est exactement ce que l'ADR-0015 refuse.

**`GET /ai/tasks` sans filtre d'organisation.** Un listing des générations
d'autrui n'a pas de sens ici ; la route rend le catalogue, pas l'historique.
L'historique se lit par `/ai/usage`, agrégé.

**`POST /ai/tasks/{id}/retry`.** Réessayer est une décision de coût. Un produit
qui veut une seconde génération en demande une nouvelle, avec une nouvelle clé
d'idempotence — et voit ce qu'elle lui coûte.
