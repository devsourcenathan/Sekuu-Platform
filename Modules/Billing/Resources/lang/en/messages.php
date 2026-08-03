<?php

declare(strict_types=1);

return [

    'plan_not_found' => 'This plan does not exist.',
    'plan_archived' => 'This plan is no longer offered.',
    'price_not_available' => 'No price available for this plan in that currency.',
    'already_on_plan' => 'The organization is already on this plan.',

    'subscription_not_found' => 'No subscription for this organization.',
    'subscription_already_active' => 'An active subscription already exists for this organization.',
    'subscription_not_renewable' => 'This subscription cannot be renewed; subscribe again.',
    'downgrade_not_allowed' => 'Current usage exceeds the limits of the target plan.',

    'invoice_not_found' => 'This invoice does not exist.',
    'invoice_already_paid' => 'This invoice has already been paid.',
    'invoice_voided' => 'This invoice was voided; it cannot be paid.',
    'invoice_pdf_unavailable' => 'Invoice downloads are not available yet.',
    'invoice_line_plan' => ':plan — :period',

    'payment_not_found' => 'This payment does not exist.',
    'payment_already_pending' => 'A payment is already in progress for this invoice.',
    'payment_failed' => 'The payment was declined.',
    'payment_cancelled' => 'The payment was cancelled.',
    'payment_expired' => 'The payment request expired without an answer.',
    'payment_cancelled_by_payer' => 'You cancelled the payment.',
    'payment_unresolved' => 'The aggregator never confirmed the outcome of this payment.',
    'payment_instructions' => 'Approve the request on your phone, or dial your operator code.',

    'invalid_msisdn' => 'This phone number is invalid, or its operator is not recognised.',
    'provider_unavailable' => 'No payment method is available for :operator.',
    'provider_unknown' => 'The :provider aggregator is not configured.',
    'provider_rejected' => 'The aggregator rejected the request.',
    'all_providers_rejected' => 'No aggregator could process this request; nothing was charged.',
    'provider_fee' => ':provider fee',

    'webhook_signature_invalid' => 'Invalid callback signature.',
    'webhook_provider_unknown' => 'Unknown endpoint.',

    'billing_role_required' => 'Only owners and administrators can commit the organization.',

    'currency_not_supported' => 'The :currency currency is not supported.',
    'currency_mismatch' => 'Cannot combine amounts in different currencies.',

    'charge_description' => 'Sekuu — invoice :number',
    'credit_applied_to_invoice' => 'Credit applied to invoice :number',
    'payment_received' => 'Payment received via :provider',
    'proration_credit' => 'Proration credit — :plan',

];
