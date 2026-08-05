# Sekuu AI — Fournisseurs, comptes et tâches

> **Version :** 1.1
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

La décision de tenir les comptes en base plutôt qu'en configuration est prise
dans [ADR-0017](../../04-decisions/adr-0017-ai-accounts.md), qui transpose
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md).

---

# 1. Quatre niveaux, et les confondre est la première erreur

| | Ce que c'est | Où ça vit |
| --- | --- | --- |
| **Pilote** | Un protocole — savoir authentifier, appeler, compter les jetons | Une classe PHP |
| **Préréglage** | Les particularités d'un service parlant ce protocole | `config/ai.php` |
| **Compte** | Une clé, un plafond, un propriétaire | **Une ligne en base** |
| **Tâche** | Ce qu'un produit peut demander | `config/ai.php` |

Un pilote sert plusieurs services ; un service sert plusieurs comptes ; une
tâche nomme un modèle, que certains comptes savent servir.

```
anthropic (pilote)              ← protocole propre : x-api-key, anthropic-version
└── —                        → 3 comptes : plateforme, client-acme, recette

openai (pilote)                 ← le PROTOCOLE, pas l'entreprise
├── openai      (préréglage) → GPT
├── gemini      (préréglage) → Google, par son point d'accès compatible
├── deepseek    (préréglage) → DeepSeek
├── mistral     (préréglage) → Mistral
├── xai         (préréglage) → Grok
├── groq        (préréglage) → Llama, Qwen — inférence rapide
├── together    (préréglage) → modèles ouverts
├── fireworks   (préréglage) → modèles ouverts
├── deepinfra   (préréglage) → modèles ouverts
├── openrouter  (préréglage) → routeur multi-fournisseurs
├── azure       (préréglage) → OpenAI chez Azure
├── ollama      (préréglage) → local
└── vllm        (préréglage) → local, auto-hébergé

bedrock (pilote)                ← signature SigV4, pas une clé
└── —                        → Claude, Llama, Mistral chez AWS

fake (pilote)
└── —                        → 1 compte : les tests
```

## 1.1 Le pilote `openai` n'est pas OpenAI

C'est le **protocole** d'OpenAI, que Mistral, Groq, Together, OpenRouter et la
plupart des serveurs locaux parlent à l'identique. Ils n'en diffèrent que par
une URL de base et un catalogue de modèles.

C'est exactement la situation du pilote `s3` de Storage, qui sert AWS, R2, B2,
Scaleway et MinIO. Et la conséquence est la même : **ajouter l'un de ces
services est une entrée de configuration, pas du code.**

Anthropic a son propre protocole — en-tête `x-api-key`, `anthropic-version`,
format de messages distinct — donc son propre pilote.

## 1.2 Ajouter une famille demande une classe

Cinq méthodes, et c'est irréductible pour la même raison que côté stockage : un
pilote doit savoir **authentifier et interpréter la facturation** d'un
fournisseur, ce qui est un protocole, pas un paramètre.

```php
interface AiDriver
{
    public function capabilities(): DriverCapabilities;

    /** Ce compte sert-il ce modèle ? */
    public function serves(AiAccount $account, string $model): bool;

    /** Exécute, ou lève. Rend la sortie **et** ce qu'elle a consommé. */
    public function generate(AiAccount $account, GenerationRequest $request): GenerationResult;

    /** La plus petite génération possible, pour l'épreuve. */
    public function probe(AiAccount $account): void;
}
```

Quatre méthodes, et **aucune ne parle de prix**. Le tarif appartient au registre
des modèles (§7), pas au pilote : le même `llama-3.3-70b` coûte trois prix
différents chez trois hébergeurs, et le protocole n'y est pour rien.

`generate()` rend les **jetons consommés**, jamais un montant. La conversion en
coût est faite au-dessus, par ce qui connaît le registre — et sait aussi si le
compte appartient à la plateforme ou à un client, donc si le chiffre est exact
ou estimé.

---

# 2. Le compte

| Champ | Rôle |
| --- | --- |
| `slug` | Nom court, cité par les règles de placement |
| `driver` / `preset` | Le protocole, et le service |
| `config` | URL de base, région, organisation chez le fournisseur |
| `credentials` | **Chiffrés**, jamais rendus |
| `owner` | La plateforme, une organisation, ou un produit externe |
| `environment` | `production` ou `test` |
| `status` | `unverified`, `active`, `paused`, `disabled` |
| `spend_cap_micros` | Plafond propre au compte, nullable |
| `models` | Modèles servis — vide = ce que le pilote sait |

## 2.1 Les états

| État | Générer | Choisi par la résolution |
| --- | --- | --- |
| `unverified` | non | **non** |
| `active` | oui | oui |
| `paused` | non | non |
| `disabled` | non | non |

`paused` et `disabled` diffèrent par l'intention, pas par l'effet immédiat :
le premier est une suspension, le second dit que le compte n'est plus le nôtre.
La distinction compte au moment de nettoyer — on relance un compte en pause, on
ne relance pas un compte dont la clé a été rendue.

Il n'y a pas d'équivalent au `read_only` de Storage, et c'est révélateur : un
magasin garde des octets qu'il faut continuer à servir, un compte d'IA ne garde
rien. Cesser d'y appeler suffit à le retirer, sans rien rendre illisible.

## 2.2 L'épreuve coûte de l'argent

Une génération réelle d'un jeton, sur le plus petit modèle du fournisseur. De
l'ordre du millionième d'unité — mais pas zéro.

D'où deux conséquences. L'épreuve est **quotidienne**, pas horaire. Et son coût
est imputé à la plateforme, jamais au propriétaire du compte : nous ne facturons
pas à un client le fait de vérifier notre propre configuration.

Une épreuve qui ne consommerait rien — lister les modèles — ne prouverait pas ce
qui compte. Un compte peut lister sans avoir de crédit.

## 2.3 Le garde-fou d'environnement

Une clé de test en production, ou une clé de production ailleurs, est refusée
dans les deux sens. Sans échappatoire, comme `CredentialGuard` pour les
agrégateurs de paiement.

La faute est moins grave qu'un débit erroné, mais elle coûte : une clé de
production sur un poste de développement facture de vrais appels à chaque
exécution de la suite de tests.

## 2.4 Les identifiants

Chiffrés au repos. **Jamais rendus par l'API**, y compris à celui qui les a
déposés : la lecture rend une empreinte — quatre caractères et un condensat.

Rotation par `PUT /ai/accounts/{id}/credentials`, qui **éprouve avant de
remplacer**. Les anciens identifiants ne sont abandonnés qu'après succès : une
rotation ratée ne doit pas couper le service.

---

# 3. La résolution

Une tâche nomme un modèle. Reste à savoir **quel compte** l'exécute.

| Rang | Source | Cas d'usage |
| --- | --- | --- |
| 1 | Le compte nommé par l'appelant externe | « Utilise ma clé » |
| 2 | Règle de placement `(organisation, tâche)` | Ce client veut ses quiz chez lui |
| 3 | Règle de placement `(organisation, *)` | Ce client veut tout chez lui |
| 4 | Comptes de la plateforme servant ce modèle | Le cas courant |

Un compte nommé mais inéligible — non éprouvé, en pause, hors environnement, ne
servant pas le modèle — **échoue**. La résolution ne redescend pas d'un rang.

C'est la règle de Storage, et pour une raison plus forte encore : se rabattre
sur un compte de la plateforme ferait payer **nous** à la place du client, sans
que personne l'ait décidé.

## 3.1 Plusieurs comptes de la plateforme pour un même modèle

Le rang 4 peut rendre plusieurs candidats. Ils sont essayés dans l'ordre de leur
déclaration, et **seul un `429` fait passer au suivant** — voir §4.

Ce n'est pas de la répartition de charge : c'est une réaction à un fait. Un
tourniquet optimiserait une file qui n'existe pas encore.

## 3.2 Ce qui est écrit sur la génération

`account_id`, à la demande, définitivement. Une génération dit où elle est
partie et qui l'a payée.

Changer une règle de placement n'affecte que les générations à venir — même
principe qu'un fichier qui ne déménage jamais.

---

# 4. La bascule est étroite

On ne réessaie ailleurs que si la requête **n'a jamais atteint le modèle**.

| Situation | Autre compte ? | Autre fournisseur ? |
| --- | --- | --- |
| Connexion refusée, DNS, `502`/`503` avant traitement | oui | oui |
| `429` du fournisseur, aucun jeton consommé | **oui** | oui |
| `401`/`403` — clé invalide ou révoquée | oui | oui, et le compte bascule `unverified` |
| Délai dépassé après le début de la génération | non | non |
| Réponse reçue, hors schéma | non — un réessai, même compte | non |
| Refus de modération | non | **non** |

Passé le premier jeton, les jetons sont facturés qu'on obtienne une réponse ou
non. Réessayer ailleurs paie deux fois et rend une réponse différente de celle
qui arrivait peut-être.

C'est l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md),
transposée mot pour mot : **l'incertitude compte comme un appel abouti.**

La dernière ligne est la plus discutable. Un refus de modération contourné en
changeant de fournisseur serait un contournement réussi — et personne n'en veut,
ni nous, ni le fournisseur, ni le client le jour où cela se sait.

**Un compte de tiers ne bascule jamais vers un compte de la plateforme.** Ce
serait faire payer autrui, silencieusement. Il échoue franchement.

---

# 5. Les tâches restent du code

C'est la différence de fond avec Storage, et elle est délibérée.

Une destination est un **compte** — un client peut l'apporter. Une tâche déclare
un modèle, une température, un schéma de sortie : c'est un **comportement
facturé**, et le changer sans revue ni test serait le changer en silence.

```php
'tasks' => [

    'summarize' => [
        'model' => 'claude-sonnet-4-6',
        'fallback' => 'mistral-large',
        'max_input_tokens' => 100_000,
        'max_output_tokens' => 1_000,
        'temperature' => 0.2,
        'output' => 'text',
        'synchronous' => true,
        'retain_days' => null,
    ],

    // Libre : l'appelant écrit ce qu'il veut, la plateforme choisit le modèle.
    // Les bornes remplacent le schéma — c'est tout ce qui tient le coût.
    'prompt' => [
        'model' => 'claude-sonnet-4-6',
        'fallback' => 'deepseek-chat',
        'max_input_tokens' => 32_000,
        'max_output_tokens' => 4_000,
        'temperature' => 0.7,
        'output' => 'text',
        'synchronous' => false,
        'accepts_history' => true,
    ],

    'prompt-fast' => ['extends' => 'prompt', 'model' => 'gemini-2.5-flash', 'max_output_tokens' => 1_000],
    'prompt-deep' => ['extends' => 'prompt', 'model' => 'claude-sonnet-4-6', 'max_output_tokens' => 16_000],

    'extract' => [
        'model' => 'claude-sonnet-4-6',
        'fallback' => null,
        'temperature' => 0,
        'output' => 'json',
        'schema' => ExtractSchema::class,
        'synchronous' => false,
    ],

],
```

`temperature: 0` sur `extract` n'est pas un réglage de confort : une extraction
qui varie d'un appel à l'autre est inexploitable, et un produit qui la
rapprocherait de sa base attribuerait les écarts à ses données.

`fallback: null` est un choix. Une extraction structurée réussie par un modèle et
ratée par un autre produirait deux formes de sortie ; mieux vaut échouer
franchement.

---

# 6. Les préréglages

De la donnée. Ajouter Groq, Together, Cerebras ou un serveur local demande une
entrée, et rien d'autre.

```php
'presets' => [

    // Protocole OpenAI — la grande famille. Rien d'autre qu'une URL de base.
    'openai'     => ['driver' => 'openai', 'base_url' => 'https://api.openai.com/v1'],
    'gemini'     => ['driver' => 'openai', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
    'deepseek'   => ['driver' => 'openai', 'base_url' => 'https://api.deepseek.com/v1'],
    'mistral'    => ['driver' => 'openai', 'base_url' => 'https://api.mistral.ai/v1'],
    'xai'        => ['driver' => 'openai', 'base_url' => 'https://api.x.ai/v1'],
    'groq'       => ['driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1'],
    'together'   => ['driver' => 'openai', 'base_url' => 'https://api.together.xyz/v1'],
    'fireworks'  => ['driver' => 'openai', 'base_url' => 'https://api.fireworks.ai/inference/v1'],
    'deepinfra'  => ['driver' => 'openai', 'base_url' => 'https://api.deepinfra.com/v1/openai'],
    'openrouter' => ['driver' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1'],

    // Azure : même protocole, autre en-tête d'authentification et une version
    // dans l'URL. Deux paramètres, toujours pas de code.
    'azure'      => ['driver' => 'openai', 'auth' => 'api-key', 'requires' => ['base_url', 'api_version']],

    // Local. Pas de clé, pas de prix public — voir §7.3.
    'ollama'     => ['driver' => 'openai', 'base_url' => 'http://localhost:11434/v1'],
    'vllm'       => ['driver' => 'openai', 'requires' => ['base_url']],

    // Protocoles propres.
    'anthropic'  => ['driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com'],
    'bedrock'    => ['driver' => 'bedrock', 'requires' => ['region']],

],
```

Treize services, **deux pilotes**. C'est ce que vaut le choix de nommer le
pilote d'après un protocole plutôt que d'après une entreprise.

## 6.1 Google par son point d'accès compatible, et pourquoi

Gemini a une API native — `generativelanguage.googleapis.com`, format propre.
Google publie aussi un point d'accès **compatible OpenAI**, et c'est celui-ci
que le préréglage utilise.

Ce n'est pas de la paresse : un préréglage coûte une ligne, un pilote natif
coûte une classe, des tests et une dette de maintenance. Tant que la voie
compatible sert les modèles dont nous avons besoin, la seconde ne se justifie
pas.

Elle se justifiera le jour où une capacité n'y passera pas — le raisonnement
étendu, la configuration de sûreté, l'API de fichiers native. Ce jour-là, un
pilote `gemini` s'ajoutera **à côté**, et les comptes existants continueront de
fonctionner : c'est tout l'intérêt d'avoir séparé le compte du protocole.

## 6.2 Bedrock mérite un pilote

Il ne s'authentifie pas par une clé mais par une **signature SigV4**, avec des
identifiants AWS, une région, et éventuellement un rôle. Aucun paramètre ne
transforme le pilote `openai` en signataire AWS.

C'est la démonstration de la limite posée par
[ADR-0017](../../04-decisions/adr-0017-ai-accounts.md) : ajouter un **service**
est une donnée, ajouter une **famille d'authentification** est du code.


## 6.3 Un préréglage n'est pas une caution

`openrouter` est dans cette liste parce que son protocole est compatible, pas
parce qu'il satisfait l'exigence de non-entraînement de
l'[ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md).

Un intermédiaire qui revend un accès agrégé ne contrôle pas la politique de
données des modèles qu'il route. Sur un compte de la plateforme, c'est notre
responsabilité et il est écarté ; sur le compte d'un client, c'est la sienne, et
nous ne pouvons que le lui dire.

---

# 7. Le registre des modèles

Deux pilotes et treize services donnent accès à des dizaines de modèles. Sans
registre, personne ne sait lequel coûte quoi, lequel sait produire du JSON, ni
lequel a été retiré la semaine dernière.

```php
'models' => [

    'claude-sonnet-4-6' => [
        'family'      => 'anthropic',
        'context'     => 200_000,
        'capabilities'=> ['json', 'tools', 'vision'],
        'price_in'    => 3.00,   // par million de jetons
        'price_out'   => 15.00,
        'status'      => 'preferred',
    ],

    'deepseek-chat' => [
        'family'      => 'openai',
        'context'     => 128_000,
        'capabilities'=> ['json', 'tools'],
        'price_in'    => 0.28,
        'price_out'   => 0.42,
        'status'      => 'preferred',
    ],

    'gemini-2.5-flash' => [
        'family'      => 'openai',
        'context'     => 1_000_000,
        'capabilities'=> ['json', 'tools', 'vision'],
        'price_in'    => 0.30,
        'price_out'   => 2.50,
        'status'      => 'preferred',
    ],

    'llama-3.3-70b' => [
        'family'      => 'openai',
        'context'     => 128_000,
        'capabilities'=> ['json'],
        'price_in'    => null,   // dépend de l'hébergeur
        'price_out'   => null,
        'status'      => 'preferred',
    ],

],
```

Les prix sont **indicatifs et versionnés avec le code**. Ils bougent, souvent à
la baisse, et une revue ne devrait pas être nécessaire pour en profiter — mais
le prix **appliqué** est figé sur la ligne de la génération : un tarif révisé ne
réécrit jamais l'historique.

## 7.1 Les capacités ne sont pas décoratives

Une tâche déclare ce dont elle a **besoin**, pas seulement quel modèle elle
préfère :

```php
'extract' => [
    'model'    => 'claude-sonnet-4-6',
    'fallback' => 'gemini-2.5-flash',
    'requires' => ['json'],
    …
],
```

Un test d'architecture vérifie au démarrage que **chaque modèle d'une chaîne
satisfait les exigences de sa tâche**. Un repli sans `json` sur une tâche qui en
exige produirait, en production, une sortie invalide sur le chemin le moins
testé — celui qui ne sert que quand le premier modèle est déjà tombé.

C'est le genre de défaut qui ne se voit qu'un mauvais jour, et cumulé avec un
autre.

## 7.2 Le cycle de vie d'un modèle

| Statut | Résolution | Effet |
| --- | --- | --- |
| `preferred` | choisi | — |
| `deprecated` | choisi | journalisé en `warning`, listé par `ai:models` |
| `retired` | **refusé** | la tâche passe à son repli, ou échoue |

C'est ici que se paie la dette que l'[ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md)
nous a fait assumer : quand un fournisseur annonce un retrait, on marque le
modèle `deprecated`, on voit dans les journaux qui l'utilise encore, puis on le
passe `retired` — **et aucun produit n'a rien à changer.**

Un modèle absent du registre est refusé. Une tâche ne peut pas nommer un modèle
que personne n'a tarifé : ce serait une facture qu'on ne saurait pas imputer.

## 7.3 Les modèles sans prix

`price_in: null` n'est pas un oubli. Un modèle auto-hébergé sur `vllm` ne coûte
pas par jeton mais par heure de machine ; un modèle ouvert servi par trois
hébergeurs a trois prix.

`cost_micros` reste alors `null` — distinct de zéro. L'un dit « on ne sait
pas », l'autre dirait « gratuit », et un tableau de coûts qui affiche zéro pour
une machine qu'on loue à l'heure est un tableau qui ment.

---

# 8. Nos clés **et** les leurs

Les deux coexistent, et c'est le cas nominal.

| | Comptes de la plateforme | Comptes d'un client |
| --- | --- | --- |
| Qui les pose | Nous, par `ai:account` ou par l'environnement | Le client, par `POST /ai/accounts` |
| Combien | Autant que voulu, par fournisseur | Autant qu'il veut |
| Quota du plan | S'applique | Ne s'applique pas |
| Coût | Exact | Estimé |
| Résolution | Rang 4 | Rangs 1 à 3 |

**Nos comptes n'ont pas de route**, et n'en auront pas. Ils portent nos
identifiants et servent toutes les organisations ; les exposer reviendrait à
confier cette infrastructure à qui détient un jeton d'administration. C'est la
règle posée pour les magasins de Storage, et elle vaut ici davantage encore :
une clé d'IA fuitée se dépense.

Ils se posent à la main :

```bash
php artisan ai:account plateforme-anthropic --preset=anthropic --priority=10
```

Ou par l'environnement là où il n'y a pas de shell — `AI_DEFAULT_*`, avec la
reprise d'un compte jamais éprouvé, exactement comme `STORAGE_DEFAULT_*`. Cette
voie existe parce que son absence a bloqué une mise en production de Storage.
