# ADR-0006 — Catégories de messages et liste de suppression

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Deux mécanismes empêchent un message de partir, et on les confond souvent :

* la **préférence** — le destinataire ne veut pas de ce type de message ;
* la **suppression** — la destination ne fonctionne plus, ou son propriétaire nous a signalés comme indésirables.

Les traiter de la même façon produit deux défauts symétriques. Si tout est désactivable, un utilisateur peut couper son propre lien de réinitialisation de mot de passe et se retrouver enfermé dehors. Si rien ne l'est, on continue d'écrire à des adresses qui rebondissent — et la réputation du domaine expéditeur s'effondre, emportant avec elle l'acheminement des messages légitimes vers **tous** les autres destinataires.

## Décision

### Trois catégories

| Catégorie | Exemples | Désabonnement |
| --- | --- | --- |
| `transactional` | Réinitialisation, vérification d'adresse, alerte de sécurité, facture | **Impossible** |
| `operational` | Invitation, rapport, rappel | Possible |
| `marketing` | Nouveautés, offres | Possible, **désactivé par défaut** |

La catégorie appartient au **template**, pas à l'appelant : un émetteur ne peut pas requalifier un message marketing en transactionnel pour contourner une préférence.

Tenter de désactiver une catégorie transactionnelle renvoie `422` / `TRANSACTIONAL_CANNOT_BE_DISABLED`. L'API refuse explicitement plutôt que d'accepter sans appliquer.

### La suppression prime sur tout

Une destination inscrite sur la liste de suppression ne reçoit **rien**, y compris les messages transactionnels.

| Motif | Durée | Origine |
| --- | --- | --- |
| `hard_bounce` | Permanente | Webhook fournisseur |
| `complaint` | Permanente | Signalement « spam » |
| `unsubscribe` | Permanente | Lien de désabonnement |
| `invalid` | Permanente | Syntaxe invalide |
| `manual` | Datée | Intervention humaine |

Un rebond **temporaire** ne supprime pas : il déclenche un réessai.

### Le désabonnement est public

`POST /preferences/unsubscribe/{token}` n'exige aucune authentification. Le jeton est opaque, et ne donne accès à rien d'autre qu'à cette action.

## Conséquences

**Positives**

* Un utilisateur ne peut pas se couper de sa propre récupération de compte.
* La réputation d'expédition est protégée par construction, sans discipline humaine.
* Le marketing désactivé par défaut évite d'avoir à réparer plus tard un consentement mal acquis.
* Un désabonnement en un clic réduit mécaniquement les signalements « spam », plus coûteux qu'un désabonnement.

**Négatives**

* **Un utilisateur peut devenir totalement injoignable** si sa seule adresse est supprimée — y compris pour réinitialiser son mot de passe.
* Une suppression permanente sur un faux positif de fournisseur bloque un destinataire valide.
* Trois catégories, c'est un arbitrage : certains messages sont à la frontière. Un rappel de rendez-vous médical est-il opérationnel ou transactionnel ?

**Mitigations**

* `notify.recipient.suppressed` est publié : Identity peut marquer le compte comme injoignable et exiger un autre moyen de vérification.
* La réhabilitation existe (`DELETE /suppressions/{id}`), sous scope dédié et journalisée.
* Les cas frontières sont tranchés à la création du template, une fois, et documentés — pas laissés à l'appréciation de chaque appelant.

## Ce qui a tranché

L'asymétrie des dégâts.

Une préférence trop permissive coûte un utilisateur mécontent. Une adresse en rebond dur qu'on continue de solliciter coûte la délivrabilité de **tout le domaine** — et ce dommage frappe des destinataires qui n'ont rien demandé, y compris sur des messages transactionnels d'autres organisations.

C'est pourquoi la suppression prime sur la catégorie, y compris transactionnelle, alors que l'intuition suggérerait l'inverse.

## Alternatives écartées

* **Tout désactivable** — un utilisateur peut se verrouiller hors de son compte.
* **Rien de désactivable** — non conforme, et générateur de signalements « spam ».
* **Catégorie choisie par l'appelant** — contournable, donc sans valeur.
* **Suppression appliquée aux seuls messages non transactionnels** — ignore que le dommage de réputation est global au domaine, pas local au message.
