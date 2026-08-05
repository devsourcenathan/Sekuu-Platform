# ADR-0016 — Ce que l'IA coûte, et ce qu'elle emporte

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Deux risques accompagnent tout appel à un modèle, et ils n'ont rien en commun
sinon d'être invisibles jusqu'à ce qu'ils coûtent cher.

**L'argent part sans qu'on le voie.** Un envoi de SMS coûte un montant connu à
l'unité ; une génération coûte ce que le modèle décide de produire. Une boucle,
une entrée anormalement longue ou une clé fuitée brûlent en une nuit ce qu'un
plan rapporte en un an.

**La donnée part chez un tiers.** Un prompt porte ce que le produit lui a
donné : un dossier médical chez SOS Clinique, un contrat, une pièce d'identité.
Elle traverse un réseau, atterrit chez un fournisseur étranger, et — selon le
contrat — sert éventuellement à entraîner un modèle.

## Le problème que cette ADR tranche

1. Qui borne la dépense, et à quel endroit ?
2. Que conserve-t-on d'une génération ?
3. Quels fournisseurs sont admissibles ?

## Décision

### Deux bornes de dépense, et il en faut deux

| | Quota de plan | Plafond absolu |
| --- | --- | --- |
| Source | Billing, `ai_credits_monthly` | Configuration de la plateforme |
| Rôle | Limite **commerciale** | Garde-fou contre l'emballement |
| Porte sur | Une organisation | La plateforme entière |

C'est exactement la coexistence déjà en place dans Notify entre `ChannelQuota`
et `SpendGuard`, et pour la même raison : **supprimer le second laisserait une
organisation au plan illimité sans aucune borne.** Une clé fuitée sur un plan
« illimité » est précisément le scénario où l'on perd de l'argent.

Le quota est vérifié **avant** l'appel, sur une estimation, puis le coût réel est
constaté après. Une estimation ne peut pas être exacte — le nombre de jetons
produits n'est connu qu'après — donc un dépassement de quelques pourcents est
possible et assumé. L'alternative serait de refuser tout appel dont le coût
maximal théorique franchit la limite, ce qui bloquerait bien avant qu'elle ne
soit atteinte.

### Le registre des générations est append-only

Chaque appel écrit une ligne : tâche, fournisseur, modèle, jetons entrants et
sortants, coût, latence, issue. Scellée au niveau du modèle, comme les registres
de Payments.

Sans elle, une facture de fournisseur en fin de mois est un nombre que personne
ne peut expliquer, ni imputer à une organisation.

### Ce qu'on ne conserve pas : le contenu

**Par défaut, ni le prompt ni la réponse ne sont stockés.** Une empreinte du
prompt suffit à l'idempotence ; les métriques suffisent à la facturation.

Le contenu n'est conservé que si la tâche le déclare explicitement, avec une
durée de rétention, et jamais au-delà. Trois raisons, dans l'ordre de gravité :

Le prompt d'un produit de santé porte des données de santé. Les stocker en fait
notre responsabilité, dans une base qui n'a pas été conçue pour cela.

Un registre de prompts est la cible la plus intéressante de la plateforme : il
concentre, en clair, ce que tous les produits ont de plus sensible.

Et il grossit sans limite. Une base qui double tous les mois finit par coûter
plus cher que les générations qu'elle décrit.

### Aucun fournisseur qui entraîne sur nos entrées

Condition d'admission, non négociable : le fournisseur doit garantir
contractuellement qu'il n'entraîne pas sur les données envoyées par l'API. C'est
le cas des offres professionnelles d'Anthropic, d'OpenAI, de Google et de
Mistral — mais pas de leurs offres grand public, ni de tous les intermédiaires.

Une clé personnelle glissée dans la configuration parce qu'elle « marche pareil »
est le chemin par lequel cette garantie se perd, et rien dans le code ne peut le
détecter. C'est écrit ici pour que le choix soit conscient.

### La bascule entre fournisseurs est étroite

On ne réessaie chez un autre fournisseur que si la requête **n'a jamais atteint
le modèle** — erreur de transport, refus avant traitement, délai d'attente à la
connexion.

Passé ce point, les jetons consommés sont facturés, qu'on obtienne une réponse
ou non. Réessayer ailleurs paie deux fois, et rend une réponse différente de
celle qui était peut-être en train d'arriver.

C'est la règle de bascule de l'[ADR-0008](adr-0008-payment-aggregators-failover.md),
transposée mot pour mot : *l'incertitude compte comme un appel abouti.*

## Conséquences

**On ne peut pas rejouer une génération à l'identique.** Sans le prompt, aucun
débogage a posteriori. C'est le prix de ne pas constituer ce registre, et il se
paie au moment le plus désagréable — quand un client conteste une réponse. La
conservation par tâche existe pour ces cas-là, activée sciemment.

**Le coût d'une organisation est connu à la ligne près**, imputable, et
exportable. C'est ce qui rendra une facturation à l'usage possible le jour où
elle sera décidée.

**Une estimation trop basse laisse passer un dépassement.** Borné par le plafond
absolu, qui lui ne dépend d'aucune estimation.

**Un fournisseur écarté pour sa politique de données peut être moins cher.**
C'est un arbitrage assumé, et il ne se rediscute pas au cas par cas.

## Ce qui a été écarté

**Facturer à l'appel plutôt qu'au jeton.** Simple pour le client, mais un appel
qui résume trois lignes et un appel qui traite trente pages ne coûtent pas la
même chose. On facturerait au pire cas, donc trop cher pour presque tout le
monde.

**Chiffrer les prompts plutôt que de ne pas les garder.** Le chiffrement protège
d'une fuite de base, pas de l'accumulation ni de notre propre responsabilité.
Ne pas détenir reste la seule garantie qui ne dépende d'aucune clé.

**Un plafond par utilisateur.** Le bon grain serait l'organisation, qui est
l'unité de facturation. Un plafond par utilisateur inviterait à contourner en
créant des comptes.
