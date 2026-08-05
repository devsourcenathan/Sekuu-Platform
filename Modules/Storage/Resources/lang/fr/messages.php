<?php

declare(strict_types=1);

return [

    'owner_type_unknown' => 'Aucun module ne revendique le type « :type ».',
    'owner_type_out_of_scope' => 'Cette clé ne peut pas manipuler des fichiers de type « :type ».',
    'attach_forbidden' => 'Le propriétaire de cet objet refuse ce rattachement.',
    'policy_incoherent' => 'La politique de « :type » déclare un repli sans destination principale.',

    'file_not_found' => "Ce fichier n'existe pas.",
    'file_not_ready' => "Les octets de ce fichier n'ont pas encore été constatés.",
    'file_retained' => "Ce fichier doit être conservé jusqu'au :date.",
    'upload_incomplete' => "Aucun objet n'a été trouvé dans le magasin. Le téléversement n'a pas abouti.",
    'declared_does_not_match' => 'Les octets constatés ne correspondent pas à ce qui avait été déclaré.',

    'mime_not_allowed' => "Le type « :type » n'est pas accepté pour cet objet.",
    'size_required' => 'La taille annoncée doit être strictement positive.',
    'too_large_for_owner' => 'Ce fichier dépasse la taille autorisée pour cet objet (:max octets).',
    'too_large_for_destination' => 'Ce fichier dépasse ce que la destination « :destination » accepte (:max octets).',
    'retention_too_long' => 'La rétention demandée dépasse le plafond de cette clé (:max jours).',
    'quota_exceeded' => "Le quota de stockage de l'organisation est atteint.",

    'destination_not_found' => "Ce magasin n'existe pas.",
    'destination_forbidden' => "Ce magasin n'est pas le vôtre.",
    'destination_unavailable' => "Le magasin « :slug » n'accepte pas d'écriture pour le moment.",
    'destination_and_fallback_unavailable' => "Ni « :slug » ni son repli « :fallback » n'acceptent d'écriture.",
    'destination_unreadable' => 'Le magasin qui porte ce fichier ne répond pas.',
    'no_default_destination' => "Aucun magasin par défaut n'est configuré pour cet environnement.",
    'slug_taken' => 'Un magasin nommé « :slug » existe déjà.',
    'environment_mismatch' => 'Un magasin déclaré « :declared » ne peut pas être enregistré depuis « :running ».',
    'activate_unverified' => "Ce magasin n'a jamais réussi l'épreuve : il ne peut pas être activé.",
    'rotation_failed' => 'Les nouveaux identifiants ont été refusés ; les précédents ont été rétablis.',
    'preset_unknown' => 'Aucun préréglage nommé « :preset ».',
    'preset_requires' => 'Le préréglage « :preset » exige le champ « :field ».',
    'driver_or_preset_required' => 'Un pilote ou un préréglage est requis.',

];
