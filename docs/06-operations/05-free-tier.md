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

## 4.2 Le magasin, faute de mieux aussi

Même problème, même remède. Une destination de stockage est une **ligne en
base** et se pose normalement avec `storage:destination` — ce que l'absence de
shell interdit.

```dotenv
STORAGE_DEFAULT_SLUG=r2-principal
STORAGE_DEFAULT_PRESET=r2
STORAGE_DEFAULT_BUCKET=sekuu-prod-files
STORAGE_DEFAULT_ACCOUNT_ID=…
STORAGE_DEFAULT_KEY=…
STORAGE_DEFAULT_SECRET=…
```

Au démarrage, le conteneur pose cette destination si elle n'existe pas encore,
et l'éprouve : écrire un objet témoin, le relire, l'effacer. **Idempotent** — il
redémarre à chaque déploiement, et à chaque réveil après sommeil.

Il n'y aura **pas** de route pour cela, même en payant. Une destination de la
plateforme porte les identifiants de nos comptes cloud et sert toutes les
organisations ; l'exposer reviendrait à confier cette infrastructure à qui
détient un jeton d'administration.

### Corriger après un échec

Redéployer suffit. Tant que la destination est `unverified`, l'amorçage
**réapplique** les variables et remet à l'épreuve : elle n'a jamais rien porté,
la corriger ne peut rien casser.

Un magasin qui **sert**, lui, ne se laisse jamais réécrire par l'environnement.
Une variable oubliée le repointerait vers un autre compte, et les fichiers déjà
posés deviendraient introuvables sans qu'aucune erreur ne le dise.

La règle tient en une phrase : **l'environnement amorce, et répare ce qui n'a
jamais servi ; il ne touche jamais à un magasin qui fonctionne.**

Le journal du démarrage porte alors le message brut du fournisseur, tronqué —
c'est le seul endroit où il apparaisse, et le seul lisible sans shell.

### Un échec ne bloque jamais le démarrage

Contrairement aux migrations. Un magasin injoignable laisse la ligne
`unverified` : aucun fichier n'est déposable, mais l'authentification, les
paiements et les notifications continuent — ils n'en dépendent pas.

Et la reprise est automatique : **l'épreuve quotidienne de 4 h fait basculer la
destination d'elle-même** le jour où les identifiants deviennent corrects, sans
nouveau déploiement. Les journaux du démarrage le disent franchement :

```
[storage] magasin « r2-principal » posé mais NON ÉPREUVÉ — credentials_rejected.
```

## 4.3 La clé d'IA, pour la même raison

Même mécanisme, et il a été écrit **parce que** celui du magasin avait manqué.

```dotenv
AI_DEFAULT_SLUG=plateforme-anthropic
AI_DEFAULT_PRESET=anthropic
AI_DEFAULT_MODELS=claude-haiku-4-5
AI_DEFAULT_KEY=…
```

`AI_DEFAULT_MODELS` est une liste, et **le premier est celui de l'épreuve**.
Mettez-y le moins cher : contrairement à celle du magasin, cette épreuve
consomme de vrais jetons — c'est une génération réelle d'un jeton, parce qu'un
compte peut lister ses modèles sans avoir de crédit.

Elle est donc **quotidienne, à 4 h 30**, et non horaire.

Les trois règles sont identiques à celles du magasin : idempotent, un échec ne
bloque pas le démarrage, et l'environnement répare ce qui n'a jamais servi sans
jamais toucher à un compte qui fonctionne.

```
[ai] compte « plateforme-anthropic » posé mais NON ÉPREUVÉ — credentials_rejected.
```

Il n'y aura **pas** de route pour cela non plus, et la raison est plus forte
encore : un magasin fuité se lit, une clé d'IA fuitée **se dépense**.

### Le rattrapage des PDF n'a besoin de personne

`billing:invoice-pdf` est ordonnancée chaque nuit à 3 h 15. Les factures émises
avant l'arrivée du module — dont les vôtres — obtiennent leur document sans
qu'aucune commande soit lancée à la main.

C'est vrai **tant que le conteneur est éveillé à cette heure-là**. Sur l'offre
gratuite il dort, et une visite quelconque suffit à le réveiller : le
rattrapage se fera la nuit suivante. Un `GET /invoices/{id}/download` le
provoque de toute façon sur-le-champ, pour cette facture-là.

Retirez-la au passage au payant, et remettez `preDeployCommand`.

---

# 5. Constater un décaissement sans shell

L'offre gratuite n'a pas de shell, et `payments:refund` est un acte d'opérateur :
un remboursement resterait indéfiniment `pending`.

La commande peut être lancée **depuis un poste**, pointée sur la base de
production :

```powershell
$env:DB_URL="<Internal Database URL>"; php artisan payments:refund
$env:DB_URL="<...>"; php artisan payments:refund <id> --reference=<ref-du-transfert>
```

`CredentialGuard` ne s'y oppose pas, et ce n'est pas un oubli : il se déclenche
à la résolution des **agrégateurs**, or `SettleRefund` ne dépend que du registre
des objets payables. Aucun chemin ne mène à `charge()` — la commande ne peut pas
débiter qui que ce soit.

**Deux précautions.** Préfixer la commande plutôt que modifier son `.env` : la
variable disparaît avec le terminal. Et ne rien lancer d'autre dans cette
session — `migrate` ou `db:seed` toucheraient la production.

---

# 6. Quand passer au payant

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
