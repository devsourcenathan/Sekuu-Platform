<?php

declare(strict_types=1);

use Modules\Storage\Infrastructure\Drivers\LocalDriver;
use Modules\Storage\Infrastructure\Drivers\S3Driver;

/*
| Configuration du module Storage.
|
| Les **magasins** ne sont pas ici : ce sont des lignes de la table
| `storage_destinations`. Ce fichier ne décrit que ce qui est du code — les
| pilotes — et ce qui n'a de sens qu'au déploiement.
|
| @see docs/03-services/storage/06-destinations.md
| @see docs/04-decisions/adr-0014-storage-destinations.md
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Pilotes
    |--------------------------------------------------------------------------
    |
    | Un pilote est un **protocole**, pas un fournisseur. `s3` sert AWS, R2, B2,
    | Scaleway, Wasabi et MinIO : ils ne diffèrent que par un point d'accès.
    |
    | Ajouter une famille nouvelle — Google Drive, Azure Blob — demande une
    | classe implémentant `StorageDriver`, et une ligne ici. C'est irréductible :
    | un pilote doit savoir fabriquer une autorisation d'écriture chez son
    | fournisseur, ce qui est un protocole d'authentification, pas un paramètre.
    |
    */

    'drivers' => [
        's3' => S3Driver::class,
        'local' => LocalDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Préréglages
    |--------------------------------------------------------------------------
    |
    | Les particularités d'un service parlant le protocole d'un pilote. **De la
    | donnée** : ajouter Wasabi, Vultr, OVH ou Cellar demande une entrée ici, et
    | rien d'autre.
    |
    | Une destination peut s'en passer complètement, en fournissant son point
    | d'accès à la main : le préréglage est un confort, pas un passage obligé.
    |
    | Les segments `{…}` sont remplacés par les valeurs de `config` de la
    | destination.
    |
    */

    'presets' => [

        'aws' => [
            'driver' => 's3',
            'requires' => ['bucket', 'region'],
        ],

        // Trafic sortant gratuit : le poste qui décide de la facture d'un
        // produit servant des vidéos depuis le Cameroun.
        'r2' => [
            'driver' => 's3',
            'endpoint' => 'https://{account_id}.r2.cloudflarestorage.com',
            'region' => 'auto',
            'path_style' => false,
            'requires' => ['bucket', 'account_id'],
        ],

        'b2' => [
            'driver' => 's3',
            'endpoint' => 'https://s3.{region}.backblazeb2.com',
            'path_style' => true,
            'requires' => ['bucket', 'region'],
        ],

        'scaleway' => [
            'driver' => 's3',
            'endpoint' => 'https://s3.{region}.scw.cloud',
            'requires' => ['bucket', 'region'],
        ],

        'minio' => [
            'driver' => 's3',
            'path_style' => true,
            'requires' => ['bucket', 'endpoint'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Objets porteurs de fichiers
    |--------------------------------------------------------------------------
    |
    | `owner_type` → module propriétaire. C'est **le seul endroit** où la couche
    | de stockage apprend qu'une facture existe : aucun de ses fichiers
    | n'importe Billing, et un test d'architecture le vérifie.
    |
    | Ce fichier est la racine de composition, pas du code de module : c'est sa
    | raison d'être de connaître les deux côtés.
    |
    | Un type absent échoue durement (`FILE_OWNER_TYPE_UNKNOWN`) : un repli
    | silencieux ferait aboutir un téléversement que personne ne saurait
    | rattacher.
    |
    */

    'owners' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Durées
    |--------------------------------------------------------------------------
    |
    | `upload_ttl` borne une autorisation d'écriture, `read_ttl` une
    | autorisation de lecture. Courtes toutes les deux : une URL signée est un
    | droit **daté**, et reprendre l'accès consiste simplement à ne plus en
    | délivrer.
    |
    | `orphan_after` est le délai au bout duquel une déclaration jamais
    | confirmée est balayée. Nettement plus long que `upload_ttl` : un client
    | dont l'écriture a réussi mais dont la confirmation s'est perdue doit avoir
    | le temps de réessayer.
    |
    | `purge_after` sépare la suppression logique de l'effacement réel. Un
    | `DELETE` accidentel reste réparable pendant ce délai.
    |
    */

    'upload_ttl' => 15 * 60,
    'read_ttl' => 10 * 60,
    'orphan_after_hours' => 24,
    'purge_after_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Types servis en ligne
    |--------------------------------------------------------------------------
    |
    | Tout ce qui n'est pas dans cette liste est servi en pièce jointe : le
    | navigateur télécharge, il n'interprète pas.
    |
    | La liste est **close et courte**. Un HTML téléversé par un client et
    | interprété par un navigateur est le vecteur principal d'un service de
    | fichiers ; il ne peut rien atteindre chez nous puisque l'URL pointe vers
    | un autre hôte, mais rien n'oblige à le rendre commode.
    |
    */

    'inline_mime_types' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ],

];
