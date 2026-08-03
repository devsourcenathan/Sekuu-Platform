<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Templates de plateforme.
 *
 * Ils sont versionnés avec le code, comme les migrations, et non modifiables
 * via l'API : une organisation qui veut les habiller crée une variante portant
 * la même clé.
 *
 * @see docs/03-services/notify/02-data-model.md
 */
return new class extends Migration
{
    private const TEMPLATES = [
        [
            'key' => 'user.welcome',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'verification_url', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Bienvenue sur Sekuu',
                'body' => "<p>Bonjour {{ first_name }},</p><p>Votre compte Sekuu est créé. Il ne reste qu'à confirmer votre adresse :</p><p><a href=\"{{ verification_url }}\">Confirmer mon adresse</a></p>",
            ],
            'en' => [
                'subject' => 'Welcome to Sekuu',
                'body' => '<p>Hello {{ first_name }},</p><p>Your Sekuu account is ready. Please confirm your email address:</p><p><a href="{{ verification_url }}">Confirm my address</a></p>',
            ],
        ],
        [
            'key' => 'email.verification',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'verification_url', 'required' => true],
                ['name' => 'expires_in_hours', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Confirmez votre adresse email',
                'body' => '<p>Bonjour {{ first_name }},</p><p><a href="{{ verification_url }}">Confirmer mon adresse</a></p><p>Ce lien expire dans {{ expires_in_hours }} heures.</p>',
            ],
            'en' => [
                'subject' => 'Confirm your email address',
                'body' => '<p>Hello {{ first_name }},</p><p><a href="{{ verification_url }}">Confirm my address</a></p><p>This link expires in {{ expires_in_hours }} hours.</p>',
            ],
        ],
        [
            'key' => 'password.reset',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'reset_url', 'required' => true],
                ['name' => 'expires_in_hours', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Réinitialisation de votre mot de passe',
                'body' => "<p>Bonjour {{ first_name }},</p><p>Vous avez demandé à réinitialiser votre mot de passe :</p><p><a href=\"{{ reset_url }}\">Choisir un nouveau mot de passe</a></p><p>Ce lien expire dans {{ expires_in_hours }} heure(s). Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.</p>",
            ],
            'en' => [
                'subject' => 'Reset your password',
                'body' => '<p>Hello {{ first_name }},</p><p>You asked to reset your password:</p><p><a href="{{ reset_url }}">Choose a new password</a></p><p>This link expires in {{ expires_in_hours }} hour(s). If you did not request this, ignore this message — your password is unchanged.</p>',
            ],
        ],
        [
            'key' => 'password.changed',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'changed_at', 'required' => true],
                ['name' => 'ip_address', 'required' => false],
            ],
            'fr' => [
                'subject' => 'Votre mot de passe a été modifié',
                'body' => "<p>Bonjour {{ first_name }},</p><p>Votre mot de passe a été modifié le {{ changed_at }}.</p><p>Si vous n'êtes pas à l'origine de ce changement, votre compte est compromis : réinitialisez immédiatement votre mot de passe et contactez le support.</p>",
            ],
            'en' => [
                'subject' => 'Your password was changed',
                'body' => "<p>Hello {{ first_name }},</p><p>Your password was changed on {{ changed_at }}.</p><p>If this wasn't you, your account is compromised: reset your password immediately and contact support.</p>",
            ],
        ],
        [
            'key' => 'invitation.sent',
            'category' => 'operational',
            'variables' => [
                ['name' => 'organization_name', 'required' => true],
                ['name' => 'inviter_name', 'required' => false],
                ['name' => 'role', 'required' => true],
                ['name' => 'accept_url', 'required' => true],
                ['name' => 'expires_at', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Vous êtes invité à rejoindre {{ organization_name }}',
                'body' => "<p>Bonjour,</p><p>{{ inviter_name }} vous invite à rejoindre <strong>{{ organization_name }}</strong> en tant que {{ role }}.</p><p><a href=\"{{ accept_url }}\">Accepter l'invitation</a></p><p>Cette invitation expire le {{ expires_at }}.</p>",
            ],
            'en' => [
                'subject' => 'You have been invited to join {{ organization_name }}',
                'body' => '<p>Hello,</p><p>{{ inviter_name }} invites you to join <strong>{{ organization_name }}</strong> as {{ role }}.</p><p><a href="{{ accept_url }}">Accept the invitation</a></p><p>This invitation expires on {{ expires_at }}.</p>',
            ],
        ],
        [
            'key' => 'organization.created',
            'category' => 'operational',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'organization_name', 'required' => true],
            ],
            'fr' => [
                'subject' => '{{ organization_name }} a été créée',
                'body' => "<p>Bonjour {{ first_name }},</p><p>L'organisation <strong>{{ organization_name }}</strong> a bien été créée. Vous en êtes le propriétaire.</p>",
            ],
            'en' => [
                'subject' => '{{ organization_name }} has been created',
                'body' => '<p>Hello {{ first_name }},</p><p>The organization <strong>{{ organization_name }}</strong> has been created. You are its owner.</p>',
            ],
        ],
        [
            'key' => 'security.new_device',
            'category' => 'transactional',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'device_name', 'required' => false],
                ['name' => 'ip_address', 'required' => false],
                ['name' => 'occurred_at', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Nouvelle connexion à votre compte',
                'body' => "<p>Bonjour {{ first_name }},</p><p>Une connexion a été détectée le {{ occurred_at }} depuis {{ device_name }} ({{ ip_address }}).</p><p>Si ce n'était pas vous, réinitialisez votre mot de passe immédiatement.</p>",
            ],
            'en' => [
                'subject' => 'New sign-in to your account',
                'body' => "<p>Hello {{ first_name }},</p><p>A sign-in was detected on {{ occurred_at }} from {{ device_name }} ({{ ip_address }}).</p><p>If this wasn't you, reset your password immediately.</p>",
            ],
        ],
        [
            'key' => 'membership.removed',
            'category' => 'operational',
            'variables' => [
                ['name' => 'first_name', 'required' => true],
                ['name' => 'organization_name', 'required' => true],
            ],
            'fr' => [
                'subject' => 'Votre accès à {{ organization_name }} a pris fin',
                'body' => '<p>Bonjour {{ first_name }},</p><p>Vous ne faites plus partie de <strong>{{ organization_name }}</strong>.</p>',
            ],
            'en' => [
                'subject' => 'Your access to {{ organization_name }} has ended',
                'body' => '<p>Hello {{ first_name }},</p><p>You are no longer a member of <strong>{{ organization_name }}</strong>.</p>',
            ],
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
                'channel' => 'email',
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
                    'subject' => $template[$locale]['subject'],
                    'body' => $template[$locale]['body'],
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
            ->whereIn('key', array_column(self::TEMPLATES, 'key'))
            ->delete();
    }
};
