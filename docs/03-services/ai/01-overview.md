# Sekuu AI — Vision & Périmètre

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Composant :** Sekuu AI Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu AI.

* Les tables font autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md), et le contrat OpenAPI par-dessus.
* Les événements font autorité dans [04-events.md](04-events.md).
* Les fournisseurs et les comptes font autorité dans [05-providers.md](05-providers.md).
* Pour brancher un module du monolithe : [06-integration.md](06-integration.md).
* Pour brancher un service externe : [07-external-api.md](07-external-api.md).
* Le refus de nommer un modèle est décidé dans [ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md).
* Les comptes multiples et la clé apportée sont décidés dans [ADR-0017](../../04-decisions/adr-0017-ai-accounts.md).
* La dépense et la confidentialité sont décidées dans [ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md).

---

# 1. Vision

AI exécute des **tâches** contre des modèles de langage, et compte ce qu'elles
coûtent. **Rien d'autre.**

Il n'implémente jamais la logique métier d'un produit. Il ne sait pas ce qu'est
une leçon, un dossier médical ou un contrat : il reçoit une tâche déclarée, une
entrée, et rend une sortie dont la forme a été promise.

## 1.1 L'appelant ne nomme jamais le modèle

C'est l'invariant du module, et il est tranché dans
[ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md).

```json
{ "task": "summarize", "input": "…", "language": "fr" }
```

Il n'existe **aucun champ `model`**. La transposition est directe : là où
Payments pose que *seul le propriétaire de l'objet nomme son prix*, AI pose que
**seule la plateforme nomme le modèle**.

Un nom de modèle dans un produit est un couplage à un fournisseur, à un prix et
à une date — et les trois se cassent séparément, sans prévenir. Le jour où un
modèle est retiré, c'est notre problème et pas celui de nos produits. C'est
exactement ce que nous vendons.

---

# 2. Ce que AI ne fait pas

| Hors périmètre | Responsable |
| --- | --- |
| Décider **quoi** demander à un modèle | Le produit appelant |
| Tenir une conversation, son historique, son état | Le produit appelant |
| Indexer un corpus, chercher dedans | **Search** |
| Stocker un document, le servir | **Storage** |
| Publier la limite de crédits d'un plan | **Billing** |
| Connaître les utilisateurs et les organisations | **Identity** |
| Fournir la clé chez le fournisseur | Nous, ou le produit — voir §5 |
| Décider si une réponse est bonne | Personne — voir §8 |

## 2.1 Un assistant conversationnel n'est pas une capacité de plateforme

C'en a l'air, et c'est le piège.

Un assistant suppose une conversation : un fil, un historique, une mémoire, des
règles sur ce qu'il peut faire au nom de qui. Tout cela est **de la logique
produit** — l'assistant de Sekuu Learn n'a ni les mêmes droits ni les mêmes
sources que celui d'un dossier patient.

AI fournit la brique : une génération, avec un historique fourni par l'appelant.
Le fil appartient au produit, qui seul sait ce qu'il a le droit d'y mettre.

---

# 3. Une tâche, pas un appel de modèle

Une **tâche** est une capacité déclarée par la plateforme : un nom, un modèle
préféré, un repli, des paramètres, un format de sortie et une politique de
conservation.

| Tâche | Ce qu'elle fait |
| --- | --- |
| `summarize` | Résume un texte, dans la langue demandée |
| `translate` | Traduit, en préservant la mise en forme |
| `extract` | Tire des champs déclarés d'un texte, en JSON validé |
| `classify` | Range une entrée dans une liste close d'étiquettes |
| `quiz` | Produit des questions à partir d'un contenu pédagogique |
| `prompt` | **Texte libre en entrée, texte libre en sortie** |
| `prompt-fast` | Idem, sur un petit modèle — volume et latence |
| `prompt-deep` | Idem, sur un grand modèle — raisonnement, rédaction longue |

Les trois dernières sont **libres**, et ne contredisent pas le §1.1 : ce qui est
refusé est que l'appelant nomme le **modèle**, pas qu'il écrive ce qu'il veut.
Elles gardent le choix du modèle, les bornes de coût, le quota et le registre.

Ce qu'elles perdent est réel : aucun format de sortie n'est promis, donc aucune
validation. Un produit qui attend du JSON d'une tâche libre écrira l'analyseur
défensif que `extract` existe pour lui épargner.

Une tâche inconnue échoue durement — `AI_TASK_UNKNOWN`. Le repli silencieux vers
un modèle générique rouvrirait la porte que l'ADR-0015 ferme, avec en prime une
facture imprévisible.

## 3.1 Le format de sortie est un contrat

Une tâche qui promet du JSON déclare son schéma, et la réponse est **validée
avant d'être rendue**. Une sortie non conforme est réessayée une fois, puis
échoue franchement.

Sans cette validation, chaque produit écrirait son propre analyseur défensif
autour d'une réponse qui « d'habitude » a la bonne forme. Ces analyseurs
divergeraient, et le premier à se tromper le ferait en silence.

---

# 4. Ce que ça coûte, et qui le borne

Deux bornes, et il en faut deux —
[ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md).

**Le quota du plan**, publié par Billing sous la clé `ai_credits_monthly`. AI
compte sa propre ressource, comme Notify compte ses SMS et Storage ses octets.

**Le plafond absolu de la plateforme**, indépendant de tout plan. C'est le
garde-fou contre une boucle ou une clé fuitée : sans lui, une organisation au
plan illimité n'aurait aucune borne.

`ai_credits_monthly` se règle **sans toucher au code** : c'est une clé du
`limits` d'un plan, modifiable par l'API d'opérateur
([ADR-0018](../../04-decisions/adr-0018-platform-operator.md)), figée sur
l'abonnement à chaque période
([ADR-0019](../../04-decisions/adr-0019-granted-limits.md)). Une hausse
s'applique tout de suite, une baisse au renouvellement.

**Une organisation sans abonnement n'a aucun quota d'IA**, et c'est le point le
plus dangereux du module. La règle de la plateforme est qu'un quota borne un
usage *autorisé* — l'autorisation, elle, vient de l'activation du produit côté
Identity. Sans abonnement, le produit n'est pas activé et l'appel est refusé en
amont par `PRODUCT_NOT_ACTIVATED`.

Si cette activation venait à manquer, il ne resterait que le plafond absolu. Ce
n'est pas une redondance de confort : c'est le seul filet quand deux règles se
font confiance mutuellement.

Le quota est vérifié **avant** l'appel sur une estimation, puis le coût réel est
constaté après. Une estimation ne peut pas être exacte — le nombre de jetons
produits n'est connu qu'une fois produits — donc un léger dépassement est
possible, et assumé.

---

# 5. Il n'y a pas *un* compte

AI appelle des fournisseurs par des **comptes** : des lignes en base, pas des
variables d'environnement. Trois comptes Anthropic et deux comptes Mistral sont
cinq lignes.

Le raisonnement est dans
[ADR-0017](../../04-decisions/adr-0017-ai-accounts.md) ; l'essentiel tient en
quatre faits.

**Le catalogue est large, délibérément.** Anthropic, OpenAI, Google, DeepSeek,
Mistral, xAI, Groq, Together, Fireworks, OpenRouter, Azure, Bedrock, et les
serveurs locaux. Treize services pour **deux pilotes** — parce qu'un pilote
porte un *protocole*, pas une entreprise, et que la plupart parlent celui
d'OpenAI. En ajouter un est une ligne de configuration.

**Un compte a un débit plafonné.** Un fournisseur limite les jetons par minute
**par clé**. Un compte unique plafonne toute la plateforme, et le premier produit
qui monte en charge dégrade tous les autres.

**Un produit peut apporter le sien.** Un client entreprise a déjà un contrat, un
tarif négocié et une exigence de résidence des données — exactement ce que
[Storage](../storage/07-external-api.md) admet pour les magasins.

**Le compte se résout une fois, puis se fige** sur la ligne de la génération.
Elle dit où elle est partie, et qui l'a payée.

**Ce qui change tout, sur la clé d'un client :** notre quota ne s'applique pas,
notre coût devient une **estimation**, et la garantie de non-entraînement
devient la sienne. Ces trois conséquences sont détaillées dans
[07-external-api.md](07-external-api.md) §2.

---

# 6. Ce qui est conservé, et ce qui ne l'est pas

**Chaque génération laisse une ligne** : tâche, fournisseur, modèle, jetons
entrants et sortants, coût, latence, issue. Append-only, scellée au niveau du
modèle comme les registres de Payments.

Sans elle, la facture d'un fournisseur en fin de mois est un nombre que personne
ne sait expliquer ni imputer.

**Ni le prompt ni la réponse ne sont stockés par défaut.** Une empreinte suffit
à l'idempotence, les métriques suffisent à la facturation.

Le prompt d'un produit de santé porte des données de santé. Un registre de
prompts serait la cible la plus intéressante de la plateforme — il concentrerait
en clair ce que tous les produits ont de plus sensible. Et il grossirait sans
limite, jusqu'à coûter plus cher que les générations qu'il décrit.

Une tâche peut déclarer une conservation, avec une durée. C'est un choix
conscient, pris tâche par tâche.

---

# 7. Long, donc asynchrone

Une génération prend de trois à soixante secondes, parfois davantage. Un délai
de requête HTTP n'est pas une durée qu'on maîtrise : Render, Cloudflare et le
navigateur du client ont chacun le leur.

`POST /ai/tasks` rend donc `202` et un identifiant. Le résultat s'obtient par
sondage ou par webhook sortant — le même mécanisme que les charges externes de
Payments, pour les mêmes raisons.

Les tâches courtes déclarées comme telles peuvent répondre en `200`
directement, sous une échéance stricte. C'est un confort, jamais une garantie :
un appelant qui suppose le synchrone casse le jour où la tâche s'allonge.

---

# 8. Ce que la version 1 ne fera pas, et pourquoi

**Ni RAG ni embeddings.** Cela suppose un corpus indexé, `pgvector`, et une
stratégie de découpage — c'est-à-dire le métier de **Search**, qui n'existe pas.
Le bâtir ici en ferait un second index concurrent.

**Ni OCR ni analyse de document.** Il faut un modèle de vision, un pipeline
d'extraction et des documents réels pour l'éprouver. Storage vient d'arriver ;
aucun produit n'y a encore déposé une facture scannée.

**Aucune évaluation de qualité.** Le module ne sait pas si une réponse est
bonne. Le prétendre supposerait des jeux de test par tâche, qui appartiennent à
l'exploitation.

**Aucun streaming.** Diffuser une réponse jeton par jeton immobilise un
processus php-fpm pendant toute la génération, ce que l'offre gratuite ne
supporte pas — et le résultat ne passe pas par une file.

Ces manques sont **écrits ici plutôt qu'implémentés à moitié**. C'est
l'enseignement du canal SMS de Notify, et celui du pilote S3 de Storage : du
code qu'on ne peut pas exécuter n'est pas une avance, c'est une dette qui se
croit livrée.
