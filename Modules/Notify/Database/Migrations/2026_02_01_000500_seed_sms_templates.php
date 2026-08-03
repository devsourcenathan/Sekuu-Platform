<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Variantes SMS de messages existants.
 *
 * Une même clé porte désormais deux templates : l'alerte de connexion part par
 * email **et** par SMS, si l'on dispose d'un numéro. C'est le canal le plus sûr
 * pour prévenir d'une compromission, puisqu'il ne passe pas par la boîte mail
 * que l'attaquant contrôle peut-être déjà.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
return new class extends Migration
{
    private const TEMPLATES = [
        [
            'key' => 'security.new_device',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'occurred_at', 'required' => true],
            ],
            // Pas de mise en forme, pas de sujet, et court : un SMS est facturé
            // par tranche de 160 caractères.
            'fr' => 'Sekuu : nouvelle connexion a votre compte le {{ occurred_at }}. Si ce n\'etait pas vous, changez votre mot de passe immediatement.',
            'en' => 'Sekuu: new sign-in to your account on {{ occurred_at }}. If this wasn\'t you, change your password immediately.',
        ],
        [
            'key' => 'password.changed',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'changed_at', 'required' => true],
            ],
            'fr' => 'Sekuu : votre mot de passe a ete modifie le {{ changed_at }}. Si ce n\'etait pas vous, contactez le support.',
            'en' => 'Sekuu: your password was changed on {{ changed_at }}. If this wasn\'t you, contact support.',
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
                'channel' => 'sms',
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
                    'subject' => null,
                    'body' => $template[$locale],
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
            ->where('channel', 'sms')
            ->whereIn('key', array_column(self::TEMPLATES, 'key'))
            ->delete();
    }
};
