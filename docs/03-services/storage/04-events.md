# Sekuu Storage — Contrat d'événements

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Storage émet **quatre** événements, et n'en consomme aucun.

Comme pour Payments, l'essentiel de ce que ce module a à dire ne passe pas par
un événement mais par un **appel synchrone** au propriétaire du fichier.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [API](03-api.md) · [Intégrer un module](05-integration.md)

---

# 1. Ce qui n'est pas un événement

Le rattachement d'un fichier à un objet — « le PDF de cette facture est prêt »,
« la vidéo de ce cours est en ligne » — est remis au propriétaire par
`FileOwner::attached()`, **dans la transaction de confirmation**.

La raison est celle de Payments, transposée : un fichier constaté présent et un
objet qui l'ignore encore forment une fenêtre qu'un consommateur en échec
définitif rendrait permanente. Une leçon resterait « sans support » alors que
les octets sont là, et rien ne le signalerait.

L'événement `storage.file.attached` existe malgré tout, informatif, pour
l'analyse et la supervision. Un module qui s'en servirait pour marquer son objet
se redonnerait la fenêtre que l'appel synchrone supprime.

---

# 2. Les quatre événements

| Événement | Quand |
| --- | --- |
| `storage.file.attached` | Un fichier est passé `ready` |
| `storage.file.deleted` | Un fichier a été supprimé logiquement |
| `storage.quota.threshold_reached` | Une organisation franchit 80 % puis 100 % de son quota |
| `storage.destination.unverified` | Un magasin a cessé de répondre à l'épreuve |

## 2.1 Format

Celui de la plateforme — `DomainEvent`, type en chaîne, aucune dépendance de
compilation entre modules.

```json
{
  "type": "storage.file.attached",
  "data": {
    "file_id": "019fd2a1-...",
    "organization_id": "019fd0...",
    "owner_type": "learn.lesson",
    "owner_id": "019fd1...",
    "mime_type": "video/mp4",
    "size": 184320000
  }
}
```

## 2.2 Ce qui ne transite jamais

| Interdit dans `data` | Pourquoi |
| --- | --- |
| `path` | La clé de l'objet ; l'exposer publie la structure du compartiment |
| `url` | Une URL signée dans un événement survit à son usage — journaux, files, rejeux |
| `name` | Nom donné par un client ; un nom de fichier est souvent une donnée personnelle |

Le troisième mérite l'explication. `contrat-signe-jean-mballa.pdf` porte une
identité, un fait et une relation. Un événement circule dans des files, des
journaux et, un jour, un entrepôt d'analyse — trois endroits où cette
information n'a rien à faire. Un consommateur qui a besoin du nom a le
`file_id`, et devra montrer qu'il a le droit de le lire.

---

# 3. `storage.quota.threshold_reached`

Le seul événement dont un humain se sert.

```json
{
  "type": "storage.quota.threshold_reached",
  "data": {
    "organization_id": "019fd0...",
    "threshold": 80,
    "bytes_used": 8589934592,
    "bytes_limit": 10737418240
  }
}
```

Émis **une fois par seuil et par période de facturation**, jamais à chaque
téléversement au-delà de 80 %. Sans cette borne, une organisation à 81 % de son
quota produirait un message à chaque fichier, et Notify livrerait fidèlement
cette avalanche jusqu'au plafond de dépense.

C'est l'erreur que le module d'envoi a déjà rencontrée sous une autre forme, et
la seule protection générale reste le plafond absolu de Notify — un garde-fou,
pas une politique.

Le seuil à 100 % est publié aussi, et il est plus utile que le refus lui-même :
au moment où le client voit `STORAGE_QUOTA_EXCEEDED`, il est déjà bloqué.

---

# 4. `storage.destination.unverified`

Le seul événement qui parle d'infrastructure, et le seul qu'un produit externe
doive vraiment surveiller.

```json
{
  "type": "storage.destination.unverified",
  "data": {
    "destination_id": "019fd3...",
    "slug": "acme-videos",
    "reason": "credentials_rejected",
    "since": "2026-08-05T04:00:00Z"
  }
}
```

Émis par l'épreuve quotidienne quand une destination `active` échoue : clé
révoquée chez le fournisseur, compartiment supprimé, droit d'écriture retiré,
point d'accès injoignable.

Il porte une **raison**, jamais un message brut du fournisseur. Une erreur S3
recopiée telle quelle peut contenir un identifiant de compte, un ARN, un nom de
rôle — de l'infrastructure d'un tiers, dans un événement qui traverse des files
et des journaux.

## 4.1 Pourquoi il vaut plus que le refus qu'il annonce

Sans lui, une destination cassée se découvre au téléversement suivant — c'est-à-
dire par un client, et pour un produit externe, par *son* client. L'épreuve
tourne à quatre heures du matin ; l'événement laisse une journée pour corriger
avant que quiconque s'en aperçoive.

C'est la même logique que le seuil à 80 % du quota : au moment où l'erreur est
rendue, il est déjà trop tard pour l'éviter.

`reason` prend un jeu fermé de valeurs — `credentials_rejected`,
`bucket_missing`, `write_denied`, `unreachable`, `probe_mismatch`,
`internal_error` — pour qu'un consommateur puisse réagir différemment selon le
cas. Les trois premières demandent une action humaine ; `unreachable` se résout
souvent seule.

`internal_error` désigne une faute de **notre** côté — une dépendance absente,
un pilote fautif. Elle mérite sa propre valeur parce que son absence a coûté un
déploiement : rangée dans `unreachable`, elle a envoyé le diagnostic chercher du
côté du réseau. Un magasin injoignable se corrige dans un tableau de bord ;
celui-ci se corrige dans le dépôt.
