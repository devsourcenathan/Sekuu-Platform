#!/usr/bin/env bash
#
# Redéploiement de Sekuu Platform.
#
# Idempotent : peut être relancé sans dommage. Ne fait **rien** qui appartienne
# au premier déploiement — ni `key:generate`, ni `identity:generate-keys`, dont
# le rejeu invaliderait respectivement les données chiffrées et tous les tokens
# en circulation.
#
# @see docs/06-operations/03-deployment.md

set -euo pipefail

cd "$(dirname "$0")/.."

# Sortir de maintenance quoi qu'il arrive : une migration qui échoue ne doit pas
# laisser le service fermé sans que personne ne le sache.
trap 'php artisan up || true' EXIT

echo "→ Maintenance"
# `--retry` renseigne l'en-tête Retry-After : les agrégateurs réessaient leurs
# callbacks au lieu de les abandonner.
php artisan down --retry=15

echo "→ Code"
git pull --ff-only

echo "→ Dépendances"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "→ Migrations"
php artisan migrate --force

echo "→ Caches"
# En production, la configuration **doit** être en cache : c'est la différence
# entre lire dix fichiers à chaque requête ou aucun.
#
# L'inverse du développement, où un cache oublié fait tourner la suite de tests
# avec les identifiants du .env — d'où le garde-fou dans tests/TestCase.php.
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "→ Workers"
# Sans cela, une tâche enfilée avec l'ancien code s'exécuterait avec le nouveau.
php artisan queue:restart

echo "→ Vérification des identifiants de paiement"
# Lecture seule, aucun paiement déclenché. Échoue si un identifiant n'est pas
# accepté ou si l'environnement ne correspond pas — avant que le service ne
# rouvre.
php artisan payments:verify

echo "→ Ouverture"
php artisan up
trap - EXIT

echo
echo "Déployé. Vérifiez maintenant :"
echo "  curl -s https://\$APP_DOMAIN/api/v1/payments/health"
echo "  php artisan schedule:list"
echo "  supervisorctl status sekuu-worker:*"
