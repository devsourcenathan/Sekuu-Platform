<?php

declare(strict_types=1);

return [

    'plan_not_found' => "Ce plan n'existe pas.",
    'plan_archived' => "Ce plan n'est plus proposé.",
    'price_not_available' => 'Aucun tarif disponible pour ce plan dans cette devise.',
    'already_on_plan' => "L'organisation est déjà sur ce plan.",

    'subscription_not_found' => 'Aucun abonnement pour cette organisation.',
    'subscription_already_active' => 'Un abonnement est déjà actif pour cette organisation.',
    'subscription_not_renewable' => 'Cet abonnement ne peut pas être renouvelé ; souscrivez à nouveau.',
    'downgrade_not_allowed' => "L'usage actuel dépasse les limites du plan visé.",

    'invoice_not_found' => "Cette facture n'existe pas.",
    'invoice_already_paid' => 'Cette facture est déjà réglée.',
    'invoice_voided' => "Cette facture a été annulée ; elle n'est pas payable.",
    'invoice_pdf_unavailable' => "Le téléchargement des factures n'est pas encore disponible.",
    'invoice_line_plan' => ':plan — :period',

    'payment_not_found' => "Ce paiement n'existe pas.",
    'payment_already_pending' => 'Un paiement est déjà en cours sur cette facture.',
    'payment_failed' => 'Le paiement a été refusé.',
    'payment_cancelled' => 'Le paiement a été annulé.',
    'payment_cancelled_by_payer' => 'Vous avez annulé le paiement.',
    'payment_unresolved' => "L'agrégateur n'a pas confirmé l'issue de ce paiement.",
    'payment_instructions' => 'Validez la demande sur votre téléphone, ou composez le code de votre opérateur.',

    'invalid_msisdn' => "Ce numéro n'est pas valide, ou son opérateur n'est pas reconnu.",
    'provider_unavailable' => "Aucun moyen de paiement n'est disponible pour :operator.",
    'provider_unknown' => "L'agrégateur :provider n'est pas configuré.",
    'provider_rejected' => "L'agrégateur a refusé la demande.",
    'all_providers_rejected' => "Aucun agrégateur n'a pu traiter cette demande ; aucun débit n'a été effectué.",
    'provider_fee' => 'Commission :provider',

    'webhook_signature_invalid' => 'Signature du callback invalide.',
    'webhook_provider_unknown' => 'Endpoint inconnu.',

    'billing_role_required' => "Seuls les propriétaires et administrateurs peuvent engager l'organisation.",

    'currency_not_supported' => "La devise :currency n'est pas prise en charge.",
    'currency_mismatch' => 'Impossible de combiner des montants de devises différentes.',

    'charge_description' => 'Sekuu — facture :number',
    'credit_applied_to_invoice' => 'Crédit appliqué à la facture :number',
    'payment_received' => 'Paiement reçu via :provider',
    'proration_credit' => 'Crédit de proration — :plan',

];
