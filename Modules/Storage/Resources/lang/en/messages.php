<?php

declare(strict_types=1);

return [

    'owner_type_unknown' => 'No module claims the type ":type".',
    'owner_type_out_of_scope' => 'This key cannot handle files of type ":type".',
    'attach_forbidden' => 'The owner of this object refuses this attachment.',
    'policy_incoherent' => 'The policy for ":type" declares a fallback without a primary destination.',

    'file_not_found' => 'This file does not exist.',
    'file_not_ready' => 'The bytes of this file have not been observed yet.',
    'file_retained' => 'This file must be retained until :date.',
    'upload_incomplete' => 'No object was found in the store. The upload did not complete.',
    'declared_does_not_match' => 'The observed bytes do not match what was declared.',

    'mime_not_allowed' => 'The type ":type" is not accepted for this object.',
    'size_required' => 'The declared size must be strictly positive.',
    'too_large_for_owner' => 'This file exceeds the size allowed for this object (:max bytes).',
    'too_large_for_destination' => 'This file exceeds what the destination ":destination" accepts (:max bytes).',
    'retention_too_long' => 'The requested retention exceeds this key ceiling (:max days).',
    'quota_exceeded' => 'The organization storage quota has been reached.',

    'destination_not_found' => 'This store does not exist.',
    'destination_forbidden' => 'This store is not yours.',
    'destination_unavailable' => 'The store ":slug" is not accepting writes right now.',
    'destination_and_fallback_unavailable' => 'Neither ":slug" nor its fallback ":fallback" accepts writes.',
    'destination_unreadable' => 'The store holding this file is not responding.',
    'no_default_destination' => 'No default store is configured for this environment.',
    'slug_taken' => 'A store named ":slug" already exists.',
    'environment_mismatch' => 'A store declared ":declared" cannot be registered from ":running".',
    'activate_unverified' => 'This store has never passed the probe: it cannot be activated.',
    'rotation_failed' => 'The new credentials were rejected; the previous ones have been restored.',
    'preset_unknown' => 'No preset named ":preset".',
    'preset_requires' => 'The preset ":preset" requires the field ":field".',
    'driver_or_preset_required' => 'A driver or a preset is required.',

];
