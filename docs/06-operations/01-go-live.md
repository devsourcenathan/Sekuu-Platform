# Mise en service

> **Statut :** Procédure de référence
> **Dernière mise à jour :** Août 2026

Ce document répond à une seule question : **que faut-il pour qu'un premier franc
soit encaissé pour de vrai ?**

Il ne décrit pas ce que la plateforme sait faire — c'est le rôle des
spécifications. Il décrit ce qui manque autour du code.

---

# 1. La règle qui prime sur tout le reste

> **L'environnement et les identifiants doivent être d'accord.**

`CredentialGuard` le vérifie **à la résolution des agrégateurs**, dans les deux
sens, et il n'existe aucune option pour l'ignorer.

C'est le dernier instant où aucun téléphone n'a encore sonné, et le premier où un
paiement devient possible. Le placer au démarrage de l'application rendait
`artisan` inutilisable — y compris les commandes qui servent à corriger la
configuration fautive.

**Corollaire pratique :** `GET /payments/health` est la vérification d'avant-vol,
puisqu'elle résout ce registre. Une configuration incohérente s'y voit
immédiatement.

| `APP_ENV` | Notch Pay | Tranzak |
| --- | --- | --- |
| autre que `production` | clé préfixée `test_` | `sandbox.dsapi.tranzak.me` |
| `production` | clé réelle | `dsapi.tranzak.me` |

## 1.1 Pourquoi ce contrôle existe

**Tranzak refuse une clé qui ne correspond pas à l'hôte** — vérifié en
conditions réelles : des identifiants de production reçoivent
`Authentication Error` sur `sandbox.dsapi.tranzak.me`. Une URL oubliée est donc
bruyante, et `payments:verify` la révèle avant tout déploiement.

**Notch Pay n'offre pas cette protection : il ne distingue pas ses
environnements par l'URL.**
`api.notchpay.co` sert le test et la production ; seul le **préfixe de la clé**
décide. Une clé de production collée dans un `.env` de développement fait sonner
le téléphone d'une vraie personne, et la débite. Aucun garde-fou d'hôte
n'intervient.

Le sens inverse se voit encore moins : une clé de test **en production** fait
« aboutir » les paiements sans qu'aucun argent ne bouge. Le client reçoit sa
confirmation, le service s'ouvre, et la plateforme n'a rien encaissé. Personne
ne le signale — jusqu'au rapprochement bancaire.

Ce dépôt a déjà envoyé une centaine de vrais emails le jour où une clé a été
renseignée. La protection reposait alors sur le fait qu'aucune clé n'était
configurée, ce qui n'est pas une protection.

## 1.2 Il n'y a pas d'échappatoire

Volontairement. Une variable pour désactiver le contrôle finit toujours par être
activée « juste pour essayer », et c'est exactement l'essai qui débite
quelqu'un.

Si le garde-fou vous bloque, la réponse est de corriger `APP_ENV` ou les
identifiants — jamais de contourner.

---

# 2. Ce qui doit tourner

Trois processus. Aucun n'est optionnel, et ce sont les trois qui manquaient.

## 2.1 Le serveur

Rien de particulier : PHP 8.3, PostgreSQL 18.

## 2.2 Le worker

```bash
sudo cp deploy/sekuu-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start sekuu-worker:*
```

Il porte les envois de Notify **et les webhooks sortants de Payments**.

Sans lui, un produit externe n'apprend jamais ses encaissements autrement qu'en
sondant. C'est le point où l'API externe a changé la nature de cette dépendance :
avant, un worker arrêté retardait des emails ; maintenant, il laisse un client
payé sans service.

**Un déploiement doit appeler `php artisan queue:restart`.** Sans cela, une
tâche enfilée avec l'ancien code peut s'exécuter avec le nouveau.

## 2.3 L'ordonnanceur

```bash
sudo cp deploy/crontab /etc/cron.d/sekuu
```

Une seule ligne : Laravel décide lui-même quelle tâche est due.
`php artisan schedule:list` montre les trois.

Sans elle, **aucun callback perdu n'est rattrapé** — la pire défaillance que ce
module existe pour empêcher — et aucun rappel d'échéance ne part.

## 2.4 Redis

`compose.yaml` le fournit en développement. En production, la file **doit** être
Redis : `database` fonctionne, au prix d'une latence et d'une charge en écriture
qui ne tiennent pas sous volume.

`appendonly yes` : une file perdue au redémarrage, ce sont des livraisons
d'encaissement qui ne repartiront jamais.

---

# 3. Chez les agrégateurs

## 3.1 Les URL de callback

À enregistrer dans les deux tableaux de bord :

```text
https://payments.sekuu.com/api/v1/payments/webhooks/notchpay
https://payments.sekuu.com/api/v1/payments/webhooks/tranzak
```

L'ancienne adresse `/api/v1/billing/webhooks/{provider}` reste servie : des
transactions déjà initialisées la portent **figée dans leur payload**. À
supprimer une fois qu'aucune n'est en cours.

**Un tableau de bord n'accepte souvent qu'une URL.** `NOTCHPAY_CALLBACK_URL`
permet de la passer par paiement quand un seul compte marchand sert plusieurs
environnements.

## 3.2 Les secrets de callback

`TRANZAK_AUTH_KEY` et `NOTCHPAY_WEBHOOK_HASH`. **Sans eux, aucun callback n'est
accepté** — une variable oubliée ne doit pas devenir une porte ouverte sur les
paiements.

Ce n'est pas bloquant pour encaisser : la réconciliation rattrape. C'est
bloquant pour le faire **vite**.

## 3.3 Vérifier les identifiants, sans dépenser un franc

```bash
php artisan payments:verify
```

Appels en **lecture seule** chez les deux agrégateurs : un jeton chez Tranzak,
une lecture de liste chez Notch Pay. La commande dit lesquels acceptent la clé,
et **quel environnement elle ouvre**.

C'est la seule commande autorisée à toucher des identifiants de production hors
production, et son exemption est structurelle : elle ne résout pas les
agrégateurs, donc il n'existe aucun chemin vers `charge()`. Elle ne *peut pas*
débiter quelqu'un.

Elle ne prouve **pas** que les callbacks arriveront — voir § 3.4.

## 3.4 Vérifier le service

```bash
curl https://payments.sekuu.com/api/v1/payments/health
```

`can_collect: true` et les deux agrégateurs dans `providers`. Un agrégateur sans
identifiants n'est jamais essayé, et le dit ici plutôt que d'échouer au premier
paiement.

---

# 4. Les secrets

Aujourd'hui, un `.env` en clair sur le serveur. Trois mesures gratuites couvrent
l'essentiel :

**Chiffrer le fichier de production.**

```bash
php artisan env:encrypt --env=production
```

Le `.env.production.encrypted` peut être versionné ; le serveur le déchiffre au
déploiement avec `LARAVEL_ENV_ENCRYPTION_KEY`. Le gain est de passer de dix
secrets à protéger à **un seul**.

Sa limite, qu'il faut connaître : l'application écrit quand même un `.env` en
clair sur le serveur. Cela protège le dépôt, les sauvegardes et le transport —
pas la machine.

**`chmod 600` sur le `.env`**, et jamais dans une sauvegarde automatique.

**Des clés distinctes** entre développement et production. C'est le point qui
compte le plus, et celui que `CredentialGuard` rend obligatoire.

Un gestionnaire dédié — Infisical, gratuit en auto-hébergé — devient utile le
jour où quelqu'un d'autre a besoin d'un accès, ou le jour où il y a plusieurs
serveurs. Pas avant : ce serait de l'infrastructure à maintenir avant d'avoir un
client.

---

# 5. La liste

| | Fait ? |
| --- | --- |
| `APP_ENV=production` et identifiants réels — les deux | |
| Comptes marchands Notch Pay et Tranzak | |
| URL de callback enregistrées dans les deux tableaux de bord | |
| `TRANZAK_AUTH_KEY` et `NOTCHPAY_WEBHOOK_HASH` renseignés | |
| `payments:verify` — les deux agrégateurs en **production**, identifiants acceptés | |
| Redis, et `QUEUE_CONNECTION=redis` | |
| Worker Supervisor démarré | |
| Crontab installée, `schedule:list` non vide | |
| `payments/health` répond `can_collect: true` | |
| Domaine expéditeur vérifié chez Resend (DKIM, DMARC) | |
| `.env` chiffré ou en gestionnaire, `chmod 600` | |
| CI verte sur `main` | |
| Un endpoint de livraison par produit externe (`payments:endpoint`) | |

---

# 6. Peut-on valider la production depuis un poste local ?

La question se pose forcément avant un premier déploiement. Elle recouvre deux
choses qu'il faut séparer.

**Les identifiants sont-ils valides ?** Oui, et sans risque : `payments:verify`
le dit, en lecture seule, quel que soit `APP_ENV`.

**Les callbacks de production arrivent-ils ?** Non, et il ne faut pas essayer.
Cela supposerait d'enregistrer une URL de tunnel dans le tableau de bord de
**production**. Un tunnel change d'adresse à chaque redémarrage ; l'oubli de
remettre l'URL réelle enverrait les callbacks de vrais clients dans le vide —
des gens débités sans service, et personne pour le signaler.

## 6.1 Et `APP_ENV=production` en local ?

`CredentialGuard` l'accepterait. Trois effets n'ont pourtant rien à voir avec le
paiement, et sont durables :

* les clés d'API émises deviennent `sk_live_` ;
* les erreurs cessent d'être détaillées, ce qui rend le diagnostic plus difficile
  au moment précis où l'on teste ;
* **l'argent réel se retrouve dans une base de développement.** Le paiement
  existe chez l'agrégateur et nulle part en production : le rapprochement
  montrera un encaissement que la plateforme ignore.

Le troisième est le vrai problème, et il ne se corrige pas après coup.

## 6.2 Le compromis, si vous y tenez

Un vrai paiement peut être lancé depuis un poste local **sans toucher aux
tableaux de bord** : le callback n'arrivera pas, et `payments:reconcile` le
constatera par sondage. C'est précisément ce pour quoi le sondage n'est pas
optionnel.

Cela valide la chaîne d'encaissement réelle. Cela ne valide toujours pas les
callbacks, et cela laisse un encaissement réel dans une base jetable.

---

# 7. Le premier paiement réel

**Faites-le vous-même, avec votre propre numéro, sur un petit montant.**

C'est la seule vérification qui prouve que la chaîne entière fonctionne :
l'invite part, le callback arrive, le registre s'écrit, le produit est prévenu.
Aucun bac à sable ne le prouve — les deux agrégateurs ont déjà démenti deux
hypothèses chacun lors de leur intégration.

Puis vérifiez les trois faits, dans cet ordre :

1. `payment_transactions` porte **deux** lignes : le `charge` brut et le `fee`.
2. Le produit propriétaire a bien été réglé.
3. `payment_deliveries` est `delivered`, si un produit externe est branché.

---

# 8. Ce qui reste en dette

**Storage.** `GET /invoices/{id}/download` renvoie `503`. Une facture non
téléchargeable est un problème légal, pas un confort.

**Le décaissement automatique.** Un remboursement est constaté à la main —
[08-refunds.md](../03-services/payments/08-refunds.md).

**L'expiration d'une obligation de remboursement.** Rien ne périme un
remboursement décidé et jamais versé, qui immobilise une part du brut.

**Tara**, dont la documentation technique n'est pas publique. Deux agrégateurs
suffisent à supprimer le point de défaillance unique ; le troisième améliore.
