# ADR-0018 — Administrer la plateforme

> **Statut :** Acceptée — remplace une première version plus étroite
> **Date :** Août 2026

---

## Contexte

Tous les rôles de la plateforme sont portés par une **organisation** : `owner`,
`admin`, `billing_manager`. Un utilisateur agit toujours au nom d'un client.

Personne n'agit au nom de **Sekuu**.

Tant que la seule configuration de plateforme était technique — identifiants
d'agrégateurs, magasins, files — cela ne s'est pas vu : ces objets se posent en
ligne de commande, par quelqu'un qui a accès au serveur.

Mais exploiter une plateforme suppose de voir les organisations, de consulter
une facture qu'un client conteste, de corriger un quota, de constater qu'un
magasin est tombé. Rien de tout cela n'a de porte aujourd'hui — et sur l'offre
gratuite de Render, il n'y a pas même de shell.

## Le problème

Trois exigences qui ne tiennent pas ensemble avec ce qui existe :

1. administrer la plateforme depuis une interface, sans déploiement ;
2. sans qu'un client puisse s'accorder ce qu'il veut ;
3. sans que « administrer » devienne « tout voir, tout faire, sans trace ».

La troisième est la difficile. Un drapeau booléen « super-administrateur » règle
les deux premières et crée un compte dont la compromission donne accès à
l'ensemble des données de tous les clients.

## Décision

### Un opérateur est marqué hors de l'application

Une table `platform_operators` — un utilisateur, un jeu de permissions, une date
d'octroi, qui l'a octroyée. Peuplée par `identity:operator` ou directement en
base. **Jamais par une route, jamais par une invitation, jamais par un rôle
d'organisation.**

Il n'existe aucun chemin applicatif pour s'octroyer ce marquage ni pour
l'octroyer à autrui. C'est ce qui empêche un `owner` de se promouvoir par
`roles.assign`.

### Des permissions granulaires, pas un drapeau

| Permission | Ce qu'elle ouvre |
| --- | --- |
| `platform.plans` | Lire et modifier le catalogue et ses limites |
| `platform.organizations` | Lister les organisations, leur état, leur usage |
| `platform.billing` | Consulter abonnements et factures d'un client |
| `platform.infrastructure` | Magasins, comptes d'IA, agrégateurs — **état seulement** |
| `platform.audit` | Lire le journal d'audit de la plateforme |
| `platform.operators` | Octroyer des permissions — **jamais par l'API** |

La dernière ligne est délibérément inerte : elle existe pour être refusée. Une
permission qui distribue des permissions transformerait le premier compte
compromis en un nombre illimité de comptes compromis.

Un drapeau unique serait plus simple, et c'est exactement le problème : le jour
où l'on veut donner à quelqu'un le droit de corriger un quota, on lui donne
aussi l'accès aux factures de tous les clients.

### Le préfixe est visible

`/api/v1/platform/…`, refusé à quiconque n'est pas opérateur.

Il rend visible, dans une table de routage et dans les journaux, ce qui relève
de l'exploitation — plutôt que de le dissimuler parmi les routes clientes
derrière une condition qu'on oublie de relire.

### **Les lectures sont journalisées, pas seulement les écritures**

C'est la contrepartie qui rend le reste acceptable.

Un opérateur qui consulte la facture d'un client accède à une donnée qui ne lui
appartient pas. Si cet accès ne laisse pas de trace, la seule garantie offerte
au client est notre parole.

Chaque appel sous `/platform/` écrit au journal d'audit : qui, quelle
organisation, quelle ressource, quand. Les écritures ajoutent l'avant et
l'après.

C'est plus verbeux qu'un journal d'écritures, et c'est le prix d'un accès aux
données d'autrui.

### Ce qui reste hors de portée, même pour un opérateur

| Refusé | Pourquoi |
| --- | --- |
| Le **contenu** d'un fichier | Storage ne le sert que sur autorisation du propriétaire de l'objet ; un opérateur n'en est pas un |
| Le **contenu** d'un prompt ou d'une réponse | Il n'est pas stocké — ADR-0016. Un opérateur ne peut pas lire ce qui n'existe pas |
| Le corps d'une notification envoyée | Même raison : ce sont les mots d'un client à son client |
| Se connecter en tant qu'un utilisateur | L'usurpation est un autre sujet, avec ses propres gardes |
| Modifier un abonnement en cours | Décision commerciale : elle passe par les routes normales, avec le consentement du client |
| Poser une clé ou un magasin | Cela porte des **secrets** — ligne de commande, comme avant |

Les trois premières lignes sont la frontière qui compte : **un opérateur voit
des métadonnées et des montants, jamais le contenu que les clients nous
confient.** Il peut constater qu'un fichier de 4 Mo existe ; il ne peut pas
l'ouvrir.

La dernière trace l'autre frontière : **un nombre passe par l'API, un secret n'y
passe jamais.**

## Conséquences

**Une nouvelle surface d'attaque, et elle est sérieuse.** Un compte opérateur
compromis expose la tarification, la liste des clients et leurs montants. D'où
le marquage hors application, les permissions séparées, les lectures
journalisées, et une liste de routes qui doit rester courte.

**Le second facteur devient nécessaire pour ces comptes.** Il n'existe pas
encore. Tant qu'il n'existe pas, un mot de passe sépare un attaquant du
catalogue et de la liste des clients. C'est écrit ici pour que le manque soit
connu, daté, et corrigé avant le premier client qui compte.

**Un opérateur ne doit pas être un compte quotidien.** Le même identifiant qui
lit ses courriels et administre la plateforme réunit les deux risques. Tant
qu'il n'y a qu'une personne, c'est théorique ; ça cesse de l'être en
embauchant.

**La porte est ouverte pour un back-office**, et tout ce qu'il fera devra être
ajouté ici, route par route, permission par permission. C'est volontairement
laborieux.

## Ce qui a été écarté

**Un rôle global `platform_admin`.** Attribuable par `roles.assign`, donc par un
`owner` à lui-même. La faille serait immédiate.

**Une organisation « Sekuu » traitée à part.** Le privilège tiendrait à un
identifiant, et une erreur de configuration le transférerait ailleurs sans
bruit.

**Un drapeau booléen unique.** Voir plus haut : il lie le droit de corriger un
quota à celui de lire toutes les factures.

**L'accès au contenu, « pour le support ».** C'est la demande qui reviendra, et
la réponse est non. Un client qui a besoin qu'on regarde son document peut nous
en envoyer une copie ; une plateforme qui peut ouvrir n'importe quel fichier est
une plateforme dont la promesse de confidentialité ne vaut que par sa
discipline.

**Des variables d'environnement pour les limites.** Elles exigeraient un
déploiement à chaque changement de tarif — ce que cette ADR refuse. Elles
restent la voie des secrets.
