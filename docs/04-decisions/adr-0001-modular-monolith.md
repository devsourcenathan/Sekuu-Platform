# ADR-0001 — Monolithe modulaire plutôt que microservices

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Sekuu Platform doit héberger huit domaines (Identity, Verify, Notify, Billing, Storage, AI, Search, Analytics) consommés par plusieurs produits SaaS indépendants.

L'équipe est très réduite — un développeur au démarrage.

Une architecture microservices dès le premier jour imposerait : plusieurs bases de données, plusieurs déploiements, plusieurs pipelines CI/CD, plusieurs systèmes de monitoring, de logs et de sauvegarde, ainsi que la gestion de la cohérence distribuée.

## Décision

Sekuu Platform est développé comme un **monolithe modulaire Laravel** : une application, une base PostgreSQL, des modules aux frontières strictes.

Chaque module possède ses routes, ses services, ses modèles, ses migrations et ses tests. Chaque module est propriétaire exclusif de ses tables et n'accède jamais à celles d'un autre module.

Chaque domaine est exposé dès le départ via son propre sous-domaine (`identity.sekuu.com`, `verify.sekuu.com`…), tous pointant vers la même application.

## Conséquences

**Positives**

* Coût d'infrastructure et de maintenance minimal au démarrage.
* Développement rapide, transactions locales, débogage simple.
* Les contrats d'API publics sont stables dès le jour 1 : l'extraction ultérieure d'un module ne change aucune URL et n'impacte aucun consommateur.

**Négatives**

* Les frontières entre modules ne sont pas garanties par l'infrastructure : elles reposent sur la discipline et doivent être vérifiées par des tests d'architecture automatisés.
* Un déploiement affecte tous les modules.
* Une montée en charge se fait globalement, pas par domaine.

**Mitigations**

* Test d'architecture en CI interdisant toute dépendance croisée entre modules hors des interfaces de contrat.
* Communication inter-modules limitée à deux mécanismes : contrat de service synchrone et événements de domaine.

## Critère d'extraction

Un module est extrait en service indépendant lorsqu'au moins l'une de ces conditions est vérifiée :

* son profil de charge diverge fortement du reste de la plateforme (typiquement AI ou Storage) ;
* il exige un cycle de déploiement propre ;
* il doit être opéré dans une autre juridiction ou sous une autre contrainte réglementaire ;
* une équipe dédiée le prend en charge.

Aucun module n'est extrait sans qu'un de ces critères soit atteint.

## Alternatives écartées

* **Microservices dès le départ** — complexité opérationnelle sans commune mesure avec la taille de l'équipe.
* **Monolithe non modulaire** — moins coûteux à court terme, mais rend toute extraction future pratiquement impossible.
* **Serverless par fonction** — démultiplie les déploiements et les cold starts, sans bénéfice à ce stade.
