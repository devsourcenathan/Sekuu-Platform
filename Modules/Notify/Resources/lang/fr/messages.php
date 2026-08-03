<?php

declare(strict_types=1);

return [
    'template_read_only' => "Les templates de plateforme sont versionnes avec le code et ne sont pas modifiables par l'API. Creez une variante d'organisation.",
    'template_variant_exists' => 'Une variante existe deja pour cette cle et ce canal.',
    'template_category_reserved' => "Le transactionnel est reserve aux templates de plateforme : une organisation ne decide pas seule qu'un message ne peut plus etre refuse.",
    'suppression_not_found' => "Cette suppression n'existe pas.",
    'suppression_already_exists' => 'Cette destination est deja supprimee.',
    'channel_quota_reached' => 'Le quota mensuel du canal :channel prévu par votre plan est atteint.',
    'spend_limit_reached' => 'Le plafond de dépense mensuel du canal :channel est atteint.',
    'unsubscribe_token_invalid' => 'Ce lien de désabonnement est invalide.',
    'idempotency_key_required' => "L'en-tête Idempotency-Key est obligatoire sur ce point d'entrée.",
    'bulk_limit_exceeded' => 'Un envoi groupé porte au maximum :limit messages.',
    'notification_not_cancellable' => 'Ce message est déjà parti ; il ne peut plus être annulé.',
    'channel_not_available' => 'Le destinataire n\'a aucune coordonnée utilisable pour ce message.',
    'channel_not_configured' => 'Aucun fournisseur n\'est configuré pour le canal :channel.',
    'notification_not_found' => 'Cette notification n\'existe pas.',
    'recipient_invalid' => 'Le destinataire n\'est pas valide pour le canal :channel.',
    'recipient_opted_out' => 'Le destinataire a désactivé cette catégorie.',
    'recipient_suppressed' => 'Cette destination figure sur la liste de suppression.',
    'template_no_content' => 'Le template :key n\'a aucun contenu dans une langue utilisable.',
    'template_not_found' => 'Aucun template n\'est enregistré pour :key.',
    'template_variables_missing' => 'Variables obligatoires manquantes : :names',
    'transactional_locked' => 'Les messages transactionnels ne peuvent pas être désactivés.',
    'webhook_handler_missing' => 'Aucun gestionnaire de webhook n\'est enregistré pour :provider.',
    'webhook_signature_invalid' => 'La signature du webhook est invalide.',
];
