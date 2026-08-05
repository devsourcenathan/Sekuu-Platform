<?php

declare(strict_types=1);

/*
| Le vocabulaire du document lui-même, séparé des messages d'erreur.
|
| Un fichier à part parce que ces chaînes sont **imprimées dans un document
| légal** : les changer n'a pas les mêmes conséquences que reformuler un message
| d'API, et les mélanger inviterait à le faire à la légère.
*/

return [

    'title' => 'Facture',
    'billed_to' => 'Facturé à',
    'issued_at' => 'Émise le',
    'due_at' => 'Échéance',
    'period' => 'Période',

    'description' => 'Désignation',
    'quantity' => 'Qté',
    'unit_price' => 'Prix unitaire',
    'amount' => 'Montant',

    'subtotal' => 'Sous-total',
    'tax' => 'TVA :rate',
    'credit' => 'Crédit appliqué',
    'total' => 'Total',

    'status' => [
        'open' => 'À régler',
        'paid' => 'Réglée',
        'void' => 'Annulée',
        'uncollectible' => 'Irrécouvrable',
    ],

    'footer_note' => 'Document émis par voie électronique. Montants en francs CFA, sans centime.',

];
