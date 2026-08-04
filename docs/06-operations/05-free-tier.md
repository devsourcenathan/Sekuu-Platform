# Tenir sur l'offre gratuite

> **Statut :** Procédure de référence
> **Dernière mise à jour :** Août 2026

Le blueprint de [04-render.md](04-render.md) coûte environ **41 $ par mois**.
L'essentiel vient d'un détail : **Render n'a pas d'offre gratuite pour les
background workers**, et il en faut deux.

Ce document dit comment tenir à zéro, et **ce que cela coûte réellement** —
parce que ce n'est pas gratuit, c'est payé autrement.

---

# 1. La contrainte, puis le contournement

| Ressource | Gratuit ? |
| --- | --- |
| Service web | Oui |
| PostgreSQL | Oui, **mais expire** |
| Key Value (Redis) | Oui, capacité réduite |
| Background worker | **Non** |

Le worker de files et l'ordonnanceur tournent donc **dans le conteneur web** :

```text
Docker command : all
```

`supervisord` y tient quatre processus au lieu de deux — nginx, php-fpm,
`queue:work` et `schedule:work`.

---

# 2. Ce que cela coûte, sans détour

## 2.1 Un service gratuit s'endort

Après quinze minutes sans requête, Render arrête le conteneur. **Tout s'arrête
avec lui** : le worker et l'ordonnanceur aussi.

Trois conséquences, par ordre de gravité :

**Un callback d'agrégateur arrivant sur un service endormi** attend le réveil —
quelques dizaines de secondes. Notch Pay et Tranzak abandonnent avant. Le
callback est perdu.

**La réconciliation, qui devrait le rattraper, dort également.** Elle ne reprend
qu'au réveil du service, donc à la prochaine requête entrante. Un paiement peut
rester non constaté aussi longtemps que personne ne visite l'API.

**Les webhooks sortants** vers un produit externe attendent de même. Le produit
n'apprend son encaissement qu'en sondant.

Autrement dit : sur l'offre gratuite, **le filet de sécurité a les mêmes horaires
que ce qu'il protège**. C'est exactement la défaillance que ce module existe pour
empêcher.

## 2.2 La base de données expire

La base gratuite de Render est supprimée après un délai fixe. Sur des données
monétaires — factures numérotées, registre append-only — c'est disqualifiant.

## 2.3 Un seul conteneur, donc aucune redondance

Un déploiement interrompt tout. Une erreur qui fait boucler le conteneur arrête
aussi l'ordonnanceur, donc la réconciliation, donc le rattrapage.

---

# 3. Ce que l'offre gratuite permet quand même

**Tout valider.** Le parcours complet fonctionne : encaissement réel, callbacks,
registre, remboursement, webhooks sortants. Rien n'est simulé.

C'est parfaitement adapté à ce qui reste à faire aujourd'hui : vérifier que le
déploiement est correct, faire le premier paiement réel, brancher un produit
externe.

**Ce qui ne l'est pas, c'est d'y laisser un vrai client.** Le premier paiement
d'un tiers arrivant pendant un sommeil produit exactement le scénario qu'on a
passé le module entier à rendre impossible : quelqu'un a payé, et la plateforme
l'ignore.

---

# 4. Configuration

Sur le service web, remplacez la commande de démarrage — **avec le chemin
complet** :

```text
/usr/local/bin/entrypoint all
```

Le champ *Docker Command* de Render remplace la commande **entière**, pas
seulement le `CMD` du Dockerfile. Saisir `all` seul fait chercher un binaire de
ce nom, et le conteneur meurt avec `status 128` **sans aucune sortie** — l'échec
le plus difficile à diagnostiquer, puisqu'il ne dit rien.

Puis les variables, comme en production — sauf `QUEUE_CONNECTION`.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://platform.sekuu.com
IDENTITY_JWT_ISSUER=https://platform.sekuu.com
LOG_CHANNEL=stderr

DB_CONNECTION=pgsql
DB_URL=<Internal Database URL>

# Redis gratuit est petit. `database` fonctionne, et la base est déjà là.
QUEUE_CONNECTION=database
CACHE_STORE=database
```

`QUEUE_CONNECTION=database` évite une ressource de plus. La file passe alors par
PostgreSQL : plus lente, mais **durable**, ce qui compte davantage ici. Les
migrations de Laravel créent la table `jobs`.

Attention : ce choix **ne dispense pas** du worker. Une file sans worker
accumule des tâches que personne n'exécute.

## 4.1 Les migrations, faute de mieux

```dotenv
RUN_MIGRATIONS_ON_BOOT=true
```

Sans plan payant, il n'y a **ni `preDeployCommand` ni shell** : aucun moyen de
créer les tables. L'entrypoint les applique donc au démarrage du conteneur.

Ce n'est pas le bon endroit, et il faut savoir pourquoi. Normalement, une
migration tourne **avant** que le trafic ne bascule ; un échec annule le
déploiement, et l'ancienne version continue de servir. Ici, un échec laisse un
conteneur qui redémarre en boucle — visible, mais après coup.

**Ne l'activez jamais avec plusieurs instances.** Deux conteneurs démarrant
ensemble migreraient simultanément, sur des tables monétaires. Tant qu'il n'y a
qu'un service gratuit, le cas ne peut pas se présenter — c'est la seule raison
pour laquelle cette option est acceptable.

Retirez-la au passage au payant, et remettez `preDeployCommand`.

---

# 5. Quand passer au payant

Trois seuils, et le premier suffit.

**Avant le premier client réel.** Pas avant le premier paiement — celui-là,
faites-le vous-même sur l'offre gratuite pour valider la chaîne. Avant que
quelqu'un d'autre ne paie.

**Avant de brancher un produit externe.** Sekuu Learn appellerait une API qui
dort, et ses clients paieraient sans obtenir leur formation.

**Avant que la base gratuite n'expire.** Sinon la question ne se pose plus : les
données sont parties.

Au passage, retirez `RUN_MIGRATIONS_ON_BOOT` et remettez le `preDeployCommand` :
un échec de migration doit annuler un déploiement, pas se découvrir en
production.

Le minimum viable payant n'est pas le blueprint complet. Un service web payant —
qui ne dort pas — plus une base payante suffisent, en gardant la commande `all`.
Les deux workers séparés ne deviennent utiles que le jour où le web est répliqué.
