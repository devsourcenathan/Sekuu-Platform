<?php

declare(strict_types=1);

return [

    'payment_not_found' => 'This payment does not exist.',
    'payment_already_pending' => 'A payment is already in progress for this item.',
    'payment_failed' => 'The payment was declined.',
    'payment_cancelled' => 'The payment was cancelled.',
    'payment_expired' => 'The payment request expired without an answer.',
    'payment_cancelled_by_payer' => 'You cancelled the payment.',
    'payment_unresolved' => 'The aggregator never confirmed the outcome of this payment.',
    'payment_instructions' => 'Approve the request on your phone, or dial your operator code.',

    'external_charge_not_found' => 'This charge does not exist.',
    'subject_type_not_allowed' => 'This API key may not charge this subject type.',
    'subject_type_not_external' => 'This subject type is not served by the external API.',
    'payer_type_not_allowed' => 'An external product cannot name a platform account as the payer.',

    'payable_type_unknown' => 'This payable type is not registered: :type.',
    'nothing_due' => 'There is nothing to pay on this item.',

    'invalid_msisdn' => 'This phone number is invalid, or its operator is not recognised.',
    'provider_unavailable' => 'No payment method is available for :operator.',
    'provider_unknown' => 'The :provider aggregator is not configured.',
    'provider_rejected' => 'The aggregator rejected the request.',
    'all_providers_rejected' => 'No aggregator could process this request; nothing was charged.',
    'provider_fee' => ':provider fee',

    'webhook_signature_invalid' => 'Invalid callback signature.',
    'webhook_provider_unknown' => 'Unknown endpoint.',

    'currency_not_supported' => 'The :currency currency is not supported.',
    'currency_mismatch' => 'Cannot combine amounts in different currencies.',

    'charge_description' => 'Sekuu — invoice :number',
    'payment_received' => 'Payment received via :provider',

];
