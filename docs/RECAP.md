# Récapitulatif — état de la plateforme

> **Dernière mise à jour :** Août 2026
> **Portée :** ce document décrit ce qui est **réellement implémenté**, par opposition aux spécifications qui décrivent la cible.

---

# 1. En une page

| | |
| --- | --- |
| Application | Monolithe modulaire Laravel 13, PHP 8.3, PostgreSQL 18 |
| Modules livrés | **Identity** · **Notify** (email, SMS, interne) · **Payments** (Notch Pay, Tranzak, API externe, remboursements) · **Billing** · **Storage** · **AI** |
| Modules non démarrés | Verify, Search, Analytics |
| Déploiement | **En ligne** — Render + Neon, `platform.sekuu.com` |
| Endpoints | 115 sous `/api/v1` + `/.well-known/jwks.json` |
| Migrations | 40 |
| Tests | 682, sur PostgreSQL |
| Contrats | `Modules/*/openapi.yaml`, vérifiés par test |
| Collection de test | `postman/` — 18 dossiers, 149 requêtes |

---

# 2. Ce qui existe

## 2.1 Socle de plateforme

Dans `app/Platform/`, commun à tous les futurs modules :

| Élément | Rôle |
| --- | --- |
| `ApiResponse` | Enveloppe de réponse unique (`success` / `data` / `meta`) |
| `RequestId` + middleware | Identifiant de requête en corps, en en-tête et dans les logs |
| `DomainException` | Exception métier portant un code du catalogue |
| `ApiExceptionRenderer` | Traduit **toute** exception en réponse normalisée |
| `ModuleServiceProvider` | Socle de module : routes versionnées, sous-domaine, migrations, traductions |
| `DomainEvent` | Événement générique — le type est une chaîne, pas une classe : aucune dépendance de compilation entre modules |
| `IdentityContract` | Lecture synchrone d'Identity par les autres modules |
| `BillingContract` | Limites du plan courant d'une organisation |
| `QuotaGuard` | Refus d'une écriture dépassant le quota — le comptage reste au module appelant |
| `RequestContext` | Qui appelle, sans exposer l'infrastructure d'authentification d'Identity |
| `PayableSource` | Combien vaut un objet, qui peut le payer, et que faire une fois payé |
| `Money` | Montant entier — le franc CFA n'a pas de centime, et une seule définition de l'exposant |

Conséquence : un module ne formate jamais une erreur lui-même, et n'a rien à câbler pour exposer ses routes.

Ces contrats sont le seul moyen dont dispose un module pour en interroger un autre — jamais son modèle Eloquent, jamais sa table. Le jour où l'un est extrait, seule l'implémentation change : l'appel local devient un appel HTTP, et les appelants ne sont pas modifiés.

## 2.2 Module Identity

**Authentification** — inscription, connexion, rotation des jetons, déconnexion (un appareil ou tous), profil.

**Contexte d'organisation** — création d'organisation, changement d'organisation active, rôles et permissions globaux.

**Workspaces** — création, modification, suppression, appartenance explicite.

**Invitations** — émission, consultation publique, acceptation avec création de compte, révocation.

**Mots de passe** — réinitialisation par lien, changement depuis le profil, historique des 5 derniers.

**Vérification d'adresse** — jeton à l'inscription, renvoi de lien.

**OAuth** — Google, Microsoft, GitHub via Socialite, derrière une interface `OAuthGateway`.

**Sessions** — liste des appareils connectés, révocation ciblée.

**Journal d'audit** — 24 actions, append-only, pagination par curseur.

## 2.3 Module Notify

**Implémenté** — pipeline d'envoi complet (déduplication, résolution, rendu, filtrage, mise en file, livraison), **canaux email et SMS**, diffusion multi-canal, 10 templates de plateforme traduits fr/en, préférences par catégorie, liste de suppression, **webhooks de retour de livraison**, consultation de l'historique.

**Branché sur Identity** — six événements produisent aujourd'hui de vrais messages : inscription, vérification d'adresse, réinitialisation, changement de mot de passe, invitation, création d'organisation.

**Fournisseurs** — email : **Resend** en premier rang, Postmark en bascule, mailer Laravel en dernier recours. SMS : passerelle locale en premier rang, Twilio en bascule. Webhooks : Resend (signature Svix), Postmark, passerelle locale (DLR SMS).

Un fournisseur non configuré n'est jamais essayé : en développement, Resend est ignoré et le mailer Laravel prend la main sans configuration particulière.

**API d'envoi** — `POST /notifications`, `/bulk` et `/{id}/cancel`, protégées par une **clé d'API** portant `notifications.send`. L'organisation vient de la clé, jamais du corps.

**Désabonnement par lien** — public, jeton signé sans expiration. Selon que le destinataire a un compte, l'effet est une préférence désactivée ou une suppression de la destination.

**Canal interne** — `/inbox`, sans aucun fournisseur externe : le repli qui reste disponible quand tout le reste échoue.

**Purge** — `php artisan notify:purge`, planifiée quotidiennement par le module, avec conservation d'un agrégat par jour, canal, catégorie et statut.

**Plafond de dépense** — contrôle mensuel par organisation sur les canaux facturés, et endpoint de consommation. C'est ce qui donne un usage au coût enregistré à chaque livraison.

**Templates par API** — `GET/POST /templates`, `GET/PATCH/DELETE /templates/{id}`, `POST /templates/{id}/preview`. Les templates de plateforme restent en lecture seule ; une organisation en crée des variantes qui prennent le pas.

La catégorie d'une variante est **héritée** du template de plateforme, et `transactional` est refusé sur une clé inédite : sans cette règle, une organisation requalifierait ses invitations en transactionnel et contournerait le désabonnement — l'[ADR-0006](04-decisions/adr-0006-transactional-vs-marketing.md) serait contournable par une simple requête.

`DELETE` archive au lieu de supprimer : des messages déjà envoyés référencent le template. La prévisualisation n'envoie ni n'enregistre rien.

**Suppressions par API** — `GET/POST /suppressions`, `DELETE /suppressions/{id}`. La liste est globale à la plateforme, et le `DELETE` est journalisé : réhabiliter une adresse qui rebondit dégrade la réputation de tout le domaine.

C'est aussi le seul recours contre un faux positif de fournisseur, qui bloquait jusqu'ici définitivement une adresse valide — y compris son lien de réinitialisation — sans autre issue qu'une requête SQL.

**Non implémenté** — canaux WhatsApp et push.

## 2.4 Module Billing

**Implémenté** — catalogue de plans, abonnements prépayés, factures numérotées avec TVA figée, registre de crédit append-only, quotas de plan.

**L'encaissement appartient à [Payments](#25-module-payments)** depuis l'[ADR-0009](04-decisions/adr-0009-payments-module-extraction.md). Billing lui dit seulement combien vaut une facture et qui a le droit de la régler, via `InvoicePayable`.

**Ce qui alimente enfin `organization_products`.** Cette table existait, était lue à chaque requête, et se modifiait à la main. Identity consomme désormais les événements de Billing et applique un **état cible** — jamais un delta, puisqu'un même événement peut être livré deux fois.

Le consommateur ne touche jamais les lignes `source = 'manual'` : une activation commerciale accordée par un humain ne se révoque pas au motif qu'aucun abonnement ne la justifie.

**Le modèle est prépayé** ([ADR-0007](04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)) : il n'existe aucun moyen technique de prélever un client en Mobile Money. Le renouvellement est un acte volontaire, précédé de rappels à J-7, J-3 et J-1, suivi d'une grâce de 7 jours puis d'une suspension — jamais d'une suppression.

**Les deux adaptateurs ont été exécutés contre leur bac à sable**, et chacun a démenti deux hypothèses — jamais les mêmes. Le détail est dans [05-providers.md](03-services/payments/05-providers.md) ; le résumé est qu'aucune de ces erreurs n'était visible en test unitaire, puisque les fixtures reproduisaient les suppositions.

**Ce qui a changé ailleurs** — la table `products` d'Identity n'était seedée nulle part ; elle l'est désormais (6 produits). Sans elle, aucun plan n'avait rien à ouvrir.

**Non implémenté** — Tara (aucune documentation publique), facturation à l'usage.

**PDF de facture** — produit à l'émission, figé, conservé dix ans, et servi par une redirection vers une URL signée. Le `503` d'origine a disparu. La mise en page appartient à Billing, la conservation à Storage : confondre les deux aurait fait entrer les règles fiscales camerounaises dans un module de fichiers ([ADR-0013](04-decisions/adr-0013-invoice-pdf-frozen.md)).

**Branché sur Notify** — huit événements produisent de vrais messages : activation, renouvellement, rappels d'échéance, entrée en grâce, suspension, facture émise, facture réglée, paiement échoué. Tous transactionnels ; trois portent aussi un SMS, aux seuls moments où une action du client est attendue.

Billing ne connaissant ni utilisateurs ni adresses, il obtient le destinataire d'Identity par son **contrat public** — premier usage de la couche `app/Platform/Contracts/`, et le cas exact pour lequel elle était prévue.

**Quotas appliqués** — sièges et workspaces côté Identity, volume de SMS côté Notify. Billing publie la limite, chaque module compte sa ressource. Une limite a **trois** états — plafonnée, illimitée, non couverte — et une organisation sans abonnement n'est pas bloquée : un quota borne un usage autorisé, il ne décide pas de l'autorisation.

Le plafond de dépense de Notify n'est pas supprimé pour autant. Il était un substitut aux quotas par plan ; il redevient ce qu'il aurait dû être d'emblée, un garde-fou absolu contre une boucle ou une clé fuitée — sans lui, une organisation au plan illimité n'aurait plus aucune borne.

**Callbacks vérifiés chez les deux agrégateurs** — chaîne complète éprouvée à travers un tunnel public : authentification, déduplication, rattachement à la tentative, facture réglée, registre écrit.

Trois enseignements que seuls de vrais callbacks pouvaient donner.

Chez Notch Pay, le corps porte `event` et un `id` de premier niveau, là où la documentation annonce `type` et `data.id` — la déduplication retombait donc sur une empreinte du corps, ce qui marchait par accident mais aurait laissé passer deux fois un renvoi.

Un paiement produit **trois** livraisons dans un ordre variable : croire le statut annoncé aurait fait régresser un paiement encaissé vers « en attente ». La règle « le corps ne décide jamais de l'issue » s'est justifiée en conditions réelles.

Les deux agrégateurs n'utilisent **pas la même clé de déduplication**, et c'est délibéré. Notch Pay signe ses callbacks : son identifiant de livraison suffit. Tranzak n'authentifie que par un secret dans le corps, donc rejouable avec un identifiant forgé — sa clé ne dépend que du fait rapporté.

Le paiement Tranzak a produit la ligne `fee −3 XAF` attendue : la séparation brut / net est éprouvée contre du réel, ce que le bac à sable de Notch Pay ne permet pas.

---


## 2.5 Module Payments

**Extrait de Billing** ([ADR-0009](04-decisions/adr-0009-payments-module-extraction.md)) pour qu'un produit vendant autre chose qu'un abonnement — Sekuu Learn et ses formations — puisse encaisser sans dépendre de la facturation.

**Il ignore ce qu'il encaisse.** Une intention porte un `subject_type` et un `subject_id` qu'il n'interprète jamais. La résolution vers le module propriétaire passe par `config/payments.php` : aucun de ses fichiers n'importe Billing, et un test d'architecture le vérifie.

**Le montant est indicible.** `InitiatePayment::handle()` n'a aucun paramètre de montant — on ne *peut pas* demander à régler 49 663 XAF avec 100 XAF. Il est produit par `PayableSource::quote()`, chez le propriétaire de l'objet, qui en profite pour vérifier que ce payeur a le droit de le régler.

**Aucune route de création.** Déclencher un paiement suppose de savoir ce qu'on paie, combien cela vaut et qui peut le régler. Une route ici offrirait un moyen de faire sonner le téléphone de quelqu'un sans motif vérifiable.

**Quatre défauts corrigés au passage**, tous préexistants et invisibles :

* un paiement **sans facture** n'avait aucune protection anti-triple-clic — l'index d'unicité les excluait explicitement, et c'était le cas nominal de Learn ;
* l'idempotence était globale et lue sans filtre : deux produits dérivant leurs clés du métier auraient pu se renvoyer mutuellement leurs intentions ;
* aucun verrou sur l'intention pendant l'encaissement — deux exécutions concurrentes pouvaient écrire deux lignes `charge` ;
* une tentative morte avant l'appel de débit n'était ni sondée ni expirée, et bloquait indéfiniment l'unicité « une seule tentative vivante ».

**Un service externe peut encaisser** ([ADR-0010](04-decisions/adr-0010-external-payment-api.md), [07-external-api.md](03-services/payments/07-external-api.md)). Sekuu Learn en dépendait.

La règle n'a jamais été « le montant ne vient jamais d'HTTP » — elle est **seul le propriétaire de l'objet nomme son prix**. Un module le prouve en implémentant une interface ; un service externe le prouve en présentant une clé d'API qui porte la liste des `subject_type` qu'il possède. Le montant est écrit dans `external_charges` — l'analogue d'une facture — puis **relu en base** par `quote()`. `InitiatePayment` n'a toujours aucun paramètre de montant.

Deux bornes indépendantes, et il faut les deux : la clé porte le périmètre, et le type doit être servi par l'API externe côté plateforme. **`billing.invoice` ne peut être porté par aucune clé** — la garde est à l'émission. Un `payer_type` en `identity.*` est refusé : un produit externe ne peut pas se réclamer d'un compte de la plateforme.

Ce que cela **ne** donne pas : l'atomicité. Un service externe ne participe pas à la transaction d'encaissement, donc il existe une fenêtre où un client a payé et n'a pas son service. Elle est irréductible ; elle est seulement rendue courte — webhook signé, réessayé cinq fois — et rattrapable — sondage et réconciliation, présentés comme **obligatoires** et non comme des options.

Deux choix qui méritent d'être connus. Un endpoint durablement injoignable **n'est pas désactivé** : le désactiver transformerait une panne de quelques heures en silence permanent. Et la rotation du secret émet **deux signatures** pendant une fenêtre, plutôt qu'une coupure nette qui ferait échouer les livraisons d'un produit déployant une heure plus tard.

L'URL et le secret ne passent pas par l'API : ils se déclarent avec `payments:endpoint`, donc par un opérateur. Une clé fuitée ne doit pas suffire à détourner l'issue de tous les paiements d'un produit vers un serveur choisi.

**Le remboursement existe** ([ADR-0011](04-decisions/adr-0011-refunds.md), [08-refunds.md](03-services/payments/08-refunds.md)), total ou partiel, avec deux invariants.

**On ne rend jamais plus qu'on n'a encaissé** — plafond gardé par la couche de paiement, sur le cumul, sous verrou, et un remboursement en attente immobilise déjà la somme. **On ne rend pas deux fois** : c'est le miroir du double débit, avec une différence qui a commandé la conception — le client n'a aucune raison de signaler l'erreur.

Le propriétaire de l'objet décide, par `RefundableSource`. **Ne pas porter cette interface est une réponse**, et c'est celle de Billing : un trop-perçu y devient un crédit. L'ajouter à `PayableSource` aurait forcé chaque produit à écrire une méthode pour dire non, et la première copie aurait dit oui par distraction.

**Le décaissement reste manuel**, et c'est délibéré. Un remboursement Mobile Money est un transfert, pas l'annulation d'un débit : aucun agrégateur ne documente un bac à sable de décaissement, et écrire l'adaptateur sans pouvoir l'éprouver reproduirait l'erreur du canal SMS — sur de l'argent qui **sort**. Un opérateur vire, puis constate avec `payments:refund`. La couture est prête : `SettleRefund` est le point d'entrée unique.

La ligne `refund` du registre n'est écrite qu'au décaissement constaté — un registre append-only ne peut pas porter un `pending` qu'on corrigerait ensuite.

**Non implémenté également** — encaisser pour le compte d'un tiers. `payee_organization_id` existe et laisse la porte ouverte, mais rien derrière n'est construit : pas de compte de destination, pas de type `payout`, pas d'état de reversement. Un produit externe encaisse donc pour le compte de la plateforme, et le reversement se fait hors système.

## 2.5bis Administrer la plateforme

Jusqu'ici personne n'agissait au nom de **Sekuu** : tous les rôles sont portés par une organisation. Changer un quota supposait une migration, et le changement s'appliquait rétroactivement à tout le monde.

**Un opérateur est marqué hors de l'application** — `identity:operator`, jamais une route, jamais un rôle d'organisation. Des permissions **séparées** plutôt qu'un drapeau : corriger un quota n'ouvre pas les factures de tous les clients. `platform.operators` existe pour être refusée.

**Chaque appel est journalisé, lectures comprises.** Consulter la facture d'un client, c'est accéder à une donnée qui ne nous appartient pas ; sans trace, la seule garantie offerte au client est notre parole. Une tentative refusée est tracée aussi — c'est la première chose qu'on cherchera le jour d'un incident.

**Trois choses restent hors de portée**, même pour un opérateur : le contenu d'un fichier, d'un prompt, d'une notification. Il constate qu'un document existe ; il ne l'ouvre pas.

**Les limites accordées sont figées sur l'abonnement** ([ADR-0019](04-decisions/adr-0019-granted-limits.md)). Sans cela, baisser une limite le mardi rétrograderait le soir même un client ayant payé une année d'avance. D'où l'asymétrie : **une hausse s'applique tout de suite, une baisse au renouvellement** — la plateforme peut être plus généreuse que promis, jamais moins.

**Un défaut trouvé en écrivant les tests.** `PATCH` remplaçait toute la table des limites : envoyer une seule clé effaçait les autres, en une requête qui répond `200`. Il fusionne désormais, et retirer une limite demande de la nommer — fermer une ressource ne doit pas être l'effet de bord d'autre chose.

**Une dette datée** : il n'y a pas de second facteur. Un mot de passe sépare un attaquant du catalogue et de la liste des clients.

---

## 2.6 Module Storage

**Il ignore ce qu'il garde.** Un fichier porte un `owner_type` et un `owner_id` qu'il n'interprète jamais — la même architecture que Payments, et pour la même raison : le PDF d'une facture et la vidéo d'un cours empruntent le même chemin sans que l'un sache que l'autre existe.

L'invariant de Payments s'y transpose : « seul le propriétaire de l'objet nomme son prix » devient **seul le propriétaire de l'objet dit qui peut le lire**. Storage ne connaît ni les rôles ni les workspaces ; il demande, par le contrat `FileOwner`.

**Les octets ne traversent jamais l'API** ([ADR-0012](04-decisions/adr-0012-direct-upload.md)). Le client obtient une URL signée et écrit directement dans le magasin : déclarer, écrire, confirmer. Une vidéo de 200 Mo tuerait le conteneur Render, le proxy coupe de toute façon à 100 Mo, et le disque du conteneur est éphémère — un fichier écrit dedans disparaîtrait au déploiement suivant, **sans erreur**.

**La déclaration ne fait jamais foi.** À la confirmation, le magasin est interrogé et ce qu'il répond écrase ce que le client avait annoncé. C'est la règle des callbacks de paiement, transposée : sans elle, le contrôle de type et le quota borneraient une déclaration, pas un fichier.

**Le magasin est une donnée** ([ADR-0014](04-decisions/adr-0014-storage-destinations.md)). Plusieurs comptes par fournisseur, un client peut apporter le sien, et la résolution se fige sur la ligne du fichier — changer une règle n'affecte que les fichiers à venir. Ajouter un compte ou un service compatible S3 ne demande pas de code ; ajouter une famille nouvelle demande une classe de cinq méthodes, et [le document le dit franchement](03-services/storage/06-destinations.md).

**Rien n'est public, et rien n'est permanent.** Les URL de lecture durent dix minutes et pointent vers l'hôte du magasin, jamais vers `sekuu.com` — ce qui neutralise le vecteur principal d'un service de fichiers. Tout ce qui n'est ni image ni PDF est servi en pièce jointe.

**Une destination non éprouvée ne sert jamais.** À l'enregistrement puis chaque nuit : écrire un objet témoin, le relire, l'effacer. Une clé révoquée chez le fournisseur bascule la destination en `unverified` et publie un événement — avant qu'un client ne le découvre.

**Photo de profil** — le premier fichier **déposé par une personne**, et donc le premier usage réel du chemin en trois temps. On ne dépose que sur son propre profil : changer le visage de quelqu'un d'autre n'est pas une opération d'administration, c'est une usurpation. Lisible par ses collègues, et par eux seuls. Le SVG est délibérément absent de la liste des types — un avatar est la seule chose que la plateforme rende **en ligne**, et un SVG est un document qui peut porter du script.

**PDF de facture** — le cas qui a motivé le module. Produit par Billing à l'émission, confié à Storage avec dix ans de rétention, servi par un `302`. **Figé** : régénéré à la demande, il suivrait le code du jour, et la divergence n'apparaîtrait qu'en comparant deux exemplaires du même document ([ADR-0013](04-decisions/adr-0013-invoice-pdf-frozen.md)).

**Non implémenté, et écrit plutôt qu'à moitié fait** — vignettes, transcodage, analyse antivirale, migration d'une destination à une autre. C'est l'enseignement du canal SMS de Notify : du code qu'on ne peut pas exécuter n'est pas une avance, c'est une dette qui se croit livrée.

**Un défaut trouvé en implémentant.** La mise en page du PDF est confiée à une file, et j'avais laissé son échec remonter jusqu'à l'appelant : sur une file synchrone — le cas en test, et possible en production — un magasin mal configuré empêchait de **facturer**. La tâche avale donc ses échecs, et la reprise est portée par `billing:invoice-pdf`, ordonnancée chaque nuit. Le défaut serait resté invisible tant qu'un magasin répond.

## 2.7 Module AI

**Une tâche, jamais un modèle** ([ADR-0015](04-decisions/adr-0015-ai-task-not-model.md)). Il n'existe aucun champ `model` dans l'API, et il n'y en aura pas : un appelant demande `summarize` ou `prompt-deep`, la plateforme choisit. Envoyer `model`, `temperature`, `max_tokens`, `top_p` ou `system` rend `422` — ils ne sont pas ignorés, parce qu'un champ ignoré en silence est un champ dont l'appelant croit qu'il agit.

Le prix se paie ailleurs : c'est **nous** qui portons la dette de dépréciation. Quand un fournisseur annonce un retrait, on marque le modèle `deprecated`, `ai:models` dit quelles tâches le nomment — replis compris — puis on le passe `retired`, et aucun produit n'a rien à changer.

**Les tâches libres existent, et ne contredisent pas l'invariant.** `prompt`, `prompt-fast` et `prompt-deep` acceptent un texte quelconque : ce qui est refusé est que l'appelant nomme le *modèle*, pas qu'il écrive librement. Ce qu'elles perdent est réel — aucun format de sortie promis, donc aucune validation — et ce sont les bornes de jetons qui tiennent le coût à la place du schéma.

**Un pilote est un protocole, pas une entreprise.** `openai` sert OpenAI, Google, DeepSeek, Mistral, xAI, Groq, Together, Fireworks, DeepInfra, OpenRouter, Azure et les serveurs locaux : **treize services pour deux pilotes**. Ajouter un service est une ligne de configuration ; ajouter une famille d'authentification — Bedrock et sa signature SigV4 — demande une classe. C'est la limite exacte posée par [ADR-0017](04-decisions/adr-0017-ai-accounts.md), et c'est la même que celle du pilote S3 de Storage.

**Nos clés et les leurs**, et c'est le cas nominal. Un client dépose sa clé ; son quota ne s'applique plus, notre coût devient une **estimation** — son tarif négocié donne autre chose — et **un compte de tiers ne bascule jamais vers un des nôtres**. Ce serait payer à sa place, sans que personne l'ait décidé, et la surprise arriverait un mois plus tard.

**Un compte non éprouvé ne sert jamais.** L'épreuve est une **génération réelle d'un jeton** : une épreuve qui listerait les modèles ne prouverait rien, un compte pouvant lister sans avoir de crédit. Elle coûte, donc elle est quotidienne et non horaire — contrairement à celle de Storage, qui écrit trente octets gratuits — et son coût est imputé à la plateforme, jamais au propriétaire du compte.

**La bascule est étroite** ([ADR-0016](04-decisions/adr-0016-ai-spend-and-privacy.md)). On ne réessaie ailleurs que si la requête n'a jamais atteint le modèle. Passé le premier jeton, les jetons sont facturés qu'on obtienne une réponse ou non : réessayer paie deux fois et rend une réponse différente de celle qui arrivait peut-être. C'est l'[ADR-0008](04-decisions/adr-0008-payment-aggregators-failover.md) transposée mot pour mot — *l'incertitude compte comme un appel abouti* — et la liste des motifs de bascule est **blanche** : un code inconnu ne bascule pas.

**Rien n'est conservé du contenu.** Ni le prompt ni la sortie, sauf si la tâche le déclare. Une empreinte suffit à l'idempotence ; les métriques suffisent à la facturation. Trois raisons, dans l'ordre de gravité : le prompt d'un produit de santé porte des données de santé, un registre de prompts est la cible la plus intéressante de la plateforme, et il grossit sans limite. La sortie survit brièvement — sans quoi un sondage n'aurait rien à lire — et **la lecture la consomme**.

**Deux bornes de dépense, et il en faut deux.** Le quota du plan (`ai_credits_monthly`, modifiable sans toucher au code) et le plafond absolu de la plateforme. Supprimer le second laisserait une organisation au plan illimité sans aucune borne — et une clé fuitée sur un plan « illimité » est précisément le scénario où l'on perd de l'argent.

**Le webhook ne porte jamais le contenu.** Il part vers une URL déclarée par le produit, en clair sur le réseau public : y mettre la sortie reviendrait à publier ce qu'on refuse de stocker. Le produit apprend qu'une sortie l'attend, et vient la chercher authentifié.

**Non implémenté, et écrit plutôt qu'à moitié fait** — le pilote Bedrock, les outils (*function calling*), la vision, le *streaming*, et l'API native de Gemini. Google passe par son point d'accès compatible OpenAI ; le jour où une capacité n'y passera pas, un pilote `gemini` s'ajoutera **à côté**, et les comptes existants continueront de fonctionner.


---

# 3. Décisions structurantes

Les dix-neuf ADR de [`04-decisions/`](04-decisions/) portent les décisions d'architecture. En complément, voici les arbitrages pris pendant l'implémentation.

## 3.1 Frontières entre domaines

| Sujet | Propriétaire | Ce qu'Identity fait |
| --- | --- | --- |
| Plans, abonnements, paiements | **Billing** | Consomme des événements, maintient `organization_products` comme cache de droits |
| Envoi de messages | **Notify** | Publiera des événements ; n'envoie rien |
| Permissions métier | **Chaque produit** | Ne les connaît pas |

## 3.2 Authentification

* Access token JWT **RS256**, 15 minutes. Les consommateurs ne détiennent que la clé publique : aucun produit ne peut forger un token.
* Refresh token opaque, 30 jours, stocké haché, **rotation à chaque usage** avec détection de rejeu.
* Le token ne porte **aucune donnée personnelle** — uniquement des identifiants, rôles et scopes globaux.
* Un token sans claim `org` n'ouvre que les routes de profil.

## 3.3 Isolation entre organisations

La règle appliquée partout : **une ressource hors périmètre renvoie `404`, jamais `403`** — un `403` confirmerait son existence.

Seule exception délibérée : un workspace de sa **propre** organisation dont on n'est pas membre renvoie `403 WORKSPACE_ACCESS_DENIED`. Entre collègues, l'existence n'est pas un secret.

L'organisation provient **toujours** du token. Lorsqu'elle apparaît aussi dans l'URL, les deux doivent correspondre : une URL ne peut jamais élargir la portée d'un token.

---

# 4. Écarts assumés par rapport aux spécifications

Ces points divergent des documents de spécification. Ils y sont signalés, et sont rappelés ici pour éviter qu'ils ne se perdent.

| Écart | Raison | Réversible ? |
| --- | --- | --- |
| Table `user_sessions`, pas `sessions` | Laravel réserve `sessions` à son driver de session web ; la collision aurait été silencieuse | Non — renommage coûteux |
| Révocation via la base, pas Redis | Redis pas déployé. Sémantique identique, révocation même immédiate, mais une lecture par requête | Oui — bascule prévue |
| Refresh token en cookie **et** dans le corps | Aucun signal fiable ne distingue un client web d'un client natif | Oui — nécessite d'identifier les clients |
| Réinitialisation marque l'adresse vérifiée | Recevoir le lien prouve la maîtrise de la boîte, comme pour une invitation | Oui |
| Jetons exposés en réponse API | Les messages partent désormais réellement ; l'exposition ne subsiste que par **confort de développement**, limitée à `local` et `testing` | Oui — supprimable dès qu'une boîte de test est en place |

---

# 5. Ce qui est garanti par des tests

Les tests ne couvrent pas seulement le chemin nominal ; ils verrouillent les propriétés qui, si elles régressaient, ne se verraient pas.

**Conventions d'API** — enveloppe standard, `request_id` en corps et en en-tête, `404` sur route inconnue, identifiant client rejeté s'il est malformé.

**Non-énumération des comptes** — email inconnu et mot de passe faux renvoient un code **et un message identiques** ; `forgot-password` répond `202` dans tous les cas, y compris pour un compte suspendu.

**Contenu du token** — le payload est décodé et vérifié : ni email, ni nom, ni permission métier.

**Isolation** — pour chaque ressource, un token de l'organisation A obtient `404` sur une ressource de B. Vérifié sur `GET`, `PATCH`, `DELETE` et les sous-ressources.

**Rotation et vol de jeton** — rejouer un refresh token révoque toute la session, y compris le jeton légitime.

**Journal d'audit** — immuable (`update` et `delete` lèvent une exception), filtrage récursif des secrets vérifié sur l'ensemble des entrées d'un scénario complet.

**Schéma PostgreSQL** — `citext` (unicité insensible à la casse), contrainte `CHECK` sur `status`, index uniques partiels (un compte supprimé libère son adresse).

**Contrat OpenAPI** — parité exacte avec les routes réelles, références résolues, codes d'erreur présents au catalogue.

**Accord entre l'environnement et les identifiants** — une clé de paiement de production hors production est refusée, et une clé de test en production aussi. Les deux fautes coûtent : la première débite de vraies personnes, la seconde ouvre des services sans rien encaisser.

**Règle de bascule** — table de vérité **exhaustive** itérant sur `AttemptStatus::cases()` : un état ajouté demain sans décision explicite fait échouer le test. C'est l'endroit où une régression coûte de l'argent réel à un tiers.

**Indépendance de Payments** — aucun fichier de `Modules/Payments`, code de test compris, ne nomme Billing. Le chemin de paiement s'éprouve donc entièrement sur un objet payable factice ; ce qui reste chez Billing est ce qui n'a de sens que pour une facture.

**Le cycle complet d'un fichier, contre un vrai magasin** — déclarer, écrire, confirmer, lire, supprimer, sans compte externe ni réseau. Le pilote local émet de vraies URL signées : c'est ce qui manquait au canal SMS de Notify, intégralement écrit et jamais exécuté.

**La déclaration n'engage rien** — un client qui annonce 5 octets et en écrit 200 voit sa confirmation refusée, et l'objet effacé. Le test l'exécute réellement, il ne le simule pas.

**Le repli de destination n'existe que déclaré** — sept cas de résolution, dont celui qui vérifie qu'une destination nommée mais tombée **échoue** au lieu de se rabattre sur le défaut. C'est l'endroit où une régression produirait une facture de trafic sortant que personne ne verrait venir.

**Un fichier ne déménage jamais** — changer une règle de placement laisse les fichiers existants là où ils sont. Sans ce test, rebrancher une organisation rendrait illisibles tous ses fichiers antérieurs, d'un coup et sans erreur.

**La rétention l'emporte sur tout le monde** — le PDF d'une facture répond `409` à toute suppression pendant dix ans, quel que soit l'appelant.

**Les deux vrais pilotes d'IA sont exécutés** — en-têtes, format des messages, lecture des jetons, classification des erreurs, contre `Http::fake`. Écrit avant le reste du module, en réponse directe au pilote S3 qui avait été écrit, documenté et recommandé sans jamais être instancié : 561 tests ne l'avaient pas vu, et le défaut est apparu au premier démarrage en production.

**Chaque modèle d'une chaîne satisfait les exigences de sa tâche** — vérifié sur la déclaration, jamais à l'exécution. Un repli sans `json` sur une tâche qui en exige produirait une sortie invalide **sur le chemin le moins testé**, celui qui ne sert que quand le premier modèle est déjà tombé.

**Un délai dépassé ne bascule jamais** — et la liste des motifs qui basculent est blanche, donc un code inconnu ne bascule pas. C'est l'endroit où une régression fait payer deux fois la même génération.

**Le webhook ne porte ni le prompt ni la sortie** — le test cherche littéralement le contenu dans la charge utile livrée.

**Tout scope qu'un module oppose à un appelant est émissible** — les constantes des traits `Resolves*Actor` sont confrontées à la liste fermée d'`IssueApiKey`. Ajouté après avoir découvert qu'aucune clé de Storage ne l'était.

**Toute clé de traduction citée par le code existe** — le contrôle part du code, pas de la comparaison entre langues : deux fichiers cohérents mais tous deux incomplets satisfaisaient le test précédent.

---

# 6. Des défauts que les tests ont révélés

Ils méritent d'être mentionnés parce qu'ils étaient invisibles à la lecture.

**La révocation anti-vol était annulée par le rollback.** À la détection d'un rejeu de refresh token, la session était révoquée *à l'intérieur* de la transaction, puis l'exception provoquait un rollback qui effaçait la révocation. Le vol restait donc sans conséquence.

**La clé étrangère auto-référencée ne se créait pas sur PostgreSQL.** Laravel émet les contraintes `FOREIGN KEY` avant la `PRIMARY KEY` ; Postgres refusait l'auto-référence. SQLite laissait passer, ce qui est précisément l'argument pour tester sur le moteur de production.

**Aucune clé d'API de Storage n'était émissible.** `storage.write`, `storage.read` et `storage.destinations` étaient exigés à chaque appel, documentés, et absents de la liste fermée d'`IssueApiKey`. Rien ne l'a signalé : les tests de Storage écrivent leurs clés directement en base, et vérifiaient donc le contrôleur **en contournant précisément la voie cassée**. C'est la même forme que le pilote S3 jamais instancié — un chemin éprouvé, et le vrai chemin à côté.

**Trois clés de traduction citées par du code n'existaient pas**, dont une sur une route d'opérateur livrée la semaine précédente. Laravel rend la clé brute quand la traduction manque : un client aurait reçu `ai::messages.already_started` en guise de phrase. Mes propres tests d'API ne l'avaient pas vu, parce qu'ils n'assertaient que le code d'erreur.

**Un `upsert` porteur de valeur comptait deux fois.** Trouvé dans `StorageQuota::adjust`, et il ne se voyait que le premier jour du mois — la première écriture d'une période écrivait le montant puis l'incrémentait. Le registre de dépense d'AI écrit donc des zéros, puis incrémente.

---

# 7. Utiliser l'API

Le dossier **16 — Plateforme (opérateur)** est à part : ses routes ne s'adressent pas à une organisation mais à Sekuu, et exigent une habilitation posée par `identity:operator` — jamais par une route.

## 7.1 Démarrer

```bash
composer install && cp .env.example .env && php artisan key:generate
```

```bash
php artisan migrate && php artisan serve
```

## 7.2 Tester

Suite automatisée (PostgreSQL requis, base `sekuu_testing`) :

```bash
php artisan test
```

Exploration manuelle : importer [`postman/Sekuu-Platform.postman_collection.json`](../postman/Sekuu-Platform.postman_collection.json) et l'environnement associé. 77 requêtes couvrant les 55 routes des deux modules ; les jetons sont capturés automatiquement d'une requête à l'autre.

## 7.3 Parcours minimal

```text
POST /auth/register              →  access_token
POST /organizations              →  organization_id
POST /auth/switch-organization   →  access_token contextualisé
POST /workspaces                 →  workspace_id
GET  /audit-logs                 →  trace des quatre étapes
```

---

# 8. Ce qui reste

## 8.1 Déployé

La plateforme tourne sur **Render**, image Docker, base **Neon**, domaine
`platform.sekuu.com`. Un seul conteneur porte nginx, php-fpm, le worker de files
et l'ordonnanceur — l'offre gratuite de Render n'ayant pas de background worker.

`GET /api/v1/health` répond `database: ok`, `GET /api/v1/payments/health`
répond `can_collect: true` avec Notch Pay et Tranzak en production.

Procédures : [mise en service](06-operations/01-go-live.md) ·
[déploiement](06-operations/03-deployment.md) ·
[Render](06-operations/04-render.md) ·
[offre gratuite](06-operations/05-free-tier.md)

### Ce que le déploiement a appris

Cinq défauts que ni les 510 tests ni une relecture n'auraient trouvés, tous du
même genre — du code qui marche là où il a été écrit :

* `tests/Unit` déclaré dans `phpunit.xml` mais absent d'un clone, Git ne
  versionnant pas les répertoires vides. La suite entière échouait ;
* un test de sous-domaine qui ne passait que sur une machine dont le `.env` ne
  portait pas la clé — `refreshApplication()` relit `.env` et écrase les trois
  sources que `env()` consulte ;
* `payments` absent de `config/sekuu.php` depuis son extraction : son
  sous-domaine aurait été ignoré **sans erreur** ;
* la commande de démarrage de Render remplace la commande entière, pas le `CMD` ;
* `HOME=/root` rendant impossible toute connexion PostgreSQL en TLS, avec un
  message désignant un fichier de certificat inexistant.

Les trois premiers sont désormais verrouillés par des tests.

## 8.1.1 Ce qui reste avant un vrai client

* **Les URL de callback** dans les tableaux de bord Notch Pay et Tranzak, avec
  `TRANZAK_AUTH_KEY` et `NOTCHPAY_WEBHOOK_HASH`. Sans ces secrets, aucun
  callback n'est accepté — la réconciliation rattrape, plus lentement.
* ~~Le premier paiement réel~~ — **fait**, et le remboursement avec.

  Le parcours complet a été exercé en production : invite reçue et validée,
  issue constatée, charge à `paid`, puis un remboursement partiel décidé,
  décaissé à la main et constaté avec la référence du transfert.

  Le registre porte les trois lignes attendues — `charge +100`, `fee -3`,
  `refund -40`. La commission est lue correctement par l'adaptateur, ce que le
  bac à sable Tranzak avait mis en défaut ; le taux constaté est de **3 %**.

  Reste connu : le nom affiché sur l'invite est celui de l'agrégateur, pas
  celui de Sekuu — voir [ADR-0008](04-decisions/adr-0008-payment-aggregators-failover.md).
* **Sortir de l'offre gratuite.** Le service dort après quinze minutes, et le
  worker comme l'ordonnanceur dorment avec lui : un callback arrivant pendant le
  sommeil est perdu, et le filet censé le rattraper dort aussi. Acceptable pour
  valider, jamais pour encaisser l'argent d'un tiers —
  [05-free-tier.md](06-operations/05-free-tier.md).
* **Le domaine expéditeur** vérifié chez Resend (DKIM, Return-Path, DMARC).
  Sans cela les messages partent par le mailer Laravel, qui ne rapporte aucun
  rebond : le service paraît fonctionner tout en accumulant une dette de
  délivrabilité invisible.
* **`RUN_MIGRATIONS_ON_BOOT` à retirer** au passage au payant, au profit d'un
  `preDeployCommand` : un échec de migration doit annuler un déploiement, pas se
  découvrir en production.

## 8.2 Prochaines étapes

Par ordre décroissant de valeur :

* **Le premier paiement réel**, fait à la main sur un petit montant. C'est la seule vérification qui prouve que la chaîne entière fonctionne : les deux agrégateurs ont déjà démenti deux hypothèses chacun lors de leur intégration, et aucun bac à sable ne prouve la production.
* **La documentation Tara**, à réclamer directement — elle n'est pas publique. Deux agrégateurs suffisent à supprimer le point de défaillance unique ; le troisième améliore.

Puis Verify.

**Avant qu'AI serve un vrai client**, trois gestes qui ne sont pas du code :

* poser `ai_credits_monthly` sur les plans, par `PATCH /platform/plans/{key}` — en **millionièmes de dollar**, l'unité dans laquelle les fournisseurs facturent ;
* poser au moins un compte de la plateforme, par `ai:account` ou par `AI_DEFAULT_*` là où il n'y a pas de shell ;
* vérifier que le fournisseur choisi garantit contractuellement le **non-entraînement** sur les données envoyées par l'API. C'est le cas des offres professionnelles d'Anthropic, d'OpenAI, de Google et de Mistral — pas de leurs offres grand public, ni de tous les intermédiaires. Rien dans le code ne peut le détecter.

Le canal WhatsApp reste le plus attendu au Cameroun ; il suppose un compte Business vérifié et des modèles approuvés par Meta, donc un délai externe qu'il vaut mieux engager tôt.

## 8.3 Dette identifiée

* Aucun endpoint de listing des rôles globaux — la collection Postman doit lire l'identifiant en base.
* `GET /users` et `PATCH /users/{id}` sont spécifiés mais pas implémentés.
* Pas de MFA ni de passkeys — prévus au modèle, non développés.
* Identifiants en français dans cinq modules — `$payeur`, `$bascule`, `$repli`, `$limites`. Le dépôt écrit son code en anglais et ses commentaires en français ; AI a été aligné, le reste attend.
* Internationalisation limitée à `en` et `fr`. Ajouter une langue suppose de traduire les 93 clés et les 10 templates de Notify ; un test échoue tant qu'une clé manque.
