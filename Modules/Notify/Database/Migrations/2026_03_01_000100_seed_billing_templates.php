<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Messages de facturation.
 *
 * Billing publiait ces événements dans le vide : aucune correspondance, aucun
 * template. Un client voyait son accès se fermer sans avoir rien reçu.
 *
 * **Tous sont transactionnels**, et ce n'est pas un abus de la catégorie. Un
 * client qui aurait coupé les notifications de facturation n'aurait pas exercé
 * un choix : il aurait perdu l'information dont il a besoin pour agir. Sur un
 * modèle prépayé, où la plateforme ne peut pas prélever, prévenir est la seule
 * chose qu'elle puisse faire pour être payée.
 *
 * Trois d'entre eux existent aussi en SMS. C'est l'un des rares cas où le coût
 * du SMS se justifie sans discussion : l'échéance est le moment où le modèle
 * exige une **action** du client, et le rappel arrive là où le paiement se
 * fera — sur le téléphone qui recevra l'invite Mobile Money.
 *
 * @see docs/03-services/billing/04-events.md
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
return new class extends Migration
{
    private const COMMON = [
        ['name' => 'first_name', 'required' => true],
        ['name' => 'organization_name', 'required' => true],
    ];

    /**
     * Corps email volontairement sobres : pas d'image, pas de colonne, pas de
     * bouton dépendant d'un client mail. Un message de facturation doit rester
     * lisible partout, y compris dans un client texte.
     */
    private const TEMPLATES = [
        [
            'key' => 'subscription.activated',
            'channel' => 'email',
            'variables' => [['name' => 'plan_name', 'required' => true], ['name' => 'period_end', 'required' => true]],
            'fr' => ['Votre abonnement {{ plan_name }} est actif', "<p>Bonjour {{ first_name }},</p>\n<p>L'abonnement <strong>{{ plan_name }}</strong> de {{ organization_name }} est actif jusqu'au <strong>{{ period_end }}</strong>.</p>\n<p>Nous vous préviendrons avant cette date. Aucun prélèvement automatique n'est effectué : le renouvellement se fait depuis votre espace, quand vous le décidez.</p>"],
            'en' => ['Your {{ plan_name }} subscription is active', "<p>Hello {{ first_name }},</p>\n<p>The <strong>{{ plan_name }}</strong> subscription for {{ organization_name }} is active until <strong>{{ period_end }}</strong>.</p>\n<p>We will remind you before that date. Nothing is charged automatically: renewal happens from your dashboard, when you decide.</p>"],
        ],
        [
            'key' => 'subscription.expiring',
            'channel' => 'email',
            'variables' => [['name' => 'plan_name', 'required' => true], ['name' => 'days_remaining', 'required' => true], ['name' => 'expires_at', 'required' => true]],
            'fr' => ['Votre abonnement expire dans {{ days_remaining }} jour(s)', "<p>Bonjour {{ first_name }},</p>\n<p>L'abonnement <strong>{{ plan_name }}</strong> de {{ organization_name }} expire le <strong>{{ expires_at }}</strong>, dans {{ days_remaining }} jour(s).</p>\n<p>Renouvelez-le depuis votre espace pour éviter toute interruption. Le paiement se fait par Mobile Money : vous recevrez une invite à valider sur votre téléphone.</p>"],
            'en' => ['Your subscription expires in {{ days_remaining }} day(s)', "<p>Hello {{ first_name }},</p>\n<p>The <strong>{{ plan_name }}</strong> subscription for {{ organization_name }} expires on <strong>{{ expires_at }}</strong>, in {{ days_remaining }} day(s).</p>\n<p>Renew it from your dashboard to avoid any interruption. Payment is by Mobile Money: you will get a prompt to approve on your phone.</p>"],
        ],
        [
            'key' => 'subscription.grace',
            'channel' => 'email',
            'variables' => [['name' => 'plan_name', 'required' => true], ['name' => 'grace_days', 'required' => true], ['name' => 'grace_ends_at', 'required' => true]],
            'fr' => ['Votre abonnement a expiré — {{ grace_days }} jours pour le renouveler', "<p>Bonjour {{ first_name }},</p>\n<p>L'abonnement <strong>{{ plan_name }}</strong> de {{ organization_name }} a expiré.</p>\n<p><strong>Votre accès reste ouvert jusqu'au {{ grace_ends_at }}.</strong> Passé cette date, il sera suspendu — vos données seront conservées, mais vos équipes ne pourront plus travailler.</p>"],
            'en' => ['Your subscription expired — {{ grace_days }} days to renew', "<p>Hello {{ first_name }},</p>\n<p>The <strong>{{ plan_name }}</strong> subscription for {{ organization_name }} has expired.</p>\n<p><strong>Your access stays open until {{ grace_ends_at }}.</strong> After that it will be suspended — your data is kept, but your teams will not be able to work.</p>"],
        ],
        [
            'key' => 'subscription.suspended',
            'channel' => 'email',
            'variables' => [['name' => 'plan_name', 'required' => false]],
            'fr' => ['Accès suspendu — {{ organization_name }}', "<p>Bonjour {{ first_name }},</p>\n<p>Faute de renouvellement, l'accès de {{ organization_name }} est suspendu.</p>\n<p><strong>Vos données sont conservées.</strong> Un paiement rouvre l'accès immédiatement, sans rien perdre.</p>"],
            'en' => ['Access suspended — {{ organization_name }}', "<p>Hello {{ first_name }},</p>\n<p>Without renewal, access for {{ organization_name }} is suspended.</p>\n<p><strong>Your data is kept.</strong> A payment reopens access immediately, with nothing lost.</p>"],
        ],
        [
            'key' => 'invoice.issued',
            'channel' => 'email',
            'variables' => [['name' => 'invoice_number', 'required' => true], ['name' => 'amount', 'required' => true], ['name' => 'due_at', 'required' => false]],
            'fr' => ['Facture {{ invoice_number }} — {{ amount }}', "<p>Bonjour {{ first_name }},</p>\n<p>La facture <strong>{{ invoice_number }}</strong> de {{ amount }} a été émise pour {{ organization_name }}.</p>\n<p>Échéance : {{ due_at }}. Réglez-la depuis votre espace, par Mobile Money.</p>"],
            'en' => ['Invoice {{ invoice_number }} — {{ amount }}', "<p>Hello {{ first_name }},</p>\n<p>Invoice <strong>{{ invoice_number }}</strong> for {{ amount }} has been issued for {{ organization_name }}.</p>\n<p>Due: {{ due_at }}. Pay it from your dashboard, by Mobile Money.</p>"],
        ],
        [
            'key' => 'invoice.paid',
            'channel' => 'email',
            'variables' => [['name' => 'invoice_number', 'required' => true], ['name' => 'amount', 'required' => true], ['name' => 'paid_at', 'required' => true]],
            'fr' => ['Reçu — facture {{ invoice_number }}', "<p>Bonjour {{ first_name }},</p>\n<p>Nous avons bien reçu {{ amount }} pour la facture <strong>{{ invoice_number }}</strong>, le {{ paid_at }}.</p>\n<p>Merci. Ce message tient lieu de reçu.</p>"],
            'en' => ['Receipt — invoice {{ invoice_number }}', "<p>Hello {{ first_name }},</p>\n<p>We received {{ amount }} for invoice <strong>{{ invoice_number }}</strong> on {{ paid_at }}.</p>\n<p>Thank you. This message serves as your receipt.</p>"],
        ],
        [
            'key' => 'payment.failed',
            'channel' => 'email',
            'variables' => [['name' => 'amount', 'required' => true], ['name' => 'reason', 'required' => false]],
            'fr' => ['Votre paiement de {{ amount }} n\'a pas abouti', "<p>Bonjour {{ first_name }},</p>\n<p>Le paiement de {{ amount }} pour {{ organization_name }} n'a pas abouti.</p>\n<p>Vérifiez le solde de votre compte Mobile Money, puis relancez le paiement depuis votre espace. Rien n'a été débité.</p>"],
            'en' => ['Your {{ amount }} payment did not go through', "<p>Hello {{ first_name }},</p>\n<p>The {{ amount }} payment for {{ organization_name }} did not go through.</p>\n<p>Check your Mobile Money balance, then start the payment again from your dashboard. Nothing was charged.</p>"],
        ],

        // --- SMS : uniquement là où une action est attendue ----------------
        // Pas de mise en forme, pas de sujet, et court : un SMS est facturé par
        // tranche de 160 caractères.
        [
            'key' => 'subscription.expiring',
            'channel' => 'sms',
            'variables' => [['name' => 'days_remaining', 'required' => true], ['name' => 'expires_at', 'required' => true]],
            'fr' => [null, 'Sekuu : votre abonnement expire le {{ expires_at }}. Renouvelez-le pour eviter toute interruption.'],
            'en' => [null, 'Sekuu: your subscription expires on {{ expires_at }}. Renew it to avoid any interruption.'],
        ],
        [
            'key' => 'subscription.grace',
            'channel' => 'sms',
            'variables' => [['name' => 'grace_ends_at', 'required' => true]],
            'fr' => [null, 'Sekuu : votre abonnement a expire. Acces maintenu jusqu\'au {{ grace_ends_at }}, puis suspendu. Renouvelez maintenant.'],
            'en' => [null, 'Sekuu: your subscription expired. Access stays open until {{ grace_ends_at }}, then suspended. Renew now.'],
        ],
        [
            'key' => 'payment.failed',
            'channel' => 'sms',
            'variables' => [['name' => 'amount', 'required' => true]],
            'fr' => [null, 'Sekuu : votre paiement de {{ amount }} n\'a pas abouti. Rien n\'a ete debite. Reessayez depuis votre espace.'],
            'en' => [null, 'Sekuu: your {{ amount }} payment did not go through. Nothing was charged. Try again from your dashboard.'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::TEMPLATES as $template) {
            $exists = DB::table('notification_templates')
                ->where('key', $template['key'])
                ->where('channel', $template['channel'])
                ->whereNull('organization_id')
                ->exists();

            if ($exists) {
                continue;
            }

            $templateId = (string) Str::uuid();

            DB::table('notification_templates')->insert([
                'id' => $templateId,
                'key' => $template['key'],
                'channel' => $template['channel'],
                // Jamais désactivable : couper un rappel d'échéance reviendrait
                // à laisser un client perdre son accès sans le savoir.
                'category' => 'transactional',
                'organization_id' => null,
                'variables' => json_encode(array_merge(self::COMMON, $template['variables'])),
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
                    'subject' => $template[$locale][0],
                    'body' => $template[$locale][1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $keys = array_unique(array_column(self::TEMPLATES, 'key'));

        DB::table('notification_templates')
            ->whereIn('key', $keys)
            ->whereNull('organization_id')
            ->delete();
    }
};
