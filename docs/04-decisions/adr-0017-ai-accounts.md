# ADR-0017 — Le compte d'IA est une donnée, et un produit peut apporter le sien

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

La première spécification d'AI supposait **un** compte par fournisseur, décrit
par des variables d'environnement — `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`.

C'est la forme normale, et elle ne tient pas ici. Storage a rencontré exactement
le même mur trois semaines plus tôt, et
[ADR-0014](adr-0014-storage-destinations.md) l'a tranché : le magasin est une
donnée. Les raisons se transposent presque toutes.

**Un compte a des limites de débit.** Un fournisseur plafonne les jetons par
minute **par clé**. Un seul compte plafonne toute la plateforme, et le premier
produit qui monte en charge dégrade tous les autres.

**Les modèles ne sont pas disponibles partout.** Une clé donne accès à certains
modèles, dans certaines régions, selon le niveau du compte. Un modèle refusé sur
un compte peut être servi par un autre.

**Un produit peut vouloir sa propre clé.** Un client entreprise a déjà un
contrat Anthropic, un tarif négocié, et une exigence de résidence des données.
C'est ce que [ADR-0010](adr-0010-external-payment-api.md) a admis pour
l'encaissement et [ADR-0014](adr-0014-storage-destinations.md) pour le
stockage : un service externe passe par nous **ou** apporte le sien.

**Une clé compromise doit se remplacer sans redéployer.** Une variable
d'environnement suppose un déploiement — et Storage a démontré ce que cela coûte
sur une offre sans shell.

## Le problème que cette ADR tranche

1. Où vivent les comptes — configuration, ou base ?
2. Qui paie quand un produit apporte sa clé, et que valent alors nos chiffres ?
3. Que devient la garantie de non-entraînement de l'ADR-0016 ?

## Décision

### Un compte est une ligne : `ai_accounts`

Pilote, préréglage, paramètres, identifiants chiffrés, propriétaire,
environnement, état. Quatre comptes Anthropic et deux comptes Mistral sont six
lignes.

Les mêmes règles que les destinations de Storage, et pour les mêmes raisons —
elles ont été apprises en production, autant ne pas les réapprendre :

* **un compte non éprouvé ne sert jamais**, et l'épreuve est rejouée chaque
  nuit ;
* **l'environnement amorce, et répare ce qui n'a jamais servi** — il ne touche
  jamais à un compte qui fonctionne ;
* **on retire un compte du service en cessant d'y écrire**, pas en coupant tout ;
* **un compte qui porte des générations ne se supprime pas** ;
* les identifiants sont chiffrés, **jamais rendus** — une empreinte suffit ;
* un compte de la plateforme **n'a pas de route** : il porte nos identifiants et
  sert toutes les organisations.

### L'épreuve coûte de l'argent, et c'est la seule différence

Éprouver une destination de stockage est gratuit : on écrit un octet, on le
relit, on l'efface. Éprouver un compte d'IA suppose une **génération réelle** —
un jeton, sur le plus petit modèle du fournisseur.

C'est de l'ordre du millionième d'unité, mais ce n'est pas zéro. L'épreuve est
donc quotidienne et non horaire, et son coût est imputé à la plateforme, jamais
au propriétaire du compte.

Une épreuve qui ne consommerait rien — lister les modèles, par exemple — ne
prouverait pas ce qui compte : qu'on peut **générer**. Un compte peut lister
sans avoir de crédit.

### Sur sa propre clé, un produit n'a pas de quota

Il paie son fournisseur directement. Lui opposer nos crédits n'aurait aucun
sens, et notre plafond absolu ne protège pas son argent.

Il peut poser **son propre plafond** sur son compte. Sans lui, une boucle chez
lui reste une boucle chez lui — mais elle passe par nos serveurs, et nous
préférons qu'elle s'arrête.

### Sur sa propre clé, notre coût est une **estimation**

C'est la conséquence la moins évidente, et la plus piégeuse.

Nous calculons le coût à partir de notre table de prix publics. Un client avec
un tarif négocié, un engagement de volume ou une région différente paie autre
chose. Le nombre que nous affichons ne sera **pas** celui de sa facture.

Il est donc marqué comme estimé, partout où il apparaît. Le présenter comme un
montant ferait perdre une journée à quelqu'un qui compare deux chiffres qui
n'ont jamais eu à correspondre.

### Sur sa propre clé, la garantie de non-entraînement est la sienne

L'[ADR-0016](adr-0016-ai-spend-and-privacy.md) pose que nous n'utilisons que des
fournisseurs qui garantissent contractuellement ne pas entraîner sur les données
envoyées.

Cette garantie vient de **notre** contrat. Sur la clé d'un client, c'est le sien
qui s'applique — et nous n'en connaissons pas les termes. Un client qui branche
une clé d'offre grand public envoie ses données à l'entraînement, et rien dans
notre code ne peut le voir.

C'est dit à l'enregistrement, et redit dans la documentation d'intégration.
Nous ne pouvons pas garantir ce que nous ne contractons pas.

## Conséquences

**Nous détenons les clés d'IA de tiers**, comme nous détenons déjà leurs
identifiants cloud. Chiffrées, jamais rendues, et l'épreuve vérifie qu'elles
fonctionnent avant qu'un client ne le découvre.

**Le débit se répartit.** Plusieurs comptes du même fournisseur permettent de
basculer sur un `429` — sans jeton consommé, donc dans la règle étroite de
l'ADR-0016.

**Un compte ne change jamais après coup pour une génération donnée.** Le compte
retenu est écrit sur la ligne, comme la destination l'est sur un fichier. Une
génération dit où elle est partie, et qui l'a payée.

**Deux natures de coût cohabitent en base.** Le nôtre, exact. Le leur, estimé.
Les additionner produirait un total qui ne veut rien dire — les agrégats les
séparent.

## Ce qui a été écarté

**Les comptes en configuration.** Ils y seraient versionnés et lisibles, et
ajouter la clé d'un seul client demanderait un déploiement. Surtout, un produit
externe ne peut pas déposer une pull request pour enregistrer sa clé.

**Une bascule automatique vers le compte d'un autre client** en cas de panne du
sien. Ce serait faire payer autrui, silencieusement. Un compte de tiers tombé
échoue franchement.

**Répartir la charge entre comptes par tourniquet.** Séduisant, et prématuré :
sans trafic, on optimiserait une file qui n'existe pas. La bascule sur `429`
suffit, et elle a le mérite d'être déclenchée par un fait plutôt que par une
supposition.
