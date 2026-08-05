# ADR-0012 — Les octets ne traversent jamais la plateforme

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Storage doit accepter des fichiers : PDF de facture produits par le serveur,
pièces jointes envoyées par un client, et — c'est le cas dimensionnant — les
vidéos de cours de Sekuu Learn.

La voie évidente est un `POST /files` multipart : le client envoie le fichier à
l'API, l'API le pose dans le magasin. C'est ce que fait la majorité des
applications Laravel, et Laravel le rend facile.

## Le problème

Facile ne veut pas dire tenable, et trois faits le disent.

**Une vidéo de cours pèse deux cents mégaoctets.** Les faire transiter par
php-fpm demande d'accorder `upload_max_filesize`, `post_max_size`,
`client_max_body_size` côté nginx, et un délai de requête assez long pour une
connexion camerounaise. Sur l'offre gratuite de Render — 512 Mo de mémoire pour
un conteneur qui porte déjà nginx, php-fpm, le worker et l'ordonnanceur — deux
téléversements simultanés suffisent à tuer le service. Et le proxy de Render,
comme celui de Cloudflare, coupe à 100 Mo : la limite ne serait même pas la
nôtre.

**Le disque du conteneur est éphémère.** Un fichier écrit dans
`storage/app` disparaît au déploiement suivant. Sans erreur, sans journal, sans
que quiconque le remarque avant qu'un client réclame sa facture. Le déploiement
sur Render nous a déjà appris cinq défauts de ce genre — du code qui marche là
où il a été écrit — et celui-ci a la particularité de détruire des données.

**Un octet qu'on manipule est un octet qu'on peut mal manipuler.** Nom de
fichier contrôlé par le client, chemin construit par concaténation, fichier
temporaire jamais nettoyé : la liste des failles classiques d'un service
d'upload suppose toutes que le serveur touche le fichier.

## Décision

**Le client écrit directement dans le magasin d'objets, par une URL signée de
courte durée. L'API ne voit jamais les octets.**

En trois temps : déclarer, écrire, confirmer.

La déclaration pose les bornes — qui, quoi, quelle taille — et rend une URL
signée valable quinze minutes, restreinte à une clé d'objet, à la méthode `PUT`
et au type annoncé. La confirmation **interroge le magasin** et écrase ce que le
client avait déclaré.

Cette dernière phrase est la décision dans la décision. Une confirmation qui
croirait le client sur parole rendrait le contrôle de type et le quota purement
décoratifs : ils s'appliqueraient à une déclaration, pas à un fichier. C'est la
règle que les callbacks de paiement ont déjà imposée sous une autre forme — *le
corps ne décide jamais de l'issue*.

## Conséquences

**Un client fait trois appels au lieu d'un.** C'est le coût réel, et il tombe
sur l'intégrateur. Il est atténué pour les modules du monolithe, qui écrivent en
un geste quand ils produisent les octets eux-mêmes.

**Il existe un état où le fichier est déclaré et absent.** `pending` ne signifie
pas « en cours » : Storage ne sait rien de ce que le client fait avec l'URL. Il
signifie **on ne sait pas** — le même aveu qu'une intention de paiement expirée,
avec les mêmes conséquences : un balayage devient obligatoire, pas optionnel.

**Le magasin devient une dépendance de disponibilité.** S'il est injoignable,
aucun téléversement n'aboutit. C'était déjà vrai dans l'autre architecture, avec
en plus notre propre mémoire comme point de rupture.

**Les tests ne dépendent d'aucun compte externe.** Le pilote `local` de Laravel
émet aussi des URL temporaires : la chaîne complète — déclarer, écrire,
confirmer, lire — s'éprouve sans réseau. C'est ce qui manquait cruellement au
canal SMS de Notify, entièrement écrit sans jamais avoir été exécuté.

## Ce qui a été écarté

**Le téléversement multipart classique.** Voir ci-dessus.

**Le téléversement en morceaux, géré par nous.** Découper, ranger, recoller,
reprendre — c'est un protocole à écrire et à éprouver. S3 le fait déjà, et son
téléversement en plusieurs parties s'ajoutera si une vidéo dépasse la limite
d'un seul `PUT`. Le rebâtir aujourd'hui serait de la dette qui se croit livrée.

**La notification d'écriture du magasin, à la place de la confirmation.** Elle
existe côté S3, et R2 la propose. Mais elle arrive par un canal que nous n'avons
pas éprouvé, absent des offres gratuites, et Payments a documenté ce qu'il en
coûte de dépendre d'un webhook — trois livraisons dans un ordre variable, un
corps qui ment sur le statut. Une confirmation demandée par le client est
synchrone, attribuable, testable. Elle pourra devenir un accélérateur plus tard,
jamais la garantie.
