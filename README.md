# Sekuu Platform

Socle technique commun de l'écosystème Sekuu : une plateforme de services partagés (identité, vérification, notifications, facturation, stockage, IA, recherche, analytics) sur laquelle s'appuient tous les produits SaaS Sekuu.

Développé comme un **monolithe modulaire Laravel** — une application, une base PostgreSQL, des modules aux frontières strictes, chacun exposé sur son propre sous-domaine et extractible plus tard sans changer d'URL.

📖 **La documentation est dans [`docs/`](docs/README.md)** — commencez par la [vision](docs/01-overview/vision.md) puis l'[architecture](docs/01-overview/architecture.md).

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

Vérification rapide :

```bash
curl http://localhost:8000/api/v1/health
```

## Tests

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
| **Identity** | Squelette en place — `health`, table `users` |
| Verify · Notify · Billing · Storage · AI · Search · Analytics | Non démarrés |

Prochaine étape : la tranche verticale d'authentification (`register`, `login`, `refresh`, `me`, `switch-organization`).
