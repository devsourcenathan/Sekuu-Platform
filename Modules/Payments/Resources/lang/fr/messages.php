<?php

declare(strict_types=1);

return [

    'payment_not_found' => "Ce paiement n'existe pas.",
    'payment_already_pending' => 'Un paiement est déjà en cours sur cet objet.',
    'payment_failed' => 'Le paiement a été refusé.',
    'payment_cancelled' => 'Le paiement a été annulé.',
    'payment_expired' => 'La demande de paiement a expiré sans réponse.',
    'payment_cancelled_by_payer' => 'Vous avez annulé le paiement.',
    'payment_unresolved' => "L'agrégateur n'a pas confirmé l'issue de ce paiement.",
    'payment_instructions' => 'Validez la demande sur votre téléphone, ou composez le code de votre opérateur.',

    'payable_type_unknown' => "Ce type d'objet payable n'est pas enregistré : :type.",
    'nothing_due' => "Il n'y a rien à payer sur cet objet.",

    'invalid_msisdn' => "Ce numéro n'est pas valide, ou son opérateur n'est pas reconnu.",
    'provider_unavailable' => "Aucun moyen de paiement n'est disponible pour :operator.",
    'provider_unknown' => "L'agrégateur :provider n'est pas configuré.",
    'provider_rejected' => "L'agrégateur a refusé la demande.",
    'all_providers_rejected' => "Aucun agrégateur n'a pu traiter cette demande ; aucun débit n'a été effectué.",
    'provider_fee' => 'Commission :provider',

    'webhook_signature_invalid' => 'Signature du callback invalide.',
    'webhook_provider_unknown' => 'Endpoint inconnu.',

    'currency_not_supported' => "La devise :currency n'est pas prise en charge.",
    'currency_mismatch' => 'Impossible de combiner des montants de devises différentes.',

    'charge_description' => 'Sekuu — facture :number',
    'payment_received' => 'Paiement reçu via :provider',

];
