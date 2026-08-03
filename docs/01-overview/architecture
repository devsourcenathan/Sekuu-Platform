# Architecture de déploiement de Sekuu Platform

> **Version :** 1.0
> **Statut :** Architecture de référence

---

# 1. Objectif

L'objectif de cette architecture est de permettre à Sekuu Platform d'évoluer progressivement :

* sans créer une infrastructure complexe dès le départ ;
* tout en gardant la possibilité d'extraire certains services dans le futur sans modifier les produits consommateurs.

Cette approche suit le principe du **Modular Monolith First**.

---

# 2. Principe

Au démarrage, **Sekuu Platform est une seule application Laravel**.

Cette application est découpée en modules indépendants.

Chaque module possède :

* ses routes ;
* ses contrôleurs ;
* ses services ;
* ses modèles ;
* ses migrations ;
* ses tests.

Tous les modules partagent :

* la même application Laravel ;
* la même base PostgreSQL.

---

# 3. Architecture

```text
                     SEKUU PLATFORM

               Laravel Modular Monolith

 ┌──────────────────────────────────────────────────────┐
 │                                                      │
 │  Identity                                             │
 │  Verify                                               │
 │  Notify                                               │
 │  Billing                                              │
 │  Storage                                              │
 │  Search                                               │
 │  Analytics                                            │
 │                                                      │
 └──────────────────────────────────────────────────────┘
                     │
                     │
             PostgreSQL (Unique)
```

Chaque domaine est indépendant dans le code mais partage l'infrastructure.

---

# 4. Pourquoi un monolithe modulaire ?

Les microservices apportent beaucoup d'avantages mais également une forte complexité :

* plusieurs bases de données ;
* plusieurs déploiements ;
* plusieurs pipelines CI/CD ;
* plusieurs sauvegardes ;
* plusieurs systèmes de monitoring ;
* plusieurs systèmes de logs.

Pour une petite équipe ou un développeur seul, cette complexité est rarement rentable.

Le monolithe modulaire permet de bénéficier :

* d'un développement plus rapide ;
* d'une maintenance simplifiée ;
* d'une séparation claire des responsabilités ;
* d'une migration progressive vers des services indépendants.

---

# 5. Organisation des modules

```text
Modules/

Identity/

Verify/

Notify/

Billing/

Storage/

Search/

Analytics/
```

Chaque module est totalement autonome.

Exemple :

```text
Modules/

Identity/

├── Application/

├── Domain/

├── Infrastructure/

├── Presentation/

├── Routes/

├── Database/

└── Tests/
```

---

# 6. Gestion des sous-domaines

Même si une seule application Laravel est déployée, chaque module est exposé via un sous-domaine dédié.

Exemple :

```text
identity.sekuu.com

verify.sekuu.com

notify.sekuu.com

billing.sekuu.com

storage.sekuu.com
```

Tous ces sous-domaines pointent vers la même application Laravel.

Le routage interne dirige ensuite la requête vers le bon module.

---

# 7. Routage

Chaque module enregistre ses propres routes.

Exemple :

Identity

```text
identity.sekuu.com

/api/auth/login

/api/auth/logout

/api/users

/api/organizations
```

Verify

```text
verify.sekuu.com

/api/verifications

/api/providers

/api/webhooks
```

Notify

```text
notify.sekuu.com

/api/emails

/api/sms

/api/push
```

Le client pense communiquer avec plusieurs services alors que l'infrastructure n'en exécute qu'un seul.

---

# 8. Architecture réseau

```text
                   Internet
                       │
             ┌─────────┴─────────┐
             │ Reverse Proxy/CDN │
             └─────────┬─────────┘
                       │
      ┌────────────────┼─────────────────┐
      │                │                 │
identity.sekuu.com verify.sekuu.com notify.sekuu.com
      │                │                 │
      └────────────────┴─────────────────┘
                       │
               Sekuu Platform
              (Application Laravel)
                       │
                PostgreSQL Unique
```

Le reverse proxy (Nginx, Caddy, Traefik ou Cloudflare) redirige les sous-domaines vers la même application.

---

# 9. Base de données

Une seule base est utilisée au démarrage.

Exemple :

```text
sekuu_platform
```

Les tables sont regroupées par domaine fonctionnel.

Identity

```text
users

organizations

memberships

global_roles

global_permissions

sessions

oauth_accounts
```

Verify

```text
verification_requests

verification_sessions

verification_documents

verification_results
```

Notify

```text
notifications

notification_templates

notification_logs
```

Billing

```text
plans

subscriptions

invoices

transactions
```

Chaque module est propriétaire de ses tables.

Un module ne doit jamais modifier directement les tables d'un autre module.

Les échanges passent par les services du domaine concerné.

---

# 10. Évolution vers des services indépendants

L'un des principaux objectifs est de permettre une extraction progressive.

Aujourd'hui :

```text
Identity
Verify
Notify

↓

Une seule application
```

Demain :

```text
Identity

↓

Toujours dans Sekuu Platform

----------------------------

Verify

↓

Application indépendante

----------------------------

Notify

↓

Toujours dans Sekuu Platform
```

L'URL ne change jamais.

Exemple :

Avant :

```text
verify.sekuu.com
```

Après extraction :

```text
verify.sekuu.com
```

Le consommateur ne voit aucune différence.

---

# 11. Pourquoi conserver les sous-domaines dès le début ?

Même lorsqu'un module est interne, il est exposé via son propre domaine.

Cela présente plusieurs avantages :

* contrat d'API stable ;
* indépendance des consommateurs ;
* migration transparente ;
* meilleure séparation logique.

---

# 12. Déploiement

Version initiale

```text
1 dépôt Git

1 application Laravel

1 pipeline CI/CD

1 serveur

1 base PostgreSQL
```

Version intermédiaire

```text
1 dépôt Git

1 application Laravel

2 serveurs

1 base PostgreSQL
```

Version avancée

```text
Plusieurs applications

Plusieurs pipelines

Plusieurs bases

Services totalement indépendants
```

---

# 13. Philosophie

Sekuu Platform ne doit pas être pensé comme une collection de microservices dès sa création.

Il doit être conçu comme un **monolithe modulaire**, où les frontières entre les domaines sont clairement définies.

Chaque domaine est développé comme s'il était déjà un service indépendant.

Lorsqu'un domaine devient suffisamment volumineux, il peut être extrait sans impacter les produits consommateurs.

Cette approche permet d'obtenir le meilleur compromis entre simplicité, évolutivité et coût de maintenance.

---

# 14. Vision à long terme

À terme, Sekuu Platform deviendra une véritable plateforme de services partagés.

Chaque service pourra évoluer indépendamment, être déployé séparément et disposer de sa propre infrastructure.

Cependant, cette évolution ne sera réalisée que lorsqu'un besoin technique ou fonctionnel le justifiera.

Le principe directeur est le suivant :

> **Commencer simple, concevoir pour évoluer et extraire uniquement lorsque cela apporte une réelle valeur.**
