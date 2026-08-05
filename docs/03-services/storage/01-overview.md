# Sekuu Storage — Vision & Périmètre

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Composant :** Sekuu Storage Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu Storage.

* Les tables font autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md), et le contrat OpenAPI par-dessus.
* Les événements font autorité dans [04-events.md](04-events.md).
* Pour brancher un module du monolithe : [05-integration.md](05-integration.md).
* Les magasins et leurs pilotes font autorité dans [06-destinations.md](06-destinations.md).
* Pour brancher un service externe : [07-external-api.md](07-external-api.md).
* Le téléversement direct est décidé dans [ADR-0012](../../04-decisions/adr-0012-direct-upload.md).
* Le PDF de facture est décidé dans [ADR-0013](../../04-decisions/adr-0013-invoice-pdf-frozen.md).
* Les destinations multiples sont décidées dans [ADR-0014](../../04-decisions/adr-0014-storage-destinations.md).

---

# 1. Vision

Storage garde des octets et contrôle qui peut les reprendre. **Rien d'autre.**

Il ne sait pas ce qu'il garde. Un fichier porte un `owner_type` et un `owner_id`
— `billing.invoice`, `learn.lesson`, `identity.avatar` — qu'il transporte,
indexe, et remet à un résolveur sans jamais les interpréter.

C'est la même architecture que Payments, et pour la même raison : une facture
d'abonnement et la vidéo d'une formation empruntent le même chemin, sans que
l'un sache que l'autre existe.

## 1.1 La propriété est ailleurs

Storage ne décide jamais qui a le droit de lire un fichier. Il **demande** au
module propriétaire de l'objet auquel le fichier est rattaché.

C'est l'exacte transposition de l'invariant de Payments — « seul le
propriétaire de l'objet nomme son prix » devient ici **seul le propriétaire de
l'objet dit qui peut le lire**. Storage ne connaît ni les rôles, ni les
workspaces, ni ce qu'est une facture. Trancher cette question ici obligerait à
y recopier les règles d'accès de chaque module, et ces copies divergeraient.

---

# 2. Ce que Storage ne fait pas

| Hors périmètre | Responsable |
| --- | --- |
| Décider **qui a le droit** de lire ou d'attacher un fichier | Le module propriétaire de l'objet |
| **Produire** un document — PDF de facture, export, reçu | Le module qui en connaît le contenu |
| Connaître les utilisateurs et les organisations | **Identity** |
| Publier la limite de stockage d'un plan | **Billing** |
| Transcoder une vidéo, redimensionner une image | Personne aujourd'hui — voir §8 |
| Fournir le magasin lui-même | Un fournisseur externe — voir §5 |
| Analyser un fichier pour en extraire du sens | **AI** |
| Indexer le contenu d'un document pour la recherche | **Search** |

## 2.1 Storage ne génère aucun document

La distinction porte tout le reste.

Le `503` de `GET /billing/invoices/{id}/pdf` n'est pas dû à l'absence d'un
service de stockage : il est dû à l'absence d'un **producteur de PDF**. Storage
n'a jamais eu vocation à mettre en page une facture — il ne sait pas ce qu'est
une ligne de facturation, ni quelle TVA s'applique, ni dans quelle langue écrire
« Total ».

Billing produit le PDF ; Storage le garde et le ressert. Confondre les deux
aurait fait entrer les règles fiscales camerounaises dans un module de fichiers.

---

# 3. Les octets ne traversent jamais l'API

Un client n'envoie pas son fichier à la plateforme. Il obtient une **URL de
téléversement signée**, de courte durée, et écrit directement dans le magasin
d'objets.

```
1. POST /files              → déclare : à quoi ça se rattache, quel nom, quel type
                            ← id du fichier + upload_url (expire en 15 min)
2. PUT  {upload_url}        → le client écrit les octets, sans passer par nous
3. POST /files/{id}/confirm → Storage interroge le magasin : taille, type, empreinte
                            ← le fichier devient `ready`, le propriétaire est prévenu
```

Trois raisons, dans l'ordre de gravité — le détail est dans
[ADR-0012](../../04-decisions/adr-0012-direct-upload.md).

**Une vidéo de formation ne tient pas dans un processus PHP.** Faire transiter
200 Mo par php-fpm demande de la mémoire, un `client_max_body_size`, et un délai
de requête que ni Render ni Cloudflare n'accordent. Le premier cours filmé
casserait le service.

**Un octet qu'on ne touche pas ne peut pas être mal traité.** La plateforme
n'écrit jamais de fichier sur son propre disque, donc aucune traversée de
chemin, aucun fichier temporaire oublié, aucun disque plein.

**Le disque de Render est éphémère.** Un fichier écrit dans le conteneur
disparaît au déploiement suivant, silencieusement. Une architecture qui rend
cette erreur impossible vaut mieux qu'une consigne de ne pas la commettre.

## 3.1 La déclaration ne fait jamais foi

À la confirmation, Storage **interroge le magasin** — taille réelle, type
réel, empreinte réelle — et écrase ce que le client avait annoncé.

C'est la règle déjà éprouvée sur les callbacks de paiement : *le corps ne décide
jamais de l'issue*. Un client qui déclare « image/png, 2 ko » puis téléverse un
exécutable de 80 Mo verra sa confirmation refusée, et l'objet balayé.

Sans cette vérification, le contrôle de type et le quota ne borneraient rien du
tout : ils s'appliqueraient à une déclaration, pas à un fichier.

---

# 4. Rien n'est public

Aucun objet n'est lisible sans URL signée. Aucun compartiment n'est ouvert en
lecture.

Un fichier se lit par `GET /files/{id}/url`, qui pose la question de droit au
propriétaire, puis rend une URL signée valable quelques minutes.

## 4.1 Pourquoi pas une URL permanente

Une URL permanente est un droit d'accès qu'on ne peut plus retirer. Elle survit
au départ d'un employé, à la fin d'un abonnement, à la révocation d'un partage.
Elle finit dans un courriel, un cache de moteur, une capture d'écran.

Une URL courte est un droit **daté**. Reprendre l'accès, c'est simplement ne
plus en délivrer.

## 4.2 Pourquoi l'URL ne pointe pas vers la plateforme

Elle pointe vers l'hôte du magasin d'objets, jamais vers `sekuu.com`.

C'est ce qui neutralise le vecteur principal d'un service de fichiers : un HTML
téléversé par un client, servi depuis notre domaine, s'exécuterait avec nos
cookies. Servi depuis un autre hôte, il ne peut rien atteindre.

À quoi s'ajoute une seconde borne : tout ce qui n'est pas une image ou un PDF
est servi en `Content-Disposition: attachment`. Le navigateur télécharge, il
n'interprète pas.

---

# 5. Il n'y a pas *un* magasin

Storage écrit dans des **destinations** : des lignes en base, pas une
configuration de déploiement. Quatre comptes S3 et trois comptes R2 sont sept
lignes.

Le raisonnement est dans
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md) ; l'essentiel
tient en quatre faits.

**Le bon magasin dépend du fichier.** Une vidéo lue mille fois coûte, chez AWS,
plus cher en trafic sortant qu'en stockage ; chez R2 le trafic sortant est
gratuit. Un PDF de facture consulté deux fois en dix ans a le problème inverse.

**Un produit peut apporter le sien.** Un client entreprise voudra ses vidéos
dans son compartiment, sous sa juridiction, sur sa facture — exactement ce que
[ADR-0010](../../04-decisions/adr-0010-external-payment-api.md) a admis pour
l'encaissement.

**La destination se résout une fois, puis se fige** sur la ligne du fichier. Un
fichier vit où ses octets ont été posés ; changer une règle n'affecte que les
fichiers à venir. Sans cette règle, rebrancher une organisation rendrait
illisibles tous ses fichiers antérieurs, d'un coup et sans erreur.

**Ajouter un compte, ou un service compatible S3, ne demande pas de code.**
Ajouter une *famille* nouvelle — Google Drive, Azure Blob — demande une classe
de cinq méthodes. La distinction est expliquée dans
[06-destinations.md](06-destinations.md) §1.2 : un pilote doit savoir fabriquer
une autorisation d'écriture chez son fournisseur, et c'est un protocole
d'authentification, pas un paramètre.

---

# 6. Le quota est compté ici, publié ailleurs

Billing publie la limite de stockage du plan ; Storage compte les octets de
l'organisation **sur les destinations de la plateforme** et refuse au-delà
(`STORAGE_QUOTA_EXCEEDED`, 429).

Les octets posés sur la destination d'un client ou d'un produit externe sont
comptés et rapportés, jamais opposés : il paie sa propre facture cloud.

C'est la règle déjà posée pour les sièges et les SMS : *une limite a trois états
— plafonnée, illimitée, non couverte* — et une organisation sans abonnement
n'est pas bloquée.

Le quota est vérifié **à la déclaration**, sur la taille annoncée, puis **à la
confirmation**, sur la taille réelle. La première vérification épargne un
téléversement inutile ; seule la seconde engage.

---

# 7. Rétention : certains fichiers ne se suppriment pas

Un fichier peut porter une date avant laquelle il est indestructible.

Elle est fixée par le propriétaire au moment du rattachement, jamais par le
client. Une facture émise au Cameroun doit être conservée dix ans : la route de
suppression la refuse, quel que soit l'appelant, y compris une clé d'API.

Sans cette borne, une obligation légale ne tiendrait qu'à ce qu'aucun module
n'expose de route de suppression — c'est-à-dire à rien.

---

# 8. Ce que la version 1 ne fera pas, et pourquoi

**Ni vignettes, ni transcodage.** Redimensionner demande une extension image et
du temps processeur ; transcoder demande ffmpeg et une machine dédiée. Sur
l'offre gratuite de Render, une seule vidéo occuperait le worker qui porte aussi
les envois de Notify et les webhooks de Payments.

**Ni analyse antivirale.** ClamAV veut 1 Go de mémoire pour ses signatures. Les
deux bornes du §4.2 — autre origine, téléchargement forcé — traitent le risque
réel d'ici là.

Ces trois manques sont **écrits ici plutôt qu'implémentés à moitié**. C'est
l'enseignement du canal SMS de Notify : intégralement écrit, jamais exécuté
contre une vraie passerelle, et faux sur trois points le jour du premier envoi.
Du code qu'on ne peut pas exécuter n'est pas une avance, c'est une dette qui se
croit livrée.
