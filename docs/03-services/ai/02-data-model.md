# Sekuu AI — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence — fait autorité sur les tables
> **Dernière mise à jour :** Août 2026

Cinq tables. Trois pour les générations, deux pour les comptes qui les
exécutent.

Les **tâches** n'en font pas partie : elles sont déclarées dans `config/ai.php`,
parce qu'une tâche est du code — un modèle, des paramètres, un schéma de sortie.

Les **comptes**, eux, sont des données, exactement comme les destinations de
Storage : plusieurs par fournisseur, et un client peut apporter le sien
([ADR-0017](../../04-decisions/adr-0017-ai-accounts.md)).

La différence tient en une phrase : **un compte est une clé, une tâche est un
comportement facturé.** La première se remplace sans revue, la seconde jamais.

---

# 1. `ai_generations`

Une exécution, de sa demande à son issue.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | UUIDv7, identifiant public |
| `organization_id` | `uuid` nullable | Le porteur du quota |
| `task` | `varchar(64)` | `summarize`, `extract` — déclarée en configuration |
| `status` | `varchar(16)` | `queued`, `running`, `succeeded`, `failed`, `cancelled` |
| `account_id` | `uuid` nullable | Le compte qui a exécuté — **résolu une fois, jamais recalculé** |
| `provider` | `varchar(32)` nullable | Constaté, jamais demandé |
| `model` | `varchar(64)` nullable | Idem |
| `input_hash` | `char(64)` | SHA-256 de l'entrée normalisée |
| `input_tokens` | `integer` nullable | Constatés |
| `output_tokens` | `integer` nullable | Constatés |
| `cost_micros` | `bigint` nullable | Coût, en millionièmes d'unité. `null` = inconnu |
| `cost_estimated` | `boolean` | Vrai sur le compte d'un tiers — voir §1.2 |
| `latency_ms` | `integer` nullable | |
| `attempts` | `smallint` | Tentatives, tous fournisseurs confondus |
| `failure_code` | `varchar(48)` nullable | Code du catalogue |
| `failure_reason` | `text` nullable | Message du fournisseur, pour un humain |
| `requested_by` | `varchar(64)` nullable | Utilisateur ou clé d'API |
| `requested_via` | `varchar(16)` | `user`, `api_key`, `system` |
| `idempotency_key` | `varchar(128)` nullable | |
| `retain_until` | `timestamptz` nullable | Non nul = le contenu est conservé |
| `started_at` / `completed_at` | `timestamptz` nullable | |
| `created_at` / `updated_at` | `timestamptz` | |

## 1.1 Pourquoi `cost_micros` et pas un `Money`

Un appel coûte des fractions de franc. `Money` porte un entier dans l'unité la
plus petite d'une devise, et le franc CFA n'a pas de subdivision : tout appel
arrondirait à zéro ou à un.

Le coût est donc compté en **millionièmes d'unité**, converti en franc au moment
de facturer. C'est la seule place du dépôt où un montant n'est pas un `Money`,
et il faut savoir pourquoi : ce n'est pas de l'argent encaissé, c'est une
consommation qui s'agrège avant de le devenir.

## 1.2 `cost_estimated`, et pourquoi un booléen mérite une colonne

Sur un compte de la plateforme, le coût est **exact** : nos prix, notre facture.

Sur le compte d'un tiers, il est calculé à partir des **prix publics** du
fournisseur — et son tarif négocié, son engagement de volume ou sa région
donnent un autre montant. Notre nombre ne sera pas celui de sa facture.

Sans cette colonne, les deux natures se mélangeraient dans les agrégats, et un
total additionnant un montant exact et une estimation ne voudrait rien dire.
Pire : un client comparerait notre chiffre au sien et y perdrait une journée.

`cost_micros` à `null` est un troisième cas, distinct de zéro : un modèle local
n'a pas de prix public. `null` dit « on ne sait pas », zéro dirait « gratuit ».

## 1.3 `input_hash` plutôt que l'entrée

L'empreinte suffit à l'idempotence, et ne porte rien.

C'est le cœur de la décision de confidentialité de
[ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md). Un registre de
prompts concentrerait en clair ce que tous les produits ont de plus sensible, et
grossirait sans limite.

L'entrée est **normalisée avant hachage** — espaces réduits, casse préservée —
pour que deux demandes identiques à un retour à la ligne près ne produisent pas
deux facturations.

## 1.4 `status` — et pourquoi `failed` n'est pas gratuit

| État | Signification |
| --- | --- |
| `queued` | Acceptée, rien n'est parti |
| `running` | Un fournisseur traite |
| `succeeded` | Sortie validée contre le schéma de la tâche |
| `failed` | Aucune sortie exploitable — **et le coût peut être non nul** |
| `cancelled` | Arrêtée avant tout appel |

`failed` avec un `cost_micros` positif est le cas qui surprend, et il est
normal : un modèle qui produit une réponse hors schéma a consommé des jetons. Ne
pas les compter reviendrait à s'offrir les échecs, et à ne jamais voir qu'une
tâche est mal réglée.

## 1.5 Index

| Index | Colonnes | Motif |
| --- | --- | --- |
| Idempotence | `organization_id, idempotency_key` (partiel, non nul) | Une clé, une génération, par organisation. |
| Quota | `organization_id, created_at` | Le coût du mois en cours. |
| Reprise | `status, created_at` (partiel, `status IN ('queued','running')`) | Les générations à relancer ou à expirer. |
| Analyse | `task, created_at` | Le coût et la latence par tâche. |
| Compte | `account_id, created_at` | Ce qu'un compte a exécuté — et empêche de le supprimer. |

L'idempotence est **cloisonnée par organisation**, comme celle de Payments : deux
produits dérivant leurs clés de leur métier pourraient sinon se renvoyer
mutuellement leurs générations.

---

# 2. `ai_contents`

Le contenu, quand une tâche déclare le conserver.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `generation_id` | `uuid` | Clé primaire |
| `input` | `text` **nullable** | L'entrée, **seulement si la tâche déclare une rétention** |
| `output` | `text` nullable | La sortie telle que reçue |
| `expires_at` | `timestamptz` | Après quoi la ligne est effacée |

L'entrée et la sortie ne vont **pas ensemble**, contrairement à ce que la forme
de la table suggère. La sortie doit survivre à l'appel — sans quoi un sondage
`GET /ai/tasks/{id}` n'aurait rien à lire, et une clé d'idempotence rejouée ne
rendrait que des métriques. L'entrée, elle, n'a aucune raison de survivre.

`null` dit « on ne l'a pas gardée », ce qu'une chaîne vide ne dirait pas : elle
se lirait comme une entrée vide.

Table **séparée**, et c'est délibéré. Le registre des générations est consulté
en permanence — quota, facturation, supervision — tandis que le contenu ne l'est
qu'exceptionnellement, pèse mille fois plus, et s'efface.

Les mêler ferait grossir la table chaude, et rendrait l'effacement du contenu
impossible sans réécrire un registre qui doit rester scellé.

## 2.1 L'effacement n'est pas optionnel

`ai:sweep` efface les lignes expirées. Une durée de conservation qui ne serait
pas appliquée est une promesse fausse, et c'est le genre de promesse qu'on
découvre fausse lors d'un audit.

Il tourne **toutes les heures**, et pas chaque nuit — non pour l'effacement, qui
pourrait attendre, mais pour sa seconde cible : les générations que plus personne
ne reprendra. `RunTaskJob::failed()` couvre un travail qui échoue ; il ne couvre
pas un travailleur tué net. La ligne reste alors `queued` pour toujours, et
l'appelant sonde indéfiniment quelque chose qui ne bougera plus.

Elles passent à `failed` avec `AI_ABANDONED` — et `cost_micros` reste `null`,
jamais zéro : la requête est peut-être partie et a peut-être été facturée.

---

# 3. `ai_spend`

La dépense agrégée, par organisation et par mois.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `organization_id` | `uuid` | |
| `period` | `char(7)` | `2026-08` |
| `cost_micros` | `bigint` | Sur **nos** comptes — exact, et seul opposable |
| `cost_micros_byo` | `bigint` | Sur les comptes du client — **estimé** |
| `generations` | `integer` | |
| `updated_at` | `timestamptz` | |

Clé primaire composée `(organization_id, period)`.

**Deux colonnes, jamais une somme.** Le quota ne porte que sur nos comptes : ce
qu'un client dépense sur sa propre clé, il le paie à son fournisseur, et le lui
opposer n'aurait aucun sens.

C'est la ventilation par destination de `storage_usage`, sous une autre forme —
et poussée d'un cran, parce qu'ici les deux nombres n'ont même pas la même
exactitude.

## 3.1 Pourquoi une table plutôt qu'une somme

Le quota est vérifié **avant chaque appel**. Une somme sur les générations du
mois reste rapide à dix mille lignes et cesse de l'être à dix millions — et elle
le cesse d'abord pour le plus gros client, celui qui appelle le plus.

C'est le même raisonnement que `storage_usage`, et la même conséquence : le
compteur est une lecture rapide, `ai_generations` reste la vérité, et une
commande le rebâtit.

---

# 4. `ai_accounts`

Une clé chez un fournisseur. Le raisonnement est dans
[ADR-0017](../../04-decisions/adr-0017-ai-accounts.md), l'usage dans
[05-providers.md](05-providers.md).

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | |
| `slug` | `varchar(64)` | Nom stable, cité par les règles de placement |
| `driver` | `varchar(32)` | `anthropic`, `openai`, `fake` — le protocole |
| `preset` | `varchar(32)` nullable | `mistral`, `groq`, `openrouter`, `vllm` |
| `config` | `jsonb` | URL de base, région, organisation chez le fournisseur |
| `credentials` | `text` | **Chiffré**, jamais rendu |
| `models` | `jsonb` nullable | Modèles servis. Nul = ce que le pilote sait |
| `owner_organization_id` | `uuid` nullable | Nul **et** `owner_api_key_id` nul = la plateforme |
| `owner_api_key_id` | `uuid` nullable | Compte d'un produit externe |
| `environment` | `varchar(16)` | `production` ou `test` |
| `status` | `varchar(16)` | `unverified`, `active`, `paused`, `disabled` |
| `spend_cap_micros` | `bigint` nullable | Plafond propre au compte |
| `priority` | `smallint` | Ordre d'essai entre comptes de la plateforme |
| `verified_at` | `timestamptz` nullable | |
| `verification_reason` | `varchar(32)` nullable | Jeu fermé — voir §4.2 |
| `verification_error` | `text` nullable | Message brut, pour un opérateur |
| `created_at` / `updated_at` | `timestamptz` | |

## 4.1 Pas de `is_default`, mais une `priority`

Storage a une destination par défaut et une seule, garantie par un index
partiel. Ici c'est différent : **plusieurs comptes de la plateforme peuvent
servir le même modèle**, et l'un prend la suite de l'autre sur un `429`.

Le défaut n'est donc pas un drapeau mais un **ordre**. Un index sur
`(environment, status, priority)` rend la résolution déterministe — ce que deux
comptes « par défaut » n'auraient pas fait.

## 4.2 Les raisons d'échec, jeu fermé

`credentials_rejected`, `model_unavailable`, `quota_exhausted`, `rate_limited`,
`unreachable`, `internal_error`.

La dernière existe parce que son absence a coûté un déploiement à Storage : une
dépendance manquante y avait été rangée dans `unreachable`, et le diagnostic
était parti chercher du côté du réseau. **Un compte injoignable se corrige chez
le fournisseur ; une erreur interne se corrige dans le dépôt.**

`quota_exhausted` est propre à l'IA : un compte parfaitement valide dont le
crédit chez le fournisseur est épuisé. Ni une erreur d'identifiants, ni une
panne — et la confusion enverrait régénérer une clé qui n'a rien.

`rate_limited` est la seule raison qui **ne change pas l'état du compte**. Le
fournisseur a dit « pas maintenant » : la clé est bonne, le modèle existe, le
crédit est là. Un compte retiré du service pour ce motif le serait précisément
aux heures de charge — c'est-à-dire quand on en a besoin — et la reprise
n'aurait lieu que le lendemain. La raison est quand même écrite : un compte
durablement saturé est une information d'exploitation.

Les fournisseurs rendent souvent le **même statut** pour `rate_limited` et
`quota_exhausted`. Les confondre fait réessayer indéfiniment chez un compte à
sec : l'un se résout en quelques secondes, l'autre demande une carte bancaire.

---

# 5. `ai_placements`

Quel compte pour quelle organisation.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | |
| `organization_id` | `uuid` | |
| `task` | `varchar(64)` nullable | Nul = toutes les tâches |
| `account_id` | `uuid` | |
| `created_at` / `updated_at` | `timestamptz` | |

Unicité sur `(organization_id, task)`, `task` nul compris — deux index partiels,
PostgreSQL ne considérant pas deux `NULL` comme égaux. Sans cette précaution,
une organisation porterait deux règles attrape-tout contradictoires, et la
résolution dépendrait de l'ordre de lecture.

## 5.1 Elles ne déplacent rien

Une règle ajoutée ou modifiée ne vaut que pour les générations **à venir**.
Celles qui existent portent déjà leur `account_id`.

L'intuition dit le contraire, et c'est la même erreur que pour les fichiers :
« je change le compte de ce client » ressemble à un déménagement, et n'en est
pas un.

---

# 6. `ai_endpoints` et `ai_deliveries`

Où livrer l'issue d'une génération, et ce qui a été livré.

| `ai_endpoints` | Type | Rôle |
| --- | --- | --- |
| `organization_id` | `uuid` unique | Une destination par organisation |
| `url` | `varchar(500)` | `https` obligatoire |
| `secret` / `previous_secret` | `varchar(120)` | Rotation sans coupure |
| `previous_secret_expires_at` | `timestamptz` nullable | |
| `status` | `varchar(20)` | `active`, `paused` |

| `ai_deliveries` | Type | Rôle |
| --- | --- | --- |
| `event_id` | `varchar(60)` unique | La clé sur laquelle le produit déduplique |
| `event_type` | `varchar(60)` | Quatre valeurs — voir [04-events.md](04-events.md) |
| `generation_id` | `uuid` **nullable** | |
| `payload` | `jsonb` | L'enveloppe signée, telle qu'envoyée |
| `status` | `varchar(20)` | `pending`, `delivered`, `exhausted` |

## 6.1 Ce ne sont pas les tables de Payments

La forme est la même ; le contenu non. `generation_id` est **nullable**, et c'est
ce qui les distingue : deux des quatre événements ne parlent pas d'une
génération. `ai.account.unverified` parle d'un compte,
`ai.spend.threshold_reached` parle d'un mois.

Les faire converger avec `payment_deliveries` donnerait une table qui ne décrit
bien ni l'une ni l'autre, et une colonne nulle dans la moitié des lignes.

Ce qui **est** partagé est ailleurs : `SignedWebhook` porte la signature HMAC et
le garde-fou d'hôte de test, pour les deux modules. Deux implémentations du même
HMAC finiraient par diverger sur un détail — l'ordre des secrets, le
séparateur — et un intégrateur ayant écrit son vérificateur pour Payments le
verrait rejeté par AI sans comprendre.

## 6.2 La charge utile ne porte jamais le contenu

Ni le prompt, ni la sortie. Un webhook part vers une URL déclarée par le produit,
en clair sur le réseau public : y mettre le contenu reviendrait à publier ce que
l'[ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md) refuse de
stocker.

Le produit apprend qu'une sortie l'attend, et vient la chercher authentifié.

## 6.3 Les réessais épuisés ne ferment pas la destination

La livraison passe `exhausted`, l'endpoint reste `active`. Le désactiver
transformerait une panne de quelques heures chez le produit en silence
permanent, et il faudrait qu'un humain s'en aperçoive pour le rouvrir.

C'est le sondage qui rattrape — et ici plus qu'ailleurs, puisqu'il est la voie
normale.


---

# 7. Ce que le modèle ne porte pas

**Aucune table de conversation.** Un fil, son historique et ses droits sont de
la logique produit — voir [01-overview.md](01-overview.md) §2.1.

**Aucune table de tâches.** Une tâche déclare un modèle, des paramètres et un
schéma de sortie : c'est du code, versionné avec lui. En faire une donnée
permettrait de changer un modèle sans revue, sans test, et sans que la
dépréciation du précédent soit vue par quiconque.

**Aucune table d'embeddings.** Elle appartiendra à Search, avec l'index qui va
avec. En poser une ici créerait un second index concurrent, et le jour où Search
arrivera, personne ne saurait lequel fait autorité.

**Aucun stockage de fichier.** Une tâche qui traitera un document recevra un
`file_id` de Storage, et lira les octets par lui.

**Aucune table de prix.** Les tarifs vivent dans la configuration du pilote :
ils changent, parfois à la baisse, et une revue de code ne devrait pas être
nécessaire pour en profiter. Le prix appliqué est en revanche **figé sur la
ligne** via `cost_micros` — un tarif révisé ne réécrit jamais l'historique.
