# Image de production de Sekuu Platform.
#
# Render n'a pas de runtime PHP natif : le déploiement passe par une image.
# La même sert aux trois services — web, worker, ordonnanceur — avec des
# commandes de démarrage différentes. Une seule image à construire, une seule à
# auditer, et aucun risque que le worker tourne un autre code que le web.
#
# @see docs/06-operations/04-render.md

# --------------------------------------------------------------------------
# Dépendances PHP
# --------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./

# `--no-scripts` : les scripts de Laravel ont besoin du code applicatif, qui
# n'est pas encore copié. Ils sont rejoués plus bas.
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction

# --------------------------------------------------------------------------
# Exécution
# --------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
      nginx \
      supervisor \
      postgresql-dev \
      linux-headers \
      $PHPIZE_DEPS \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      opcache \
      # `pcntl` permet à `queue:work` de recevoir SIGTERM et de terminer la
      # tâche en cours. Sans lui, un redéploiement Render coupe un worker au
      # milieu d'une livraison de paiement.
      pcntl \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS linux-headers \
 && rm -rf /tmp/pear

# OPcache : en production le code ne change pas entre deux démarrages.
# `validate_timestamps=0` évite un `stat()` par fichier et par requête.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'expose_php=0'; \
      echo 'upload_max_filesize=16M'; \
      echo 'post_max_size=16M'; \
    } > /usr/local/etc/php/conf.d/sekuu.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/supervisord-all.conf /etc/supervisord-all.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Composer n'est pas dans l'image d'exécution, et n'a pas à y rester : on
# l'emprunte le temps de construire l'autoloader, puis on le retire.
#
# `--no-scripts` : le script `post-autoload-dump` lance `package:discover`, donc
# amorce Laravel pendant la construction — sans APP_KEY ni base de données.
# Laravel reconstruit ce manifeste tout seul au premier démarrage.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
 && rm /usr/local/bin/composer \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache

# Render fournit le port par la variable PORT. Documentaire uniquement :
# `EXPOSE` ne publie rien.
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["web"]
