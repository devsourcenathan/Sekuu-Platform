# Brancher un produit sur Sekuu AI

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

---

# 1. Depuis un module du monolithe

Aucun HTTP, aucune clé : le module appelle le cas d'usage.

```php
$generation = $ai->handle(
    task: 'summarize',
    inputs: ['input' => $lesson->transcript, 'language' => 'fr'],
    organizationId: $lesson->organization_id,
    actor: AiActor::system(),
);
```

`organizationId` n'est pas optionnel. Sans lui, la génération ne serait imputée
à personne — ni pour le quota, ni pour la facture. Une plateforme qui ne sait
pas qui a dépensé ne sait pas non plus qui facturer.

---

# 2. Depuis un service externe

Clé d'API scopée, comme Payments et Storage.

| Scope | Ce qu'il ouvre |
| --- | --- |
| `ai.run` | Demander une exécution |
| `ai.read` | Lire une issue, lire sa consommation |

La clé porte aussi une **liste blanche de tâches**. Une clé de Learn qui peut
générer des quiz n'a aucune raison de pouvoir extraire des champs d'un document.

C'est la même double borne que côté paiement : le registre dit quelles tâches
existent, la clé dit lesquelles **ce produit-là** peut demander. Une ligne
ajoutée au catalogue n'habilite personne tant qu'aucune clé ne la porte.

---

# 3. Apprendre l'issue

Trois moyens, dans cet ordre de fiabilité.

**Le sondage.** `GET /ai/tasks/{id}`, en respectant `Retry-After`. Toujours
disponible, jamais perdu.

**Le webhook sortant.** Signé, réessayé selon la cadence de Notify — 1 min,
5 min, 30 min, 2 h, 6 h. Le mécanisme est **exactement** celui de Payments, et
il est décrit là-bas.

**Rien du tout**, pour une tâche synchrone qui a répondu `200`.

## 3.1 Le webhook n'est jamais la garantie

Même règle que Payments : un produit qui ne met en place que lui aura tôt ou
tard une génération terminée et un objet qui l'ignore.

La différence avec un paiement est instructive. Un paiement perdu se rattrape
par la réconciliation, parce que l'argent existe quelque part et qu'on peut le
retrouver chez l'agrégateur. Une génération perdue, elle, **a déjà coûté** et
n'est nulle part ailleurs : si le produit ne la lit pas, il paiera pour la
relancer.

Le sondage n'est donc pas un filet de sécurité ici, c'est la voie normale.

---

# 4. Ce que le produit doit faire, et que personne ne fera pour lui

**Écrire ce qu'il reçoit.** La sortie n'est pas conservée. Un produit qui lit
une réponse et ne la range pas devra la régénérer, et la repayer.

**Poser une clé d'idempotence.** Un double-clic, un réessai de file, un
navigateur qui renvoie : sans clé, chacun est une génération de plus, facturée.

**Traiter `AI_QUOTA_EXCEEDED` autrement que `AI_SPEND_CAP_REACHED`.** Le premier
se résout en changeant de plan, le second non — c'est la plateforme qui s'est
protégée, et inviter le client à payer plus serait mensonger.

**Ne jamais envoyer plus que nécessaire.** Le coût est proportionnel à l'entrée.
Un produit qui envoie un document entier là où trois paragraphes suffisent paie
la différence à chaque appel, et l'ADR-0016 ne l'en protège pas.

---

# 5. Ajouter une tâche

Une tâche est **du code**, et son ajout suit le chemin d'une revue :

1. une entrée dans `config/ai.php` — modèle, repli, paramètres, schéma ;
2. un schéma de sortie, si la tâche promet du JSON ;
3. un test qui l'exécute contre un fournisseur factice, et un qui valide le
   schéma ;
4. une ligne au catalogue rendu par `GET /ai/tasks`.

Ce n'est pas de la lourdeur administrative. Une tâche déclare un modèle dont
quelqu'un a vérifié le prix, une température dont quelqu'un a vérifié qu'elle
convient, et une sortie dont la forme est promise à des produits qui vont s'y
fier.

## 5.1 Le piège à éviter

Une tâche **libre** existe déjà — `prompt`, `prompt-fast`, `prompt-deep` — et
elle est légitime : la plateforme y choisit toujours le modèle, borne l'entrée
et la sortie, compte le coût.

Le piège est ailleurs : **une tâche libre non bornée**. Sans `max_input_tokens`
ni `max_output_tokens`, elle devient `POST /ai/completions` déguisé — coût
imprévisible, et un produit qui envoie un livre entier facture un livre entier.

Le second piège est de s'en contenter. Un produit qui fait tout passer par
`prompt` réécrit à chaque fois ses instructions, son format attendu et son
analyseur de réponse. Le jour où le modèle change, ses instructions vieillissent
en silence — alors qu'une tâche nommée aurait absorbé le changement.

**Si un produit appelle `prompt` avec le même texte d'instructions trois fois,
c'est une tâche.** Elle mérite un nom, un schéma, et un test.
