# Sekuu Platform

Socle technique commun de l'écosystème Sekuu : une plateforme de services partagés (identité, vérification, notifications, facturation, stockage, IA, recherche, analytics) sur laquelle s'appuient tous les produits SaaS Sekuu.

Développé comme un **monolithe modulaire Laravel** — une application, une base PostgreSQL, des modules aux frontières strictes, chacun exposé sur son propre sous-domaine et extractible plus tard sans changer d'URL.

📖 **La documentation est dans [`docs/`](docs/README.md)** — commencez par le [récapitulatif](docs/RECAP.md) pour l'état réel, ou la [vision](docs/01-overview/vision.md) puis l'[architecture](docs/01-overview/architecture.md) pour la cible.

🔌 **API Identity** — contrat [`Modules/Identity/openapi.yaml`](Modules/Identity/openapi.yaml) · [collection Postman](postman/)

---

## Prérequis

* PHP 8.3+
* Composer 2
* PostgreSQL 16+
* Node 20+ (outillage front uniquement)

## Installation

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

Créez la base, renseignez `DB_*` dans `.env`, puis :

```bash
php artisan migrate
```

## Démarrer

```bash
php artisan serve
```

L'API répond sur `http://localhost:8000/api/v1/…`.

Les envois de messages sont **asynchrones** : sans worker, ils restent en file et aucun email ne part.

```bash
php artisan queue:work --queue=notifications,default
```

Vérification rapide :

```bash
curl http://localhost:8000/api/v1/health
```

## Tests

La suite tourne sur **PostgreSQL**, comme la production — `citext`, les index partiels et les contraintes `CHECK` ne sont pas simulables sur SQLite, et une divergence entre les deux moteurs ne se révélerait qu'en production.

Créez la base de test une fois :

```bash
createdb -U postgres sekuu_testing
```

```bash
php artisan test
```

## Style de code

```bash
./vendor/bin/pint
```

---

## Organisation du code

```text
app/Platform/          Socle commun à tous les modules
├── Http/              Enveloppe de réponse, request_id
├── Exceptions/        Traduction des exceptions en erreurs normalisées
└── Support/           ModuleServiceProvider

Modules/               Un dossier par domaine de la plateforme
└── Identity/
    ├── Application/   Cas d'usage
    ├── Domain/        Modèles et règles du domaine
    ├── Infrastructure/Implémentations techniques
    ├── Presentation/  Contrôleurs, requêtes, ressources
    ├── Routes/        api_v1.php
    ├── Database/      Migrations du module
    └── Tests/
```

### Ajouter un module

1. Créer `Modules/<Nom>/` avec la même arborescence.
2. Écrire `<Nom>ServiceProvider` étendant `App\Platform\Support\ModuleServiceProvider`.
3. L'enregistrer dans [`bootstrap/providers.php`](bootstrap/providers.php).
4. Déclarer son sous-domaine dans [`config/sekuu.php`](config/sekuu.php) et `.env`.

Routes, migrations et traductions sont découvertes automatiquement.

## Règles non négociables

Elles sont détaillées dans [`docs/02-standards/`](docs/02-standards/) et vérifiées par les tests :

* Toute route publique est versionnée (`/api/v1/…`). Aucune exception.
* Toute réponse suit l'enveloppe commune et porte un `request_id`.
* Toute erreur utilise un code du [catalogue](docs/02-standards/error-codes.md) — jamais un message libre.
* `snake_case` pour les colonnes comme pour les clés JSON.
* UUID comme identifiants, dates ISO8601 en UTC.
* Un module ne lit jamais les tables d'un autre module.

## Sous-domaines

En local, laisser les variables `SEKUU_DOMAIN_*` vides : les routes répondent sur n'importe quel hôte, sans configuration DNS.

En production, chaque module reçoit le sien :

```text
SEKUU_DOMAIN_IDENTITY=identity.sekuu.com
SEKUU_DOMAIN_VERIFY=verify.sekuu.com
```

## État d'avancement

| Module | État |
| --- | --- |
| **Identity** | **Complet** — authentification, OAuth, organisations, workspaces, invitations, sessions, mots de passe, vérification d'adresse, journal d'audit |
| **Notify** | **Canal email** — pipeline d'envoi, templates traduits, préférences, liste de suppression, branché sur les événements d'Identity |
| Verify · Billing · Storage · AI · Search · Analytics | Non démarrés |

### OAuth

Fournisseurs activés via `IDENTITY_OAUTH_PROVIDERS` (défaut `google,microsoft,github`), chacun configuré dans [`config/services.php`](config/services.php). Les identifiants proviennent de `.env` :

```bash
GOOGLE_CLIENT_ID= GOOGLE_CLIENT_SECRET=
```

`IDENTITY_OAUTH_TRUSTED_PROVIDERS` liste les fournisseurs dont l'adresse email suffit à rattacher un compte existant. En dehors de cette liste, l'utilisateur doit lier le fournisseur depuis son profil — sinon un fournisseur laxiste sur la vérification des emails permettrait une prise de contrôle de compte.

Les messages partent réellement depuis que Notify existe. En développement, le mailer est sur le driver `log` : les emails sont écrits dans `storage/logs/laravel.log`. Les jetons restent également renvoyés dans la réponse API en `local` et `testing`, par confort.

Les modules disposent de deux middlewares d'autorisation exposés par Identity :

```php
Route::middleware(['auth:api', 'organization'])          // organisation active requise
Route::middleware('scope:workspace.manage')              // permission globale requise
```

### Clés de signature

En développement, une paire RSA est générée automatiquement dans `storage/app/private/identity/`. Pour la régénérer :

```bash
php artisan identity:generate-keys
```

En production, les clés proviennent du gestionnaire de secrets via `IDENTITY_JWT_PRIVATE_KEY` et `IDENTITY_JWT_PUBLIC_KEY`, et ne sont jamais versionnées.
