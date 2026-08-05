# ADR-0013 — Le PDF de facture est produit une fois et figé

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

`GET /billing/invoices/{id}/pdf` rend `503` depuis l'origine du module, avec un
commentaire honnête dans le code : « le PDF appartient à Storage, qui n'existe
pas encore ». Le récapitulatif le classe premier des manques après le déploiement
— *une facture non téléchargeable est un problème légal, pas un confort*.

Storage arrivant, la question se pose enfin. Elle est double, et la seconde
moitié est celle qui compte.

## Le problème

**Qui produit le PDF ?** Storage garde des octets ; il ne sait pas ce qu'est une
ligne de facturation, quel taux de TVA s'applique, ni dans quelle langue écrire
« Total ». Mettre une facture en page depuis un module de fichiers y ferait
entrer les règles fiscales camerounaises.

**Quand le produit-on ?** À chaque demande, ou une seule fois ?

La réponse paresseuse est « à chaque demande » : pas de stockage, pas de
migration, toujours à jour. C'est précisément le défaut.

Une facture émise est un document légal. Régénérée à la demande, elle suit le
code du jour où on la télécharge. Un taux de TVA modifié, un numéro de
contribuable corrigé, un gabarit refait, une adresse d'entreprise changée — et
la facture de janvier, téléchargée en décembre, n'est plus celle qui a été
envoyée en janvier.

Personne ne s'en apercevrait. Aucun test ne peut l'attraper : le code produit
exactement ce qu'on lui demande. La divergence n'apparaît qu'en comparant deux
exemplaires du même document — c'est-à-dire lors d'un contrôle, ou d'un litige.

## Décision

**Billing produit le PDF à l'émission de la facture. Storage le garde, avec une
rétention de dix ans. La route de téléchargement redirige vers une URL signée.**

Trois conséquences directes.

### La production reste chez Billing

Elle est déclenchée par le passage de la facture à `issued`, dans une tâche de
file — mettre en page un PDF n'a pas à retarder l'émission, et un échec de mise
en page ne doit pas empêcher une facture d'exister.

### La rétention est portée par le fichier, pas par une consigne

`retain_until` à dix ans, posé par Billing au moment du rattachement. La route
de suppression de Storage la refuse, sans paramètre pour passer outre et sans
permission qui l'emporte.

C'est ce qui distingue une obligation d'une intention. Sans elle, la
conservation légale ne tiendrait qu'à ce qu'aucun module n'expose de route de
suppression — c'est-à-dire à rien.

### La régénération existe, mais elle produit un nouveau fichier

Un gabarit corrigé, une erreur de mise en page : `billing:invoice-pdf --rebuild`
attache un **nouveau** fichier et bascule la référence. L'ancien reste, avec sa
rétention. Le document envoyé au client demeure consultable.

Une régénération qui écraserait l'ancien serait exactement ce que cette ADR
refuse, avec une commande pour la commettre.

## Conséquences

**Quelques kilo-octets par facture, conservés dix ans.** À mille factures par
mois et 80 ko l'unité, c'est un gigaoctet sur la décennie. Le coût est
négligeable ; c'est l'argument le plus faible contre la décision, et le seul.

**Une facture émise avant l'arrivée de Storage n'a pas de PDF.** Elles seront
produites par une commande de rattrapage, à partir des données de facturation
telles qu'elles sont aujourd'hui. Le document ne sera donc pas identique à ce
que le client a vu à l'époque — il n'y avait rien à voir. C'est une dette
assumée du fait que le module a été livré sans PDF, pas de cette décision.

**Le `503` disparaît, mais il pouvait rester longtemps.** Il faut le noter :
répondre franchement `503` plutôt que produire à la volée un PDF dont personne
ne garantit la stabilité était déjà la bonne décision. Cette ADR ne corrige pas
une faute, elle achève un choix.

## Ce qui a été écarté

**La génération à la volée, avec cache.** Un cache ne change rien au fond :
vidé, il régénère avec le code d'aujourd'hui. Il déplace le problème dans un
endroit où on ne le cherchera pas.

**Le stockage du HTML plutôt que du PDF.** Plus léger, et rendu identique tant
que le moteur de rendu ne bouge pas — ce qu'aucune bibliothèque ne garantit sur
dix ans. Un PDF est un format d'archivage ; un HTML est une instruction de
rendu.

**Signer le PDF.** Une signature électronique donnerait une preuve
d'intégrité opposable, au-delà de « nous conservons l'original ». Cela suppose
un certificat, une autorité, et une gestion de clé qui vaut sa propre décision.
Écrit ici pour qu'on sache que la question a été vue, et laissée ouverte.
