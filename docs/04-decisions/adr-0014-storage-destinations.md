# ADR-0014 — Le magasin est une donnée, pas une configuration

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

La première spécification de Storage supposait **un** magasin d'objets, décrit
dans `config/filesystems.php`, choisi au déploiement. C'est la forme normale
d'une application Laravel, et elle tient tant que la plateforme sert un seul
produit dans un seul pays.

Elle ne tient pas ici, pour quatre raisons qui n'ont rien à voir entre elles.

**Les coûts ne se ressemblent pas.** Une vidéo de formation servie mille fois
coûte, chez AWS, plus cher en trafic sortant qu'en stockage ; chez Cloudflare
R2, le trafic sortant est gratuit. Un PDF de facture consulté deux fois en dix
ans a le problème inverse : ce qui compte est le prix de l'octet dormant. Le bon
magasin dépend du fichier, pas de la plateforme.

**Un compte n'est pas illimité.** Une organisation qui téléverse mille heures de
cours atteindra les bornes d'un compte — quota, débit, limite de préfixe. Il
faut pouvoir en ajouter un sans redéployer.

**Un produit peut vouloir son propre magasin.** Sekuu Learn stocke chez nous ;
un client entreprise voudra ses vidéos dans son propre compartiment, sous sa
propre juridiction, avec sa propre facture cloud. C'est exactement ce que
[ADR-0010](adr-0010-external-payment-api.md) a admis pour l'encaissement : un
service externe passe par nous **ou** apporte le sien.

**Une panne de fournisseur ne doit pas être une panne de plateforme.** Un seul
magasin est un point de défaillance unique, et c'est précisément ce que
[ADR-0008](adr-0008-payment-aggregators-failover.md) a refusé côté paiement.

## Le problème que cette ADR tranche

1. Où vivent les magasins — dans un fichier de configuration, ou en base ?
2. Qui choisit celui où un fichier donné est écrit ?
3. Que veut dire, exactement, « ajouter un fournisseur sans toucher au code » ?

## Décision

### Un magasin est une ligne en base : la **destination**

`storage_destinations` porte le pilote, les paramètres, les identifiants
chiffrés, l'environnement et l'état. Plusieurs destinations peuvent partager un
pilote : quatre comptes S3 et trois comptes R2 sont sept lignes, pas sept
configurations.

`config/filesystems.php` ne décrit plus que le magasin **local**, celui des
tests.

### Le choix du magasin se résout, et il se fige

Résolution du plus précis au plus général :

1. la destination nommée par le propriétaire de l'objet dans sa politique ;
2. une règle de placement de l'organisation pour cet `owner_type` ;
3. une règle de placement de l'organisation, tous types ;
4. la destination par défaut de la plateforme.

Puis — et c'est le point qui compte — **la destination retenue est écrite sur la
ligne du fichier, et n'est plus jamais recalculée.**

Un fichier vit là où ses octets ont été posés. Changer une règle de placement
n'affecte que les fichiers à venir. Sans cette règle, rebrancher une
organisation sur un nouveau compte rendrait illisibles tous ses fichiers
antérieurs — d'un coup, sans erreur, et sans moyen de revenir en arrière puisque
la règle d'avant serait perdue.

### Une destination non éprouvée ne sert jamais

À sa création, Storage écrit un objet témoin, le relit, le supprime. Tant que
cette épreuve n'a pas réussi, la destination est `unverified` et la résolution
l'ignore.

Des identifiants faux découverts au premier téléversement d'un client sont un
incident ; découverts à l'enregistrement, ce sont deux minutes de configuration.
Le déploiement sur Render a livré cinq défauts de ce genre — du code qui marche
là où il a été écrit — et une épreuve à l'enregistrement est le remède direct.

### « Sans toucher au code » a deux moitiés, et une seule est vraie

| Ce qu'on ajoute | Ce qu'il faut |
| --- | --- |
| Un compte de plus chez un fournisseur déjà servi | **Une ligne en base** |
| Un service compatible S3 jamais utilisé — Wasabi, MinIO, Scaleway | **Une ligne en base**, avec son point d'accès |
| Une famille nouvelle — Google Drive, Dropbox, Azure Blob | **Une classe**, et une ligne de configuration |

Les deux premières lignes couvrent la quasi-totalité des cas réels : la plupart
des magasins d'objets parlent le protocole S3, et n'en diffèrent que par une URL,
une région et un style de chemin.

La troisième ne peut pas être une donnée, et il faut le dire franchement. Un
pilote doit savoir **fabriquer une autorisation d'écriture** chez son
fournisseur : c'est un protocole d'authentification, pas un paramètre. Google
Drive ouvre une session reprenable après un `POST` authentifié en OAuth ; S3
signe une URL avec une dérivation HMAC. Rendre cela configurable reviendrait à
écrire un langage de description de requêtes HTTP dans du YAML — c'est-à-dire du
code, dans un langage plus pauvre, sans types et sans tests.

Ce que la décision garantit à la place : cette classe est **petite et isolée**,
elle implémente un contrat de cinq méthodes, et rien d'autre dans la plateforme
ne change.

## Conséquences

**Nous détenons les identifiants cloud de tiers.** C'est la conséquence la plus
lourde, et elle est nouvelle : jusqu'ici la plateforme ne gardait que ses
propres secrets. Chiffrés au repos, jamais rendus par l'API — même à leur
propriétaire, qui ne reçoit qu'une empreinte — et accompagnés d'une exigence
explicite d'identifiants restreints au seul compartiment concerné.

**Le quota ne compte que nos octets.** Un produit qui apporte sa destination
paie sa facture cloud ; lui opposer notre quota n'aurait aucun sens. Storage
enregistre ses fichiers et sait les compter, mais ne refuse rien.

**Une destination ne se supprime pas tant qu'elle porte des fichiers.** Même
règle que la rétention : le refus est absolu, aucun paramètre ne passe outre.

**Un fichier ne change jamais de destination.** Déplacer suppose copier,
vérifier, repointer, effacer — avec une reprise sur panne à chaque étape. C'est
un projet, pas une option de la version 1. Écrit ici pour que l'absence soit un
choix connu.

**La panne devient partielle et lisible.** Une destination injoignable
n'empêche que ses propres fichiers, et la plateforme peut le dire précisément
plutôt que rendre une erreur générale.

## Ce qui a été écarté

**Les destinations dans un fichier de configuration.** Elles y seraient
lisibles, versionnées, et l'ajout d'un compte pour un seul client demanderait un
déploiement. Surtout, un produit externe ne peut pas déposer une pull request
pour enregistrer son compartiment.

**Une bascule automatique vers une autre destination en cas de panne**, à
l'image des agrégateurs de paiement.

Elle n'a pas le même sens : un paiement retenté ailleurs atteint le même but, un
fichier écrit ailleurs se retrouve **quelque part d'autre**, avec un autre coût
de trafic et parfois une autre juridiction. Un repli deviné par la plateforme
produirait une facture qu'aucune décision n'a prise.

Ce qui est retenu à la place : un repli **déclaré** par le module, d'un seul
rang, journalisé. `FilePolicy::allow(destination: …, fallback: …)`. Sans
`fallback`, l'échec est dur. C'est la même étroitesse que la règle de bascule
d'[ADR-0008](adr-0008-payment-aggregators-failover.md), et pour la même raison :
un repli commode finit par produire une conséquence que personne n'a choisie.

La bascule n'existe qu'au moment du choix, jamais après l'écriture.

**Une destination par fichier, choisie par le client.** Elle donnerait à
l'appelant le droit de désigner où atterrissent des octets, ce qui est une
décision d'exploitation et de coût. Le propriétaire de l'objet la prend, comme
il prend déjà celle du prix côté Payments.
