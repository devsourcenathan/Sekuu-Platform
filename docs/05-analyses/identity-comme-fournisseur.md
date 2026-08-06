# Analyse — Identity comme fournisseur d'identité

> **Statut :** Analyse préalable. Aucune décision n'est prise ici ; elles le
> seront par ADR.
> **Objectif exprimé :** *« ne plus gérer les utilisateurs de mes plateformes,
> peu importe leur type ou leur finalité. »*
> **Date :** Août 2026

---

## 1. VERDICT

**Ce n'est pas d'abord un problème d'OAuth. C'est un problème de modèle.**

Le flux de délégation — redirection, code d'autorisation, PKCE, échange — est
un protocole bien balisé, implémenté par des bibliothèques maintenues. Ce n'est
pas là que ça se joue.

Ce qui se joue est ailleurs : **Identity a été construit pour une seule
population.** Un utilisateur Sekuu est aujourd'hui un professionnel qui
appartient à une organisation, laquelle souscrit à des produits. Rôles, scopes,
produits, workspaces : tout pend à l'organisation. C'est un modèle B2B, et il
est cohérent.

L'objectif énoncé en introduit une seconde : **les utilisateurs finaux des
produits de vos clients.** Un tontinier, un apprenant Learn, un patient de SOS
Clinique. Ces gens ne sont collaborateurs de rien, ne portent aucun rôle,
n'ouvrent aucun produit, et seront cent à mille fois plus nombreux.

Poser un serveur d'autorisation sur le modèle actuel sans trancher cette
question mettrait un tontinier et un administrateur de clinique dans la même
table avec la même sémantique. La première chose à céder serait le quota
`members` — une métrique commerciale qui se mettrait à compter la mauvaise
chose, **sans erreur**.

Trois faits vérifiés rendent l'entreprise raisonnable malgré tout :

* le JWKS répond déjà en public, en RS256 — **n'importe quel produit peut
  vérifier un jeton Sekuu hors ligne**, sans appeler Sekuu ;
* le quota `members` compte des **appartenances**, pas des utilisateurs : un
  compte sans appartenance ne consomme aucun siège. Le modèle accommode déjà
  deux populations, par chance plus que par intention ;
* les sessions, la rotation des jetons de rafraîchissement et la détection de
  vol par rejeu existent et sont éprouvées. Ce sont les parties d'un système
  d'identité qu'on rate le plus souvent.

**Recommandation en une phrase :** trancher le modèle d'abord, puis ajouter la
délégation en s'appuyant sur une bibliothèque du protocole — ne pas écrire le
protocole à la main, ne pas remplacer Identity par un produit du marché.

---

## 2. Ce qui existe, vérifié dans le code

### 2.1 Ce que porte un jeton

`Modules/Identity/Infrastructure/Jwt/AccessTokenIssuer.php` :

| Claim | Toujours présent | Rôle |
| --- | --- | --- |
| `iss`, `aud`, `exp`, `iat`, `jti` | oui | Vérification standard |
| `sub` | oui | L'utilisateur |
| `sid` | oui | La session — c'est par elle que passe la révocation |
| `lang` | oui | Langue de l'utilisateur |
| `org`, `roles`, `scopes`, `products` | **non** | Seulement si une organisation est active |
| `workspace` | non | Si un workspace est actif |

Le commentaire du code est explicite : *« un token sans `org` n'ouvre que les
routes de profil. »*

**C'est le fait le plus utile de cette analyse.** Un utilisateur sans
organisation existe déjà, s'authentifie déjà, et est déjà borné à presque rien.
La place d'un tontinier dans ce modèle est donc déjà creusée — il reste à dire
ce qu'elle autorise.

### 2.2 Ce que Sekuu sait faire aujourd'hui

Inscription, connexion, rotation, révocation d'une session ou de toutes, mots de
passe avec historique, vérification d'adresse, OAuth **entrant** (Google,
Microsoft, GitHub), invitations, organisations, workspaces, rôles et
permissions, journal d'audit immuable.

Dix-huit tables. Rien de tout cela n'est à jeter.

### 2.3 Ce qui n'existe pas

Aucun point d'autorisation, aucun consentement, aucune notion d'application
cliente. `oauth/{provider}/redirect` et `/callback` sont le chemin **inverse** :
Sekuu se connecte *à* un fournisseur, il n'en est pas un.

Concrètement, aujourd'hui, un produit ne peut obtenir un jeton Sekuu qu'en
transmettant le mot de passe de l'utilisateur à `/auth/login`. C'est exactement
ce que la délégation existe pour éviter.

---

## 3. Les quatre décisions à trancher

Elles sont indépendantes du protocole, et aucune n'a de réponse évidente. Ce
sont elles qui doivent faire l'objet d'une ADR.

### 3.1 Une population, ou deux ?

**Option A — une seule table, distinguée par l'appartenance.** Un utilisateur
est un utilisateur ; être *collaborateur* c'est avoir une `membership`. Un
tontinier n'en a aucune, ne consomme aucun siège, et son jeton ne porte ni
`org`, ni `roles`, ni `products`.

*Pour :* c'est déjà presque le modèle en place. Un seul compte pour une
personne, quel que soit le produit — ce qui est l'objectif énoncé. Une seule
pile d'authentification à maintenir et à durcir.

*Contre :* la table `users` change d'échelle. Elle est aujourd'hui dimensionnée
pour des collaborateurs — quelques centaines. Elle accueillerait des dizaines de
milliers d'utilisateurs finaux, avec les conséquences habituelles sur les index,
les sauvegardes et le coût d'une fuite.

**Option B — deux tables.** `users` pour les collaborateurs, une table
d'utilisateurs finaux rattachée à l'organisation du produit.

*Pour :* isolation par construction ; un utilisateur final ne peut pas
accidentellement recevoir un rôle. Les volumes ne se mélangent pas.

*Contre :* deux piles d'authentification — donc deux fois les mots de passe, la
rotation, la détection de vol, la réinitialisation. Et l'objectif est perdu :
la même personne, tontinière chez l'un et patiente chez l'autre, aurait deux
comptes.

> **Penchant.** L'option A, parce que l'objectif énoncé est un compte unique et
> que l'option B le contredit dans son principe. Mais elle exige de traiter le
> §3.2, qui est le vrai prix à payer.

### 3.2 L'unicité de l'email, et la corrélation entre produits

Aujourd'hui : `CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE
deleted_at IS NULL`, sur une colonne `citext`. **Un email, un compte, pour toute
la plateforme.**

Avec une population unique, cela veut dire qu'une personne inscrite à la tontine
puis à SOS Clinique est **le même compte**. C'est ce qu'on veut.

Mais cela veut dire aussi qu'un produit qui reçoit `sub` peut savoir que cet
identifiant existe ailleurs. Deux produits qui comparent leurs `sub` apprennent
qui leur est commun — c'est-à-dire, pour une clinique et une application
financière, une information que ni l'un ni l'autre n'a le droit de déduire.

OIDC nomme ce choix : identifiant de sujet **public** ou **par paire**. Un `sub`
par paire est dérivé du couple (utilisateur, client) : la tontine et la clinique
reçoivent deux identifiants différents pour la même personne, et ne peuvent plus
les rapprocher.

Le coût est réel : un `sub` par paire empêche aussi vos **propres** produits de
se reconnaître, et complique une future vue unifiée. Ce n'est pas un réglage,
c'est une décision de produit — et elle est **irréversible en pratique** : on ne
repasse pas de public à par paire sans casser tous les rattachements existants.

> **Penchant.** Par paire par défaut, avec la possibilité de déclarer un groupe
> de clients qui partagent le même `sub`. Vos produits maison dans le même
> groupe, les produits tiers isolés. C'est la seule forme qui laisse le choix
> ouvert plus tard.

### 3.3 Ce qu'un jeton délégué porte

Un jeton actuel porte `org`, `roles`, `scopes`, `products` — pour un
collaborateur, dans une organisation active choisie par `switch-organization`.

Pour un utilisateur final, aucun de ces claims n'a de sens. Mais le produit qui
le reçoit a besoin de savoir **au nom de quelle organisation** il opère : la
tontine sert l'éditeur X, et un utilisateur de l'éditeur Y ne doit pas y entrer.

Trois formes possibles :

1. le jeton ne porte que `sub` — le produit sait qui, et rattache lui-même ;
2. le jeton porte l'organisation **du client OAuth**, pas de l'utilisateur ;
3. le jeton porte l'organisation active de l'utilisateur, comme aujourd'hui.

La troisième est celle qui semble naturelle et qui est fausse : elle ferait
dépendre ce qu'un produit reçoit d'un état que l'utilisateur a choisi ailleurs,
dans une autre application.

> **Penchant.** La deuxième. Le client OAuth appartient à une organisation
> — celle de l'éditeur — et c'est cette appartenance qui borne. Les claims de
> collaborateur (`roles`, `products`) ne sont émis que si l'utilisateur a
> réellement une appartenance dans cette organisation.

### 3.4 La révocation

Une session Sekuu se révoque, et la détection de rejeu révoque toute la session.
C'est éprouvé.

Un jeton délégué doit-il mourir avec la session Sekuu qui l'a produit ?

**Oui, sinon la révocation ment.** Un utilisateur qui « se déconnecte partout »
et dont la tontine continue d'accepter le jeton n'a pas été déconnecté. Le claim
`sid` est déjà là, ce qui rend le lien possible.

Mais la conséquence est lourde : un produit qui ne rafraîchit pas verrait ses
utilisateurs déconnectés dès que l'un d'eux nettoie ses sessions Sekuu. Il faut
donc que la durée de vie côté produit soit courte et le rafraîchissement
obligatoire — ce qui est la bonne pratique, mais devient ici une **contrainte
d'intégration** à écrire dans le contrat.

---

## 4. Construire, ou adopter ?

La question mérite d'être posée franchement.

### 4.1 Ce qu'un produit du marché apporterait

Keycloak, Zitadel ou Logto implémentent OIDC complet, éprouvé par des années
d'attaques publiques : validation des URI de redirection, rejeu de code, PKCE,
attaques par confusion de serveur, redirection ouverte. Ce sont des pièges
connus et subtils, et les rater ne se voit pas en test.

### 4.2 Ce qu'il coûterait

Votre modèle n'est pas générique. Organisations, appartenances, rôles portés par
l'appartenance, produits activés par organisation, workspaces, opérateurs de
plateforme, quotas publiés par Billing et comptés par chaque module : c'est du
sur-mesure, et c'est précisément ce qu'un fournisseur générique modélise mal.

Adopter voudrait dire soit réimplémenter ce modèle dans ses extensions, soit
maintenir deux sources de vérité sur les utilisateurs. Et cela mettrait au rebut
une pile éprouvée — rotation, détection de vol, audit immuable, quotas — sans
rien résoudre du §3, qui reste entier dans les deux cas.

### 4.3 La voie moyenne

**Écrire l'intégration, pas le protocole.** `league/oauth2-server` est une
bibliothèque PHP mature qui porte les points d'autorisation et de jeton, les
grants et PKCE. Ce qui reste à écrire est ce qui vous est propre : le dépôt de
clients, la liaison à vos sessions, la forme des claims, la politique de `sub`.

> **Penchant.** Cette voie. Le risque de sécurité se concentre dans la partie
> déléguée à une bibliothèque, et le sur-mesure reste chez vous.

---

## 5. Le sous-ensemble minimal

Tous vos produits sont les vôtres. Cela retire beaucoup.

| À construire | À écarter, et pourquoi |
| --- | --- |
| `oauth_clients` — déclarés par un **opérateur de plateforme** | Enregistrement dynamique : personne n'en a besoin, et c'est une surface d'attaque |
| `GET /oauth/authorize` + PKCE obligatoire | Grant implicite, grant par mot de passe : dépréciés, et dangereux |
| `POST /oauth/token` — code, rafraîchissement | `client_credentials` : les clés d'API le font déjà, mieux |
| Écran de consentement, **désactivable par client de confiance** | Le supprimer : le jour où un produit tiers arrive, il redevient obligatoire, et une bascule vaut mieux qu'une réécriture |
| Révocation propagée depuis `sid` | — |
| `.well-known/openid-configuration` | — |

Le consentement mérite un mot. Le retirer entre produits maison est légitime :
demander à un utilisateur s'il autorise Sekuu à parler à Sekuu n'apprend rien à
personne. Mais **l'écrire dès le début comme un drapeau par client** coûte une
colonne, et son absence coûterait une refonte le jour où un partenaire externe
demande un accès.

---

## 6. Ce que ce chantier casse, et ce qu'il coûte

**Le quota `members` devient ambigu pour tout le monde.** Il compte des
appartenances, ce qui est correct — mais un opérateur qui lit « 12 membres »
alors que 8 000 personnes utilisent le produit du client aura besoin qu'on le lui
explique. Il faudra soit renommer, soit publier les deux nombres.

**La table `users` change d'échelle**, avec ce que cela implique sur la
sauvegarde, l'anonymisation et la portée d'une fuite. Un incident sur `users`
cesse d'exposer vos collaborateurs pour exposer les clients de vos clients.

**Le RGPD change de nature.** Aujourd'hui vous êtes sous-traitant pour les
données des produits ; sur les comptes eux-mêmes vous devenez responsable de
traitement. Effacement, portabilité, durée de conservation : ce sont des routes
à écrire, pas des principes à afficher.

**Une panne d'Identity devient une panne de tous les produits.** C'est déjà
partiellement vrai, mais l'authentification est le seul service dont
l'indisponibilité bloque *l'entrée* plutôt qu'une fonction.

**Il n'y a toujours pas de second facteur.** Prévu au modèle, non développé. Un
compte unique qui ouvre la tontine, la clinique et l'école mérite mieux qu'un
mot de passe — et l'ajouter après coup, sur une base d'utilisateurs installée,
est nettement plus difficile qu'avant.

---

## 7. Ce que je propose

L'ordre compte, et la première étape n'est pas du code.

1. **Une ADR sur le modèle d'utilisateur** — §3.1 et §3.2. C'est la décision
   irréversible ; tout le reste en découle. Elle doit dire ce qu'est un
   utilisateur sans appartenance, ce qu'il peut faire, et si `sub` est public ou
   par paire.
2. **Une ADR sur le jeton délégué** — §3.3 et §3.4. Ce qu'il porte, combien de
   temps il vit, comment il meurt.
3. **La spécification**, sur le modèle des sept documents des autres modules,
   avec son contrat OpenAPI.
4. **L'implémentation**, en s'appuyant sur `league/oauth2-server`, avec un
   premier client réel — la tontine, parce qu'elle est neuve et sans historique
   à reprendre.

**Et une chose à ne pas faire :** commencer par la tontine en modèle A —
utilisateurs gérés par l'app — en se disant qu'on migrera plus tard. Migrer des
comptes d'une application vers un fournisseur d'identité, en conservant les mots
de passe, les sessions et les rattachements, est un chantier plus lourd que
celui-ci. Si le compte unique est l'objectif, il faut le poser avant le premier
utilisateur, pas après le millième.
