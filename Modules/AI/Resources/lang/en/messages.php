<?php

declare(strict_types=1);

return [

    'task_unknown' => 'The task ":task" does not exist.',
    'model_unknown' => 'The model ":model" is not in the registry.',
    'driver_unknown' => 'No driver named ":driver".',
    'task_out_of_scope' => 'This key cannot request the task ":task".',

    'no_base_url' => 'This account has no base URL.',
    'probe_needs_model' => 'The probe requires a model declared on the account.',

    'driver_or_preset_required' => 'A preset or a driver is required.',
    'preset_unknown' => 'No preset named ":preset".',
    'preset_requires' => 'The preset ":preset" requires the field ":field".',
    'slug_taken' => 'An account named ":slug" already exists.',
    'environment_mismatch' => 'An account declared ":declared" cannot be registered from ":running".',
    'rotation_rejected' => 'The new key failed the probe. The previous one stays in service.',

    'generation_not_found' => 'This generation does not exist.',
    'account_not_found' => 'This account does not exist.',
    'account_forbidden' => 'This account is not yours.',
    'account_unverified' => 'The account ":slug" has not passed the probe.',
    'no_account_for_model' => 'No account serves the model ":model".',
    'account_cap_reached' => 'This account spending cap has been reached.',

    'quota_exceeded' => 'The organization AI credits are exhausted.',
    'spend_cap_reached' => 'The platform reached its spending cap. This is not your fault.',
    'context_too_long' => 'The input exceeds what this task accepts (:max tokens).',
    'provider_error' => 'No provider was able to answer.',
    'output_invalid' => 'The model did not return the expected shape, twice in a row.',
    'content_refused' => 'The provider refused this request.',

];
