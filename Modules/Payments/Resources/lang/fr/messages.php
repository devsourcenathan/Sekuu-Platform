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

    'refund_not_found' => "Ce remboursement n'existe pas.",
    'refund_not_supported' => 'Le propriétaire de :type ne rembourse pas : un trop-perçu y devient un crédit.',
    'refund_exceeds_payment' => 'Ce montant dépasse ce qui reste remboursable sur ce paiement (:available).',
    'refund_payment_not_settled' => 'On ne rembourse que ce qui a été encaissé.',

    'external_charge_not_found' => "Cette demande de paiement n'existe pas.",
    'subject_type_not_allowed' => "Cette clé d'API n'est pas habilitée à faire payer ce type d'objet.",
    'subject_type_not_external' => "Ce type d'objet n'est pas servi par l'API externe.",
    'payer_type_not_allowed' => 'Un produit externe ne peut pas désigner un compte de la plateforme comme payeur.',

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
