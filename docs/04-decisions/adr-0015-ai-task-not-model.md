# ADR-0015 — L'appelant décrit une tâche, jamais un modèle

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Le module AI doit exposer des capacités génériques — résumer, traduire, extraire,
générer un quiz — à des produits qui ne partagent pas sa base de code.

La forme évidente est celle de toutes les API d'IA du marché :

```json
{ "model": "claude-sonnet-4", "messages": [...], "temperature": 0.7 }
```

C'est ce que font Anthropic, OpenAI et Mistral, et c'est ce qu'un intégrateur
attend. Ce n'est pas ce que nous devons faire.

## Le problème

**Un nom de modèle est un couplage à un fournisseur, à un prix, et à une date.**

Les trois se cassent séparément, et aucun ne prévient.

Un modèle est **retiré** tous les douze à dix-huit mois. Le jour où
`claude-sonnet-4` disparaît, chaque produit qui l'a écrit dans son code cesse de
fonctionner — et la plateforme, qui pourrait basculer en une ligne, ne peut rien
puisque le choix ne lui appartient pas.

Un modèle a un **prix**, et l'appelant ne le connaît pas. Laisser un produit
choisir revient à le laisser choisir combien la plateforme dépense. Un
intégrateur pressé prendra le plus gros modèle « pour être sûr », sur une tâche
qu'un modèle dix fois moins cher traite aussi bien.

Un modèle a des **aptitudes**. « Traduire en fulfulde » et « extraire un montant
d'une facture » n'appellent ni le même modèle, ni la même température, ni le même
format de sortie. Ce savoir appartient à celui qui exploite le service, pas à
celui qui l'appelle.

## Décision

**L'API accepte une `task`, jamais un `model`.**

```json
{ "task": "summarize", "input": "…", "language": "fr" }
```

`config/ai.php` associe chaque tâche à une **chaîne de modèles** — un préféré,
un repli — avec ses paramètres. Changer de fournisseur, changer de modèle,
corriger une température : une ligne de configuration, aucun produit touché.

C'est l'exacte transposition de l'invariant de Payments. Là-bas, *seul le
propriétaire de l'objet nomme son prix* : le montant n'existe dans aucune
signature accessible à l'appelant. Ici, **seule la plateforme nomme le
modèle** : il n'existe dans aucun champ de la requête.

### Le catalogue de tâches est fermé

Une tâche inconnue échoue durement, `AI_TASK_UNKNOWN`. Pas de repli vers un
modèle générique : ce serait rouvrir la porte que cette décision ferme, avec en
prime une facture imprévisible.

### Ce que l'appelant contrôle malgré tout

| Il peut | Il ne peut pas |
| --- | --- |
| Choisir la tâche | Choisir le modèle |
| Fournir l'entrée et la langue | Fixer la température, le nombre de jetons |
| Demander un format de sortie déclaré par la tâche | Inventer un format |
| Poser une clé d'idempotence | Contourner le quota ou le plafond |

### Une tâche peut être libre

`prompt` est une tâche du catalogue comme les autres : l'appelant envoie du
texte, reçoit du texte.

Cela ne contredit pas ce qui précède, et la nuance est le cœur de cette
révision. **Ce que l'ADR refuse, c'est que l'appelant nomme le modèle** — pas
qu'il écrive librement. Une tâche libre conserve chaque garantie :

* la plateforme choisit le modèle, et peut en changer ;
* la tâche borne l'entrée, la sortie et donc le coût ;
* le quota, le plafond et le registre s'appliquent sans exception ;
* la clé d'API doit porter `prompt` dans sa liste blanche.

Ce qu'elle perd, en revanche, est réel : **aucun format de sortie n'est promis**,
donc aucune validation. Un produit qui attend du JSON d'une tâche libre écrira
son propre analyseur défensif — précisément ce que `extract` existe pour éviter.

Trois variantes plutôt qu'une, parce que « libre » ne dit rien du compromis
voulu :

| Tâche | Modèle | Pour |
| --- | --- | --- |
| `prompt` | équilibré | Le cas courant |
| `prompt-fast` | petit, rapide | Volume, latence |
| `prompt-deep` | grand | Raisonnement, rédaction longue |

Ce sont **trois noms de la plateforme**, pas trois modèles nommés par
l'appelant. Le jour où le modèle derrière `prompt-deep` est retiré, personne ne
change une ligne.

## Conséquences

**Un produit avec un besoin exotique doit faire ajouter sa tâche.** C'est le
coût réel, et il tombe sur l'intégrateur. C'est aussi le point : une tâche
ajoutée est une tâche dont quelqu'un a vérifié le modèle, le prix et le format
de sortie.

**La plateforme peut arbitrer le coût sans prévenir personne.** Basculer
`summarize` d'un grand modèle vers un petit ne casse aucun contrat — la réponse
reste une réponse. C'est ce qui rend une facture d'IA pilotable.

**Le format de sortie devient un contrat.** Une tâche déclare son schéma, et la
réponse est validée avant d'être rendue. Un produit qui reçoit du JSON n'a pas à
se demander si le modèle a bien voulu en produire ce jour-là — c'est le module
qui réessaie, ou qui échoue franchement.

**Nous portons la dette de dépréciation.** Quand un modèle est retiré, c'est
notre problème, pas celui de nos produits. C'est exactement ce que nous
vendons.

**On ne peut pas comparer deux modèles depuis un produit.** Un produit qui
voudrait mesurer la qualité de deux modèles sur sa tâche ne le peut pas. C'est
assumé : cette évaluation appartient à l'exploitation, avec les jeux de test qui
vont avec.

## Ce qui a été écarté

**Un champ `model` optionnel**, ignoré par défaut et honoré pour les appelants
de confiance. Un champ optionnel devient obligatoire le jour où un intégrateur
en dépend, et « de confiance » n'est pas une propriété qu'on sait vérifier. La
porte se rouvrirait entièrement en un an.

**Un champ `quality` — `fast`, `balanced`, `best`.** Plus séduisant, et
défendable : l'appelant exprime un compromis sans nommer de fournisseur. Écarté
pour la version 1 parce que ces trois mots n'ont de sens que rapportés à un
prix, que l'appelant ne voit toujours pas. Il reviendra peut-être, adossé à un
coût affiché.

**Exposer directement l'API du fournisseur en mandataire.** Ce serait cesser
d'être une plateforme pour devenir un proxy facturé, sans quota, sans registre,
et sans aucun moyen de changer de fournisseur.
