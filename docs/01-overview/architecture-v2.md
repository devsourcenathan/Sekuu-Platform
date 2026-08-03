# Modifications de l'architecture de Sekuu Platform

## 1. Ajout du module AI

L'intelligence artificielle est considérée comme un domaine de la plateforme au même titre que Identity ou Verify.

Le module **AI** fournit des fonctionnalités communes à tous les produits Sekuu.

Il n'est pas destiné à implémenter la logique métier d'un produit, mais à offrir des capacités génériques d'intelligence artificielle.

### Responsabilités

* Génération de texte
* Résumé de contenu
* Traduction
* Génération de quiz
* Génération de documents
* Analyse de documents
* OCR
* Recherche sémantique (RAG)
* Classification
* Modération
* Embeddings
* Génération de titres
* Génération de descriptions
* Suggestions intelligentes
* Intégration avec plusieurs fournisseurs (OpenAI, Anthropic, Google Gemini, Mistral, modèles locaux, etc.)

---

## Architecture

```text
Modules/

Identity/

Verify/

Notify/

Billing/

Storage/

Search/

Analytics/

AI/
```

Le module AI est consommé par tous les produits via une API interne.

Exemples :

* Sekuu Learn → génération de cours, quiz et corrigés.
* DealerOS → génération de descriptions produits.
* Stock → prédictions de stock et aide à la recherche.
* ClinicFlow → résumé de consultations (selon les contraintes réglementaires).
* ImmigraFlow → aide à la préparation des dossiers.

L'objectif est qu'un changement de fournisseur d'IA ne nécessite aucune modification dans les produits.

---

# 2. Versionnement des API

Toutes les API de Sekuu Platform doivent être versionnées dès la première version.

Aucune route publique ne doit être exposée sans numéro de version.

Format recommandé :

```text
https://identity.sekuu.com/api/v1/...

https://verify.sekuu.com/api/v1/...

https://notify.sekuu.com/api/v1/...

https://billing.sekuu.com/api/v1/...

https://storage.sekuu.com/api/v1/...

https://ai.sekuu.com/api/v1/...
```

---

## Pourquoi versionner dès le début ?

Le versionnement permet :

* d'introduire des changements majeurs sans casser les intégrations existantes ;
* de maintenir plusieurs versions pendant une période de transition ;
* de faciliter la migration progressive des produits.

Exemple :

```text
/api/v1/users

/api/v2/users
```

Les deux versions peuvent coexister jusqu'à la fin de la migration.

---

# 3. Politique de compatibilité

Les règles suivantes s'appliquent à toutes les API publiques :

* Les modifications incompatibles sont introduites uniquement dans une nouvelle version majeure (`v2`, `v3`, etc.).
* Les ajouts de champs ou de nouvelles fonctionnalités compatibles sont autorisés dans la version en cours.
* Chaque version bénéficie d'une période de support documentée avant sa dépréciation.
* Les réponses doivent inclure un format d'erreur standardisé pour toutes les API.

---

# 4. Structure des URLs

Chaque service expose une structure cohérente.

Identity

```text
/api/v1/auth/login

/api/v1/auth/logout

/api/v1/users

/api/v1/organizations
```

Verify

```text
/api/v1/verifications

/api/v1/providers

/api/v1/webhooks
```

Notify

```text
/api/v1/emails

/api/v1/sms

/api/v1/push
```

AI

```text
/api/v1/chat

/api/v1/completions

/api/v1/summarize

/api/v1/translate

/api/v1/embeddings

/api/v1/documents/analyze

/api/v1/ocr
```

---

# 5. Vision

Chaque domaine de Sekuu Platform doit être conçu comme un produit autonome avec une API stable et versionnée.

Cette approche garantit :

* une évolution indépendante des modules ;
* une compatibilité ascendante des intégrations ;
* une meilleure maintenabilité ;
* la possibilité d'extraire un module en service indépendant sans modifier les consommateurs de son API.
