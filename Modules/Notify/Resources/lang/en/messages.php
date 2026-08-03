<?php

declare(strict_types=1);

return [
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
