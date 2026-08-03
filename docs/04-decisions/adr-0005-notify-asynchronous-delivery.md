# ADR-0005 — Notify : envoi asynchrone, contenu figé à l'acceptation

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Identity doit envoyer huit types de messages. La solution la plus simple serait d'appeler un fournisseur SMTP directement depuis le contrôleur d'inscription.

Cette simplicité a un coût immédiat : le temps de réponse de `POST /auth/register` deviendrait celui du fournisseur SMTP, et une panne de ce dernier ferait échouer des inscriptions parfaitement valides. Un service tiers deviendrait ainsi une dépendance du chemin critique d'authentification.

Se posait aussi une question moins visible : à quel moment le contenu d'un message est-il déterminé ?

## Décision

**Tout envoi est asynchrone.** L'API répond `202 Accepted` et met le message en file. Aucun appelant n'attend jamais un fournisseur externe.

**Le contenu est rendu et figé au moment de l'acceptation**, pas au moment de l'envoi. `notifications.rendered_subject` et `notifications.rendered_body` conservent le message exact.

**La livraison est « au moins une fois ».** Les consommateurs sont idempotents, et l'identifiant de l'événement sert de clé d'idempotence.

**Un rejet métier n'est jamais réessayé.** Numéro invalide, destinataire supprimé, variable manquante : le réessai ne réussira pas et chaque tentative coûte.

## Conséquences

**Positives**

* Une panne de fournisseur ne fait plus échouer d'inscription, ni aucune opération métier.
* Le journal reflète exactement ce que le destinataire a reçu, même si le template a changé depuis.
* Un message peut être rejoué à l'identique après un incident.
* La montée en charge se fait en ajoutant des workers, sans toucher aux émetteurs.

**Négatives**

* **L'appelant ne sait pas si le message est parti.** `202` signifie « accepté », rien de plus.
* Un message peut échouer silencieusement — il faut donc surveiller les échecs, et publier `notify.message.failed`.
* Les workers deviennent une pièce d'infrastructure à superviser : une file bloquée est invisible depuis l'API.
* Le contenu figé occupe de l'espace ; c'est la raison de la rétention à 12 mois.

**Mitigations**

* `GET /notifications/{id}` expose le statut réel et les tentatives.
* Les échecs définitifs publient un événement, exploitable par Analytics et par les alertes.
* Le canal `in_app` ne dépend d'aucun fournisseur : il reste disponible quand tout le reste échoue.

## Le cas qui a tranché

Une réinitialisation de mot de passe demandée à 14 h 00, mise en file, et un template corrigé à 14 h 01.

Sans contenu figé, le message part à 14 h 02 avec le nouveau template. Si la correction a modifié la formulation du lien, ou pire introduit une erreur, l'utilisateur reçoit autre chose que ce que le système croit avoir envoyé — et le support n'a aucun moyen de reconstituer ce qui s'est passé.

Pour un message transactionnel, cette incertitude est inacceptable. C'est ce cas, et non la performance, qui justifie de stocker le rendu.

## Alternatives écartées

* **Envoi synchrone** — met un fournisseur tiers sur le chemin critique de l'authentification.
* **Rendu au moment de l'envoi** — économise du stockage, rend le journal non fiable.
* **Livraison « au plus une fois »** — évite les doublons mais perd des messages en cas d'incident. Perdre un lien de réinitialisation est pire qu'en envoyer deux.
* **Réessai systématique, y compris sur rejet métier** — coûte de l'argent sur le canal SMS sans aucune chance de succès.
