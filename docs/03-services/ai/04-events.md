# Sekuu AI — Contrat d'événements

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

AI émet **trois** événements, et n'en consomme aucun.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [API](03-api.md) · [Fournisseurs](05-providers.md) · [Intégrer un produit](06-integration.md)

---

# 1. Ce qui ne transite jamais

| Interdit dans `data` | Pourquoi |
| --- | --- |
| L'entrée | C'est précisément ce que le module refuse de conserver |
| La sortie | Idem, et elle peut être plus volumineuse que l'événement |
| `input_hash` | Une empreinte permet de confirmer une entrée devinée |

Le troisième n'est pas évident. Une empreinte ne révèle rien par elle-même, mais
elle permet de **vérifier une hypothèse** : quelqu'un qui soupçonne le contenu
d'un prompt peut le hacher et comparer. Sur des entrées courtes et prévisibles —
un numéro, un nom, un montant — c'est suffisant.

L'empreinte reste en base, où elle sert l'idempotence ; elle ne circule pas.

---

# 2. Les trois événements

| Événement | Quand |
| --- | --- |
| `ai.generation.succeeded` | Une génération a produit une sortie valide |
| `ai.generation.failed` | Elle a échoué définitivement |
| `ai.spend.threshold_reached` | Une organisation franchit 80 % puis 100 % de ses crédits |

```json
{
  "type": "ai.generation.succeeded",
  "data": {
    "generation_id": "019fd4b2-…",
    "organization_id": "019fd0…",
    "task": "summarize",
    "input_tokens": 1840,
    "output_tokens": 210,
    "cost_micros": 1180,
    "latency_ms": 3120
  }
}
```

Ni le fournisseur ni le modèle n'y figurent. C'est la règle déjà posée pour les
agrégateurs de paiement : un détail d'exploitation exposé finit par être une
promesse, et l'ADR-0015 existe précisément pour qu'il reste changeable.

---

# 3. Ce qui n'est pas un événement

**La livraison du résultat à un produit externe.** Elle passe par le webhook
sortant de l'intégration, signé et réessayé — le mécanisme de Payments, décrit
dans [06-integration.md](06-integration.md).

Un événement de domaine et un webhook sortant ne s'adressent pas aux mêmes
destinataires : le premier circule dans la plateforme, le second sort vers un
tiers dont on ne contrôle rien.

---

# 4. `ai.spend.threshold_reached`

Le seul dont un humain se sert.

Émis **une fois par seuil et par période de facturation**, jamais à chaque appel
au-delà de 80 %. Sans cette borne, une organisation à 81 % produirait un message
par génération, et Notify livrerait fidèlement cette avalanche jusqu'à son
propre plafond de dépense.

C'est la même erreur que Notify a rencontrée sous une autre forme, et la même
protection.

Le seuil à 100 % est publié aussi, et il est plus utile que le refus qu'il
annonce : au moment où le client voit `AI_QUOTA_EXCEEDED`, il est déjà bloqué.

Il n'existe **aucun événement pour le plafond absolu de la plateforme**. Ce n'est
pas une information client — c'est un incident d'exploitation, qui appartient
aux journaux et à la supervision. Le publier reviendrait à dire à un client que
son service est coupé pour une raison qui ne le concerne pas.
