<?php

declare(strict_types=1);

return [
    'template_read_only' => 'Platform templates are versioned with the code and cannot be edited through the API. Create an organization variant instead.',
    'template_variant_exists' => 'A variant already exists for this key and channel.',
    'template_category_reserved' => 'Transactional messages are reserved to platform templates: an organization cannot decide alone that a message may no longer be declined.',
    'suppression_not_found' => 'This suppression does not exist.',
    'suppression_already_exists' => 'This destination is already suppressed.',
    'channel_quota_reached' => 'The monthly :channel quota included in your plan has been reached.',
    'spend_limit_reached' => 'The monthly spending limit for the :channel channel has been reached.',
    'unsubscribe_token_invalid' => 'This unsubscribe link is invalid.',
    'idempotency_key_required' => 'The Idempotency-Key header is required for this endpoint.',
    'bulk_limit_exceeded' => 'A bulk request carries at most :limit messages.',
    'notification_not_cancellable' => 'This message has already left; it can no longer be cancelled.',
    'channel_not_available' => 'The recipient has no usable address for this message.',
    'channel_not_configured' => 'No provider is configured for the :channel channel.',
    'notification_not_found' => 'This notification does not exist.',
    'recipient_invalid' => 'The recipient is not valid for the :channel channel.',
    'recipient_opted_out' => 'The recipient has disabled this category.',
    'recipient_suppressed' => 'This destination is on the suppression list.',
    'template_no_content' => 'The template :key has no content in a usable language.',
    'template_not_found' => 'No template is registered for :key.',
    'template_variables_missing' => 'Missing required variables: :names',
    'transactional_locked' => 'Transactional messages cannot be disabled.',
    'webhook_handler_missing' => 'No webhook handler is registered for :provider.',
    'webhook_signature_invalid' => 'The webhook signature is invalid.',
];
