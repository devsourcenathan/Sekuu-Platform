# Déployer sur Render

> **Statut :** Procédure de référence
> **Dernière mise à jour :** Août 2026

Ce document ne remplace pas [03-deployment.md](03-deployment.md) : il dit ce que
Render change, et ce qu'il faut prendre en compte **avant** de commencer.

---

# 1. Les cinq choses qui changent tout

## 1.1 Render n'a pas de runtime PHP

Node, Python, Ruby, Go, Rust — pas PHP. Le déploiement passe **obligatoirement**
par une image Docker.

Elle est fournie : [`Dockerfile`](../../Dockerfile), nginx + php-fpm, avec
`pdo_pgsql`, `redis`, `opcache` et `pcntl`. Ni `intl` ni `bcmath` : les montants
sont des entiers, et aucun formatage localisé n'est fait côté serveur.

## 1.2 Le disque est éphémère

**C'est le point le plus dangereux pour cette plateforme.**

Chaque déploiement repart d'un conteneur neuf. Tout ce qui a été écrit dans
`storage/` disparaît — y compris les clés de signature JWT que
`identity:generate-keys` y place par défaut.

Conséquence si on l'ignore : à chaque déploiement, une nouvelle paire est
générée, et **tous les tokens en circulation deviennent invalides d'un coup**.
Tous les utilisateurs déconnectés, sans explication.

```bash
php artisan identity:generate-keys --show
```

`--show` affiche la paire au lieu de l'écrire. Recopiez-la dans
`IDENTITY_JWT_PRIVATE_KEY` et `IDENTITY_JWT_PUBLIC_KEY`.

Corollaire : `LOG_CHANNEL=stderr`. Un fichier dans `storage/logs` ne survivrait
pas plus longtemps.

## 1.3 Redis doit être en `noeviction`

Une file n'est pas un cache.

Sous pression mémoire, la politique par défaut (`allkeys-lru`) **supprime** des
clés — donc des tâches en attente. Ici, ce sont des livraisons d'encaissement :
un produit externe n'apprendrait jamais qu'il a été payé, et son client resterait
sans service.

`render.yaml` pose `maxmemoryPolicy: noeviction`. Vérifiez-le après création.

## 1.4 Le worker est un service à part

Render n'exécute qu'un processus par service. Le worker de files et
l'ordonnanceur sont donc deux services distincts, qui partagent **la même
image** que le web — le worker ne peut pas tourner un autre code.

`deploy/sekuu-worker.conf` (Supervisor) ne sert pas ici : Render redémarre
lui-même un processus qui meurt.

## 1.5 L'ordonnanceur boucle au lieu d'être appelé

Pas de crontab. Render facture chaque exécution d'un service cron, et
`schedule:run` à la minute en produirait 43 200 par mois.

`schedule:work` boucle en interne, dans un service worker. Une seule
instance : `payments:reconcile` interroge les agrégateurs, et deux exécutions
concurrentes se disputeraient le même verrou d'encaissement.

---

# 2. Ce que Render résout

**Le gestionnaire de secrets.** Les variables d'environnement de Render en
tiennent lieu : chiffrées au repos, jamais dans le dépôt, modifiables sans
redéployer le code.

`php artisan env:encrypt` devient donc inutile ici. La question posée plus tôt —
faut-il payer un gestionnaire de secrets — trouve sa réponse : non, celui de
l'hébergeur suffit tant qu'une seule personne y accède.

Les valeurs marquées `sync: false` dans `render.yaml` ne sont **pas** dans le
dépôt : Render les demande à la création, et elles n'en sortent plus.

---

# 3. Marche à suivre

## 3.1 Avant de connecter Render

```bash
php artisan key:generate --show            # APP_KEY
php artisan identity:generate-keys --show  # la paire JWT
```

Gardez les trois valeurs de côté. **Elles ne se régénèrent pas** : `APP_KEY`
rendrait illisibles les données chiffrées, la paire JWT déconnecterait tout le
monde.

## 3.2 Créer le blueprint

Sur Render : **New → Blueprint**, puis le dépôt. `render.yaml` crée la base, le
Redis et les trois services.

Render demandera les valeurs `sync: false` — les trois secrets ci-dessus, les
identifiants des agrégateurs, ceux de Resend.

## 3.3 Le groupe de variables

Créez `sekuu-runtime` et attachez-y les mêmes valeurs que le service web. Les
recopier trois fois est le meilleur moyen de les voir diverger — et un worker
qui pointe sur une autre base est un incident silencieux.

## 3.4 Vérifier

```bash
curl https://sekuu-api.onrender.com/api/v1/health
curl https://sekuu-api.onrender.com/api/v1/payments/health
```

Le second doit répondre `can_collect: true` avec les deux agrégateurs.

Dans les journaux du service `sekuu-scheduler`, une ligne doit apparaître à
chaque minute. S'il n'y en a aucune, rien ne rattrape les callbacks perdus.

## 3.5 Les URL de callback

C'est ici que le domaine de production devient réel :

```text
https://sekuu-api.onrender.com/api/v1/payments/webhooks/notchpay
https://sekuu-api.onrender.com/api/v1/payments/webhooks/tranzak
```

À enregistrer dans les deux tableaux de bord. Si vous branchez un domaine
personnalisé, faites-le **avant** — changer l'URL ensuite oblige à repasser
partout, et une transaction en cours porte l'ancienne, figée dans son payload.

---

# 4. Le déploiement lui-même

`preDeployCommand` s'exécute **avant** que le trafic ne bascule :

```bash
php artisan migrate --force && php artisan payments:verify
```

Un échec annule le déploiement, et l'ancienne version continue de servir. Un
`.env` mal recopié échoue donc là, plutôt qu'au premier paiement d'un client.

Les caches — configuration, routes, événements — sont construits **au démarrage
du conteneur**, pas à la construction de l'image : les variables de Render sont
injectées au lancement, et les figer à la construction embarquerait la
configuration d'un autre environnement.

---

# 5. Ce qu'il faut savoir des offres

**Le plan gratuit met le service en veille** après quinze minutes d'inactivité.
Un callback d'agrégateur arrivant sur un service endormi attend le réveil, et
peut expirer. La réconciliation rattrape — mais elle suppose que l'ordonnanceur
tourne, or lui aussi dort.

Pour une plateforme qui encaisse, le plan payant n'est pas un confort.

**La base gratuite expire au bout de 90 jours.** Sur des données monétaires,
c'est disqualifiant.

**Une seule instance web** tant que rien ne l'exige. La montée en charge
horizontale fonctionne — les sessions sont des JWT sans état — mais l'ordonnanceur
doit rester **unique**.

---

# 6. Ce qui reste vrai

Après le déploiement, il reste ce qu'aucune infrastructure ne remplace : **le
premier paiement réel**, avec votre propre numéro et un petit montant.

C'est la seule vérification qui prouve que les URL enregistrées dans les
tableaux de bord sont les bonnes — voir
[01-go-live.md § 7](01-go-live.md#7-le-premier-paiement-réel).
