# Sekuu AI — Appeler depuis un service externe

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Un service hors du monolithe — Sekuu Learn, un produit tiers — exécute des
tâches par cette API, **avec nos comptes d'IA ou avec les siens**.

Le mécanisme est celui de
[Payments](../payments/07-external-api.md) et de
[Storage](../storage/07-external-api.md) : clé d'API scopée, liste blanche,
webhook sortant signé. Ce document ne redit que ce qui diffère.

---

# 1. Deux bornes, et il en faut deux

* **la clé est scopée** — `ai.run`, `ai.read`, `ai.accounts`, trois droits
  distincts. Une clé qui exécute des tâches n'enregistre pas de compte : ce sont
  deux dangers différents, et un seul droit pour les deux serait le plus large ;
* **la clé porte une liste blanche de tâches** — une clé de Learn qui génère des
  quiz n'a aucune raison d'extraire des champs d'un document.

Le catalogue dit quelles tâches **existent**, la clé dit lesquelles **ce
produit-là** peut demander. Une tâche ajoutée n'habilite personne tant qu'aucune
clé ne la porte.

---

# 2. Deux façons d'appeler

| | **Nos comptes** | **Le sien** |
| --- | --- | --- |
| Qui paie le fournisseur | Sekuu | Le produit |
| Quota `ai_credits_monthly` | Compté et opposable | **Aucun** |
| Plafond absolu de la plateforme | S'applique | Ne s'applique pas — voir §2.2 |
| Coût affiché | **Exact** | **Estimé** — voir §2.3 |
| Non-entraînement | Notre contrat le garantit | **Le sien** — voir §2.4 |
| Tarif | Public | Le sien, éventuellement négocié |
| En cas de rupture | Rien à récupérer | Il garde sa clé |

## 2.1 Enregistrer son compte

```http
POST /api/v1/ai/accounts
Authorization: Bearer <clé d'API>

{
  "slug": "acme-anthropic",
  "preset": "anthropic",
  "credentials": { "api_key": "…" },
  "spend_cap_micros": 50000000,
  "environment": "production"
}
```

L'épreuve est immédiate : **une génération d'un jeton**, sur le plus petit
modèle du fournisseur. Elle coûte une fraction de centime, et elle est à notre
charge.

Un échec rend `AI_ACCOUNT_UNVERIFIED` avec la raison exacte, et le compte n'est
utilisable par personne tant qu'il n'a pas réussi. Des identifiants faux
découverts ici coûtent deux minutes ; découverts au premier appel d'un client,
un incident.

La réponse ne rend jamais les identifiants — une empreinte, et l'état.

## 2.2 Sur son compte, aucun quota — mais un plafond conseillé

Le produit paie son fournisseur ; nos crédits ne le concernent pas, et notre
plafond absolu protège notre argent, pas le sien.

`spend_cap_micros` lui permet de poser le sien. Sans lui, une boucle chez lui
reste une boucle chez lui — mais elle passe par nos serveurs, et nous préférons
qu'elle s'arrête.

Au-delà : `AI_ACCOUNT_CAP_REACHED`, `429`.

## 2.3 Sur son compte, notre coût est une **estimation**

C'est le point le plus piégeux de cette intégration.

Nous calculons à partir des **prix publics** du fournisseur. Un tarif négocié,
un engagement de volume ou une région différente donnent un autre montant. Le
nombre que nous affichons ne sera pas celui de la facture.

Il est donc marqué `"cost_estimated": true` partout où il apparaît. Le présenter
comme un montant ferait perdre une journée à quelqu'un qui compare deux chiffres
qui n'ont jamais eu à correspondre.

`GET /ai/usage` sépare les deux natures de coût plutôt que de les additionner :
un total mêlant un montant exact et une estimation ne veut rien dire.

## 2.4 Sur son compte, la garantie de non-entraînement est la sienne

L'[ADR-0016](../../04-decisions/adr-0016-ai-spend-and-privacy.md) pose que nous
n'utilisons que des fournisseurs garantissant contractuellement qu'ils
n'entraînent pas sur les données envoyées par l'API.

**Cette garantie vient de notre contrat.** Sur la clé d'un client, c'est le sien
qui s'applique, et nous n'en connaissons pas les termes. Une clé d'offre grand
public envoie ses données à l'entraînement, et rien dans notre code ne peut le
détecter.

L'enregistrement le dit, la réponse le rappelle dans un champ `warnings`, et ce
document le redit ici. Nous ne pouvons pas garantir ce que nous ne contractons
pas.

## 2.5 Ce que nous n'envoyons jamais chez un tiers

Quel que soit le compte : le contenu envoyé au modèle est **celui du produit**,
et rien d'autre. Aucune donnée d'une autre organisation, aucun identifiant
interne, aucune métadonnée de la plateforme n'est jointe au prompt.

Cela paraît évident, et ne l'est pas : un module qui enrichirait
automatiquement un prompt — « voici le contexte de l'organisation » — enverrait
chez un fournisseur des données que personne n'a décidé d'y envoyer.

---

# 3. Exécuter une tâche

```http
POST /api/v1/ai/tasks
Authorization: Bearer <clé d'API>
Idempotency-Key: 019fd4a1-…

{
  "task": "quiz",
  "input": "…",
  "language": "fr",
  "account": "acme-anthropic"
}
```

`account` est facultatif ; absent, la résolution s'applique
([05-providers.md](05-providers.md) §3). Nommer un compte qui n'est pas le sien
rend `AI_ACCOUNT_FORBIDDEN`, `403`.

Il n'existe **aucun champ `model`**, pour un produit externe comme pour un
module interne — [ADR-0015](../../04-decisions/adr-0015-ai-task-not-model.md).

## 3.1 Un compte de tiers ne se rabat jamais sur les nôtres

Si le compte du produit est tombé, l'appel échoue. Basculer sur un compte de la
plateforme reviendrait à payer à sa place, sans que personne l'ait décidé — et
la surprise arriverait à la fin du mois.

---

# 4. Apprendre l'issue

Trois moyens, dans cet ordre de fiabilité : le **sondage**
(`GET /ai/tasks/{id}`), le **webhook sortant** signé, et rien du tout pour une
tâche synchrone.

Quatre événements sont livrés :

| Événement | Quand |
| --- | --- |
| `ai.generation.succeeded` | Une sortie valide a été produite |
| `ai.generation.failed` | Échec définitif |
| `ai.spend.threshold_reached` | 80 % puis 100 % des crédits, sur nos comptes |
| `ai.account.unverified` | Un compte du produit a cessé de répondre |

Le dernier est le plus utile, et il n'a pas d'équivalent côté paiement. Une clé
révoquée chez le fournisseur bascule le compte en `unverified` à l'épreuve
quotidienne : le produit l'apprend le jour même, plutôt qu'au prochain appel
d'un de ses clients.

## 4.1 Le sondage n'est pas un filet, c'est la voie normale

La différence avec un paiement est instructive.

Un paiement perdu se rattrape par la réconciliation : l'argent existe quelque
part, et on peut le retrouver chez l'agrégateur. Une génération perdue, elle,
**a déjà coûté** et n'est nulle part ailleurs. Si le produit ne la lit pas, il
paiera pour la relancer.

---

# 5. Ce que le produit ne doit jamais faire

**Supposer le synchrone.** Une tâche déclarée courte peut s'allonger, et le
changement ne sera pas annoncé.

**Oublier la clé d'idempotence.** Un double-clic, un réessai de file, un
navigateur qui renvoie : sans clé, chacun est une génération de plus, facturée.

**Jeter la sortie.** Elle n'est pas conservée. La relire coûtera une seconde
génération.

**Confondre `AI_QUOTA_EXCEEDED` et `AI_SPEND_CAP_REACHED`.** Le premier se
résout en changeant de plan, le second non : c'est une protection, et inviter le
client à payer plus serait mensonger.

**Déposer une clé d'IA sans lire le §2.4.**

---

# 6. Ce qui n'existe pas

**La suppression d'un compte qui porte des générations.** Le registre dit qui a
payé quoi ; la ligne disparue, il ne le dirait plus. On met en pause.

**Le partage d'un compte entre deux produits.** Un compte appartient à une
organisation ou à une clé, jamais aux deux, et jamais à plusieurs. La
mutualisation d'un tarif négocié est un sujet commercial, pas technique.

**Le choix du modèle**, ni par la clé, ni par le compte, ni par une option.
C'est l'invariant du module, et l'admettre pour un produit externe le
supprimerait pour tout le monde.
