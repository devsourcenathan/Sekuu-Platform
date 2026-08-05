#!/bin/sh
#
# Point d'entrée commun aux trois services Render.
#
#   entrypoint web         nginx + php-fpm
#   entrypoint worker      files : envois Notify, webhooks sortants Payments
#   entrypoint scheduler   tâches planifiées, en processus long
#   entrypoint all         les trois dans un conteneur — offre gratuite
#
# @see docs/06-operations/04-render.md

set -e

# Render remplace la commande **entiere**, pas seulement le CMD. La commande a
# saisir dans son interface est donc `/usr/local/bin/entrypoint all`, et non
# `all` seul.
#
# Ce cas est absorbe ici : si le premier argument est le chemin de ce script,
# on le retire et on continue. Le meme reglage fonctionne alors que Render
# remplace le CMD ou la commande complete, sans qu'un integrateur ait a savoir
# lequel.
case "${1:-}" in
    */entrypoint) shift ;;
esac

echo "[entrypoint] mode=${1:-web}"

# --------------------------------------------------------------------------
# Migrations, au demarrage — **sur demande explicite**
# --------------------------------------------------------------------------
# Normalement, les migrations tournent avant que le trafic ne bascule
# (`preDeployCommand`), et un echec annule le deploiement sans que personne ne
# voie une page cassee.
#
# L'offre gratuite de Render n'offre ni cette etape ni un shell : sans cette
# option, il n'existe aucun moyen de creer les tables.
#
# **Ne l'activez jamais avec plusieurs instances.** Deux conteneurs demarrant
# ensemble migreraient en meme temps, sur des tables monetaires.
if [ "${RUN_MIGRATIONS_ON_BOOT:-false}" = "true" ]; then
    php artisan migrate --force
fi

# --------------------------------------------------------------------------
# Le magasin par defaut
# --------------------------------------------------------------------------
# Meme raison que ci-dessus : sans shell, il n'existe aucun autre moyen de poser
# la premiere destination. Et il n'y aura pas de route pour cela — une
# destination de la plateforme porte les identifiants de nos comptes cloud et
# sert toutes les organisations.
#
# Idempotent : ne fait rien si le magasin existe deja, ce qui est le cas a
# chaque redemarrage et a chaque reveil apres sommeil.
#
# Ne fait **jamais** echouer le demarrage : un magasin injoignable ne doit pas
# empecher la plateforme de repondre. La ligne reste `unverified`, et l'epreuve
# quotidienne devient la reprise.
if [ -n "${STORAGE_DEFAULT_SLUG:-}" ]; then
    php artisan storage:destination --from-env || true
fi

# --------------------------------------------------------------------------
# Caches compilés
# --------------------------------------------------------------------------
# Au **démarrage**, pas à la construction : les variables d'environnement de
# Render sont injectées au lancement du conteneur. Les figer à la construction
# embarquerait la configuration d'un autre environnement dans l'image.
#
# L'inverse du développement, où un cache oublié fait tourner la suite de tests
# avec les identifiants du `.env` — d'où le garde-fou dans tests/TestCase.php.
php artisan config:cache
php artisan route:cache
php artisan event:cache

case "${1:-web}" in

    web)
        # Render impose le port par la variable PORT.
        #
        # `sed` plutot qu'`envsubst` : le second vient du paquet `gettext`, une
        # dependance de plus pour remplacer un seul jeton. `sed` est dans
        # busybox, donc toujours la.
        sed "s|\${PORT}|${PORT:-8080}|g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

        exec supervisord -c /etc/supervisord.conf
        ;;

    all)
        # nginx, php-fpm, le worker et l'ordonnanceur ensemble.
        #
        # Pour l'offre gratuite de Render, qui n'a pas de background worker.
        # Ce que ce choix coute est decrit dans
        # docs/06-operations/05-free-tier.md — l'essentiel etant qu'un service
        # gratuit s'endort, et que rien ne tourne pendant son sommeil.
        export PORT="${PORT:-8080}"
        sed "s|\${PORT}|${PORT}|g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

        exec supervisord -c /etc/supervisord-all.conf
        ;;

    worker)
        # `--max-time=3600` : un processus de longue durée garde en mémoire la
        # configuration chargée à son démarrage. Le faire redémarrer chaque
        # heure évite qu'il ignore un déploiement.
        #
        # `--tries=3` s'accorde au `backoff` des tâches — 1 min, 5 min, 30 min,
        # 2 h, 6 h pour une livraison de paiement.
        exec php artisan queue:work redis \
            --sleep=3 \
            --tries=3 \
            --max-time=3600 \
            --timeout=90
        ;;

    scheduler)
        # `schedule:work` boucle en interne plutôt que d'être appelé par une
        # crontab : Render facture chaque exécution d'un service cron, et une
        # tâche à la minute en produirait 43 200 par mois.
        #
        # Un seul processus, jamais deux : `payments:reconcile` interroge les
        # agrégateurs, et deux exécutions concurrentes se disputeraient le même
        # verrou d'encaissement.
        exec php artisan schedule:work
        ;;

    *)
        exec "$@"
        ;;
esac
