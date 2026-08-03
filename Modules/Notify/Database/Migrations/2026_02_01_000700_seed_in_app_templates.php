<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Variantes internes de messages existants.
 *
 * Le canal interne ne dépend d'aucun fournisseur : il reste consultable même
 * si l'adresse du destinataire rebondit ou si son opérateur est injoignable.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
return new class extends Migration
{
    private const TEMPLATES = [
        [
            'key' => 'organization.created',
            'category' => 'operational',
            'variables' => [
                ['name' => 'organization_name', 'required' => true],
            ],
            'fr' => ['sujet' => 'Organisation créée', 'corps' => '{{ organization_name }} a été créée. Vous en êtes le propriétaire.'],
            'en' => ['sujet' => 'Organization created', 'corps' => '{{ organization_name }} has been created. You are its owner.'],
        ],
        [
            'key' => 'security.new_device',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'occurred_at', 'required' => true],
            ],
            'fr' => ['sujet' => 'Nouvelle connexion', 'corps' => 'Une connexion a été détectée le {{ occurred_at }}. Si ce n\'était pas vous, changez votre mot de passe.'],
            'en' => ['sujet' => 'New sign-in', 'corps' => 'A sign-in was detected on {{ occurred_at }}. If this wasn\'t you, change your password.'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::TEMPLATES as $template) {
            $templateId = (string) Str::uuid();

            DB::table('notification_templates')->insert([
                'id' => $templateId,
                'key' => $template['key'],
                'channel' => 'in_app',
                'category' => $template['category'],
                'organization_id' => null,
                'variables' => json_encode($template['variables']),
                'status' => 'active',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['fr', 'en'] as $locale) {
                DB::table('notification_template_contents')->insert([
                    'id' => (string) Str::uuid(),
                    'template_id' => $templateId,
                    'locale' => $locale,
                    'subject' => $template[$locale]['sujet'],
                    'body' => $template[$locale]['corps'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->whereNull('organization_id')
            ->where('channel', 'in_app')
            ->whereIn('key', array_column(self::TEMPLATES, 'key'))
            ->delete();
    }
};
