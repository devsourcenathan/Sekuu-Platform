# ADR-0004 — Access tokens JWT stateless, refresh tokens opaques

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Chaque requête vers un produit Sekuu doit être authentifiée. Les produits peuvent être écrits dans des technologies différentes (Laravel, NestJS, Supabase) et, à terme, déployés séparément d'Identity.

Deux modèles s'opposaient :

* **tokens opaques** — chaque requête entrante déclenche un appel de validation à Identity ;
* **tokens auto-portés (JWT)** — le consommateur valide seul, par signature.

Le premier fait d'Identity un point de passage obligé pour chaque requête de l'écosystème : c'est à la fois un goulot d'étranglement et un point de défaillance unique.

## Décision

Modèle hybride.

**Access token** : JWT signé en **RS256**, durée de vie **15 minutes**, vérifié localement par le consommateur via le JWKS publié par Identity. Aucun appel réseau n'est nécessaire pour valider une requête.

**Refresh token** : chaîne opaque de 256 bits, durée de vie **30 jours**, stockée hachée en SHA-256, avec rotation à chaque usage et détection de rejeu.

Le choix de **RS256 plutôt que HS256** est déterminant : les consommateurs n'ont besoin que de la clé publique. Aucun produit ne détient de secret permettant de forger un token.

## Conséquences

**Positives**

* Identity n'est pas sollicité à chaque requête ; il peut être indisponible sans bloquer la lecture dans les produits.
* Un produit extrait ou écrit dans une autre technologie valide les tokens sans dépendance forte.
* La rotation des refresh tokens détecte les vols de token.

**Négatives — et c'est le vrai coût de ce choix**

* **Un JWT ne peut pas être révoqué instantanément.** Un token émis reste cryptographiquement valide jusqu'à son expiration.
* Le contenu du token est figé à l'émission : un changement de rôle n'est pris en compte qu'au renouvellement.
* Un JWT est signé, pas chiffré : ses claims sont lisibles par quiconque le possède.

**Mitigations**

1. Durée de vie courte — 15 minutes plafonne la fenêtre d'exposition.
2. Liste de révocation (`sid` et `jti`) en Redis, consultée par les modules de la plateforme et interrogée toutes les 60 secondes par les produits externes.
3. Aucune donnée personnelle dans les claims : seulement des identifiants.
4. Révocation totale possible en retirant une clé du JWKS.

## Portée de la révocation

| Événement | Effet |
| --- | --- |
| Déconnexion | Révocation de la session courante |
| Changement ou réinitialisation de mot de passe | Révocation de toutes les sessions |
| Retrait d'un membre | Révocation des tokens portant cette organisation |
| Suspension de compte ou d'organisation | Révocation immédiate |
| Compromission de clé | Retrait du JWKS, révocation de l'écosystème |

## Alternatives écartées

* **Tokens opaques avec introspection** — révocation immédiate, mais Identity devient un point de défaillance unique appelé à chaque requête.
* **JWT longue durée (24 h)** — supprime le refresh, mais rend la révocation illusoire.
* **Sessions serveur partagées** — impose un stockage commun à tous les produits et interdit leur extraction technologique.
* **HS256** — chaque produit détiendrait le secret de signature, et pourrait donc forger des tokens.
