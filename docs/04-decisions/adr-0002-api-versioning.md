# ADR-0002 — Versionnement des API dans l'URL, dès la v1

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Les API de Sekuu Platform sont consommées par des produits hétérogènes (Laravel, NestJS, Next.js, React Native) qui n'évoluent pas au même rythme, ainsi que par des clients mobiles déployés chez les utilisateurs et qu'on ne peut pas forcer à se mettre à jour.

Une API non versionnée rend toute évolution incompatible impossible sans casser des intégrations en production.

Une première rédaction de la documentation exposait des routes sans version (`/api/auth/login`), ce qui contredisait les guidelines.

## Décision

Toutes les routes publiques sont versionnées **dans l'URL**, dès la première version :

```text
https://identity.sekuu.com/api/v1/auth/login
```

* Aucune route publique n'est exposée sans numéro de version.
* La version ne transite **jamais** par un header ni par un paramètre de requête.
* Seule la version majeure apparaît (`v1`, `v2`) — pas de `v1.2`.
* Les évolutions compatibles (ajout de champ, de paramètre optionnel, de code d'erreur) restent dans la version courante.
* Les évolutions incompatibles (suppression ou renommage de champ, changement de type ou de sémantique, nouvelle contrainte obligatoire) exigent une version majeure.
* Deux versions majeures peuvent coexister le temps de la migration.

## Conséquences

**Positives**

* Un produit peut rester en `v1` pendant qu'un autre migre en `v2`.
* Le versionnement est visible dans les logs, le cache et le routage.
* Le contrat est lisible sans inspecter les headers.

**Négatives**

* Maintenir plusieurs versions coûte du temps et de la couverture de tests.
* Le préfixe alourdit légèrement les URL.

## Politique de dépréciation

1. Annonce publique et documentation de la version dépréciée.
2. Header `Deprecation` et `Sunset` sur les réponses de la version concernée.
3. **12 mois** minimum de support après l'annonce.
4. Extinction à la date annoncée.

Aucune rupture de compatibilité ne peut être introduite dans une version majeure déjà publiée.

## Alternatives écartées

* **Version dans un header** (`Accept: application/vnd.sekuu.v1+json`) — plus élégant en théorie, mais invisible dans les logs, difficile à tester au navigateur, et source d'erreurs de cache.
* **Pas de versionnement** — impose une compatibilité ascendante éternelle, irréaliste.
* **Versionnement par ressource** — versions désynchronisées, complexité de suivi ingérable.
