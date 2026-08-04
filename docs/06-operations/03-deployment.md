# Déploiement

> **Statut :** Procédure de référence
> **Dernière mise à jour :** Août 2026

Deux procédures distinctes : le **premier** déploiement, qui crée des secrets
qu'on ne recrée jamais, et les **suivants**, automatisés par
[`deploy/deploy.sh`](../../deploy/deploy.sh).

Les conditions à réunir sont dans [01-go-live.md](01-go-live.md). Ce document
suppose qu'elles le sont.

> Sur un hébergeur à **disque éphémère** — Render, Fly, Heroku — lisez d'abord
> [04-render.md](04-render.md) : les clés JWT et les journaux n'y survivent pas
> à un déploiement, et l'ordonnanceur ne s'installe pas par une crontab.

---

# 1. Ce qu'on ne fait qu'une fois

Trois secrets. **Les régénérer plus tard casse quelque chose**, et c'est la
seule raison pour laquelle cette section est séparée.

## 1.1 `APP_KEY`

```bash
php artisan key:generate
```

Chiffre les données sensibles en base. La régénérer les rend **définitivement
illisibles** — il n'existe aucun moyen de revenir en arrière.

## 1.2 Les clés de signature JWT

```bash
php artisan identity:generate-keys --show
```

`--show` les affiche au lieu de les écrire, et c'est ce qu'il faut en
production : recopiez-les dans `IDENTITY_JWT_PRIVATE_KEY` et
`IDENTITY_JWT_PUBLIC_KEY`.

**Pourquoi pas les fichiers.** Sans `--show`, la commande écrit dans
`storage/app/private/identity/`. Un déploiement par releases successives
remplace ce répertoire : les clés disparaîtraient, une nouvelle paire serait
générée, et **tous les tokens en circulation deviendraient invalides d'un coup**
— tous les utilisateurs déconnectés, sans explication.

Les passer par l'environnement rend ce risque impossible.

La rotation est prévue à 90 jours, et se fait par une paire de secours plutôt
que par un remplacement sec — voir
[security.md](../02-standards/security.md).

## 1.3 Le compte de base de données

Un rôle PostgreSQL dédié, propriétaire du schéma. Jamais `postgres`.

---

# 2. Le fichier d'environnement

Ce qui change par rapport à `.env.example` :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://platform.sekuu.com

QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Notch Pay : clé **sans** préfixe test_
# Tranzak  : https://dsapi.tranzak.me
```

`APP_DEBUG=false` n'est pas cosmétique : à `true`, une trace d'exception peut
exposer des fragments de configuration, identifiants de paiement compris.

## 2.1 Les sous-domaines

`SEKUU_DOMAIN_*` **vides** fait répondre tous les modules sur un seul hôte.
C'est ce qu'il faut pour un premier déploiement : un certificat, un vhost, un
enregistrement DNS.

Les renseigner suppose autant de sous-domaines réels. C'est le découpage cible,
mais rien ne le rend urgent — et l'ordre inverse est plus difficile à rattraper.

## 2.2 Chiffrer

```bash
php artisan env:encrypt --env=production
```

Le `.env.production.encrypted` peut être versionné ; le serveur le déchiffre
avec `LARAVEL_ENV_ENCRYPTION_KEY`. Dix secrets à protéger deviennent **un
seul**.

Sa limite : l'application écrit quand même un `.env` en clair sur le serveur.
Cela protège le dépôt, les sauvegardes et le transport — pas la machine. D'où
`chmod 600`.

---

# 3. Premier déploiement

```bash
git clone <dépôt> /var/www/sekuu-platform && cd $_
composer install --no-dev --optimize-autoloader

# Le fichier d'environnement, section 2
php artisan key:generate
php artisan identity:generate-keys --show   # à recopier dans le .env

php artisan migrate --force
php artisan storage:link

php artisan config:cache && php artisan route:cache && php artisan event:cache
```

Puis les deux processus :

```bash
sudo cp deploy/sekuu-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start sekuu-worker:*
sudo cp deploy/crontab /etc/cron.d/sekuu
```

Et la vérification, en lecture seule :

```bash
php artisan payments:verify
php artisan schedule:list
curl -s https://platform.sekuu.com/api/v1/payments/health
```

## 3.1 Le serveur web

Racine sur `public/`, jamais sur le dépôt. Tout le reste — `.env`, `storage/`,
les clés — doit être hors d'atteinte du web.

Propriétaire `www-data` sur `storage/` et `bootstrap/cache/` uniquement. Le
reste du dépôt n'a pas à être modifiable par le serveur.

---

# 4. Déploiements suivants

```bash
./deploy/deploy.sh
```

Maintenance, code, dépendances, migrations, caches, redémarrage des workers,
vérification des identifiants, ouverture.

Trois choses méritent d'être connues.

**`php artisan down --retry=15`** renseigne l'en-tête `Retry-After`. Les
agrégateurs réessaient alors leurs callbacks au lieu de les abandonner — un
déploiement pendant un paiement ne le perd pas.

**`queue:restart` n'est pas optionnel.** Sans lui, une tâche enfilée avec
l'ancien code s'exécute avec le nouveau.

**`payments:verify` tourne avant l'ouverture.** Un `.env` mal recopié échoue là,
service encore fermé, plutôt qu'au premier paiement d'un client.

Le script sort de maintenance **quoi qu'il arrive** : une migration qui échoue
ne doit pas laisser le service fermé sans que personne ne le sache.

---

# 5. Le cache de configuration, dans les deux sens

C'est le même mécanisme, souhaitable d'un côté et dangereux de l'autre.

| | |
| --- | --- |
| **En production** | Obligatoire. Sans lui, dix fichiers sont relus à chaque requête. |
| **En développement** | Interdit. `env()` n'étant plus appelé, les neutralisations de `phpunit.xml` sont ignorées, et **la suite tourne avec les identifiants du `.env`**. |

La même règle vaut pour les caches de **routes** et d'**événements**, pour une
raison moins coûteuse mais aussi trompeuse : des routes figées avant un
changement de configuration font passer au vert un test de sous-domaine qui
devrait échouer. Constaté.

Le second cas s'est produit. `tests/TestCase.php` refuse désormais de démarrer
si l'un des trois caches existe, avant qu'un seul test ne s'exécute.
`composer test` et la CI appellent `optimize:clear`.

---

# 6. Revenir en arrière

```bash
php artisan down --retry=15
git checkout <version-précédente>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart
php artisan up
```

**Les migrations ne sont pas annulées**, et ne doivent pas l'être à la légère :
`payment_transactions` et `credit_entries` sont append-only. Un `migrate:rollback`
sur une table monétaire détruit des écritures qui ne se reconstruisent pas.

Une migration qui pose problème se corrige par une **nouvelle** migration.
C'est la même règle que pour le registre : on n'efface pas, on ajoute.

---

# 7. Après le déploiement

Le premier paiement réel, avec votre propre numéro et un petit montant —
[01-go-live.md § 7](01-go-live.md#7-le-premier-paiement-réel).

C'est la seule vérification que ni les tests, ni `payments:verify`, ni les bacs
à sable ne remplacent : elle seule prouve que les URL de callback enregistrées
dans les tableaux de bord sont les bonnes.
