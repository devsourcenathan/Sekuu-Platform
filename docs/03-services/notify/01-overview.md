# Sekuu Notify — Vision & Périmètre

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Projet :** Sekuu Ecosystem
> **Composant :** Sekuu Notify Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu Notify.

* Le modèle de données fait autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md).
* Les événements consommés sont listés dans [04-events.md](04-events.md).

---

# 1. Contexte

Identity produit déjà huit occasions d'envoyer un message — bienvenue, vérification d'adresse, mot de passe oublié, invitation, alerte de connexion — et n'en envoie aucun. Les jetons correspondants sont aujourd'hui renvoyés dans la réponse API en environnement local, faute de destinataire.

Notify existe pour combler ce vide, et pour éviter que chaque module et chaque produit ne rebâtisse sa propre couche d'envoi.

Sans service dédié, on obtiendrait rapidement : huit configurations SMTP, autant de comptes SMS, des templates dupliqués, aucune vision consolidée de ce qui a été envoyé, et surtout aucune gestion des rebonds — le moyen le plus sûr de détruire la réputation d'expédition d'un domaine.

---

# 2. Vision

Notify est le **seul point de sortie** des messages de l'écosystème Sekuu.

Un module ou un produit ne connaît ni fournisseur, ni template, ni canal. Il déclare une intention :

```text
« Envoyer le message `invitation.sent` à cette adresse, avec ces variables. »
```

Notify décide du reste : quel canal, quel template, quelle langue, quel fournisseur, quand réessayer, et s'il faut renoncer.

---

# 3. Objectifs

## 3.1 Objectifs fonctionnels

Notify doit gérer :

* Les canaux : email, SMS, WhatsApp, push, notifications internes.
* Les templates, versionnés et traduits.
* Les préférences de réception des utilisateurs.
* La liste de suppression (rebonds durs, plaintes, désabonnements).
* Le suivi de livraison, incluant les retours des fournisseurs.
* Les files d'attente et la politique de réessai.
* Plusieurs fournisseurs par canal, avec bascule.

## 3.2 Objectifs techniques

* **Asynchrone par défaut** : un appelant n'attend jamais l'envoi effectif — voir [ADR-0005](../../04-decisions/adr-0005-notify-asynchronous-delivery.md).
* **Idempotent** : le même événement livré deux fois ne produit pas deux messages.
* **Traçable** : chaque message est rattachable à la requête qui l'a déclenché, via le `request_id`.
* **Neutre vis-à-vis des fournisseurs** : changer de fournisseur SMS ne modifie aucun produit.

## 3.3 Ce que Notify ne fait pas

| Hors périmètre | Responsable |
| --- | --- |
| Décider **si** un message doit partir | Le module ou le produit émetteur |
| Connaître les utilisateurs et les organisations | **Identity** |
| Facturer les envois | **Billing** |
| Stocker les pièces jointes | **Storage** |
| Rédiger le contenu métier | Le produit, via les variables du template |

Notify ne prend **aucune** décision métier. Il ne sait pas ce qu'est une invitation ; il sait rendre un template nommé `invitation.sent` et le remettre à un destinataire.

---

# 4. Architecture

```text
   Identity   Billing   Verify   ClinicFlow   DealerOS
       │         │        │          │           │
       └─────────┴────┬───┴──────────┴───────────┘
                      │
              événements de domaine
                      │
                ┌─────▼─────┐
                │   Notify  │
                └─────┬─────┘
                      │
      ┌───────────────┼───────────────┬──────────────┐
      │               │               │              │
    Email            SMS          WhatsApp         Push
   (SMTP,         (opérateurs    (Business      (FCM, APNs)
    SES…)          locaux…)        API)
```

Les produits externes appellent l'API HTTP ; les modules de la plateforme publient des événements. Les deux chemins aboutissent au même pipeline.

---

# 5. Le pipeline d'envoi

C'est la partie qui doit être comprise avant le modèle de données : chaque étape existe pour une raison précise, et sauter l'une d'elles produit un défaut connu.

```text
 1. Réception          intention d'envoi (API ou événement)
 2. Déduplication      même clé d'idempotence déjà vue ? → on s'arrête
 3. Résolution         template + langue + canal
 4. Rendu              variables appliquées, contenu figé
 5. Filtrage           préférences, liste de suppression
 6. Mise en file       le message devient une tâche
 7. Livraison          fournisseur, avec réessais
 8. Réconciliation     retours du fournisseur (rebond, plainte)
```

| Étape | Ce qu'elle évite |
| --- | --- |
| Déduplication | Le doublon lors d'un rejeu d'événement — inévitable en livraison « au moins une fois » |
| Rendu **avant** mise en file | Un template modifié entre-temps changerait le contenu d'un message déjà accepté |
| Filtrage | Écrire à quelqu'un qui s'est désabonné, ou à une adresse qui rebondit |
| Réconciliation | Croire qu'un message accepté par le fournisseur a été reçu |

Le point 4 mérite d'être souligné : **le contenu est figé au moment de l'acceptation**, pas au moment de l'envoi. Sans cela, un message en file d'attente pendant une heure pourrait partir avec un template corrigé entre-temps, et le journal ne refléterait plus ce qui a réellement été envoyé.

---

# 6. Canaux

| Canal | Usage | Contrainte principale |
| --- | --- | --- |
| **Email** | Par défaut pour tout message durable | Réputation d'expédition : les rebonds doivent supprimer le destinataire |
| **SMS** | Codes, alertes urgentes | Coût à l'unité, 160 caractères, pas de mise en forme |
| **WhatsApp** | Canal privilégié sur les marchés d'Afrique centrale | Templates pré-approuvés par Meta, fenêtre de 24 h pour les messages libres |
| **Push** | Applications mobiles | Nécessite un jeton d'appareil valide |
| **Interne** | Notifications dans le produit | Aucune dépendance externe, toujours disponible |

Le choix du canal appartient au **template**, pas à l'appelant : c'est ce qui permet de basculer un message de l'email vers WhatsApp sans toucher au code du produit qui le déclenche.

---

# 7. Catégories de messages

La distinction la plus lourde de conséquences du service.

| Catégorie | Exemples | Désabonnement |
| --- | --- | --- |
| **Transactionnel** | Réinitialisation de mot de passe, vérification d'adresse, alerte de sécurité, facture | **Impossible** |
| **Opérationnel** | Invitation, rapport hebdomadaire, rappel de rendez-vous | Possible, par catégorie |
| **Marketing** | Nouveautés, offres | Possible, désactivé par défaut |

Un message transactionnel ne peut pas être bloqué par une préférence : couper le lien de réinitialisation de mot de passe reviendrait à enfermer l'utilisateur dehors. Il reste en revanche soumis à la liste de suppression — une adresse qui rebondit durablement n'est plus une adresse.

Détails et justification dans [ADR-0006](../../04-decisions/adr-0006-transactional-vs-marketing.md).

---

# 8. Fournisseurs

Chaque canal accepte plusieurs fournisseurs, ordonnés par priorité.

```text
email     →  postmark  →  ses          (bascule si le premier échoue)
sms       →  operateur-local  →  twilio
whatsapp  →  meta-cloud-api
push      →  fcm
```

Trois règles :

* Un fournisseur est une **implémentation d'interface**, jamais un appel direct depuis le domaine.
* La bascule ne s'applique qu'aux erreurs **infrastructurelles** (timeout, 5xx). Un numéro invalide ne devient pas valide chez un autre opérateur.
* Les identifiants proviennent du gestionnaire de secrets, jamais du dépôt.

Le contexte des marchés visés impose de traiter les opérateurs locaux comme des fournisseurs de premier rang, et non comme un cas particulier : sur ces marchés, un SMS acheminé localement est moins cher et mieux délivré qu'un SMS international.

---

# 9. Ce qui est mesuré

Un service d'envoi sans mesure est un service qu'on croit fonctionnel.

* Taux d'acceptation par fournisseur.
* Taux de rebond dur et de plainte, par domaine expéditeur.
* Délai entre l'acceptation et la livraison.
* Volume par organisation, par canal, par catégorie.
* Messages échoués définitivement, avec leur cause.

Ces mesures alimentent Analytics ; Notify se contente de produire les événements correspondants.

---

# 10. Résumé

Notify fournit :

✓ Un point de sortie unique pour tous les messages
✓ Des templates versionnés, traduits, multi-canaux
✓ Des préférences par utilisateur et par catégorie
✓ Une liste de suppression qui protège la réputation d'expédition
✓ Un suivi de livraison de bout en bout
✓ Plusieurs fournisseurs par canal, interchangeables

Mais il ne décide jamais :

✗ Si un message doit partir
✗ Ce que le message signifie pour le métier
✗ Qui sont les utilisateurs et les organisations
