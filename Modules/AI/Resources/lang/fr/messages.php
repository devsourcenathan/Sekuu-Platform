<?php

declare(strict_types=1);

return [

    'task_unknown' => "La tâche « :task » n'existe pas.",
    'model_unknown' => "Le modèle « :model » n'est pas au registre.",
    'driver_unknown' => 'Aucun pilote nommé « :driver ».',
    'task_out_of_scope' => 'Cette clé ne peut pas demander la tâche « :task ».',

    'no_base_url' => "Ce compte n'a pas d'URL de base.",
    'probe_needs_model' => "L'épreuve exige qu'un modèle soit déclaré sur le compte.",

    'driver_or_preset_required' => 'Un préréglage ou un pilote est requis.',
    'preset_unknown' => 'Aucun préréglage nommé « :preset ».',
    'preset_requires' => 'Le préréglage « :preset » exige le champ « :field ».',
    'slug_taken' => 'Un compte nommé « :slug » existe déjà.',
    'environment_mismatch' => 'Un compte déclaré « :declared » ne peut pas être posé depuis « :running ».',
    'rotation_rejected' => "La nouvelle clé n'a pas réussi l'épreuve. L'ancienne reste en service.",

    'account_not_found' => "Ce compte n'existe pas.",
    'account_forbidden' => "Ce compte n'est pas le vôtre.",
    'account_unverified' => "Le compte « :slug » n'a pas réussi l'épreuve.",
    'no_account_for_model' => 'Aucun compte ne sert le modèle « :model ».',
    'account_cap_reached' => 'Le plafond de ce compte est atteint.',

    'quota_exceeded' => "Les crédits d'IA de l'organisation sont épuisés.",
    'spend_cap_reached' => "La plateforme a atteint son plafond de dépense. Ce n'est pas votre faute.",
    'context_too_long' => "L'entrée dépasse ce que cette tâche accepte (:max jetons).",
    'provider_error' => "Aucun fournisseur n'a pu répondre.",
    'output_invalid' => "Le modèle n'a pas rendu la forme attendue, deux fois de suite.",
    'content_refused' => 'Le fournisseur a refusé cette demande.',

];
