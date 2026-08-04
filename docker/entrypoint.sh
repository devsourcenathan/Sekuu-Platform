#!/bin/sh
#
# Point d'entrée commun aux trois services Render.
#
#   entrypoint web         nginx + php-fpm
#   entrypoint worker      files : envois Notify, webhooks sortants Payments
#   entrypoint scheduler   tâches planifiées, en processus long
#
# @see docs/06-operations/04-render.md

set -e

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
