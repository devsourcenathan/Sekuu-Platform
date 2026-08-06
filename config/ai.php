<?php

declare(strict_types=1);

use Modules\AI\Infrastructure\Drivers\AnthropicDriver;
use Modules\AI\Infrastructure\Drivers\FakeDriver;
use Modules\AI\Infrastructure\Drivers\OpenAiDriver;

/*
| Configuration du module AI.
|
| Les **comptes** ne sont pas ici : ce sont des lignes de `ai_accounts`. Ce
| fichier ne décrit que ce qui est du code — les pilotes, les modèles, les
| tâches — et ce qui n'a de sens qu'au déploiement.
|
| @see docs/03-services/ai/05-providers.md
| @see docs/04-decisions/adr-0015-ai-task-not-model.md
| @see docs/04-decisions/adr-0017-ai-accounts.md
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Pilotes
    |--------------------------------------------------------------------------
    |
    | Un pilote est un **protocole**, pas une entreprise. `openai` sert OpenAI,
    | Google, DeepSeek, Mistral, Groq, Together et les serveurs locaux : ils
    | parlent le même protocole et n'en diffèrent que par une URL de base.
    |
    | Anthropic a le sien — `x-api-key`, `anthropic-version`, format de messages
    | distinct — donc son propre pilote.
    |
    */

    'drivers' => [
        'openai' => OpenAiDriver::class,
        'anthropic' => AnthropicDriver::class,
        'fake' => FakeDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Préréglages
    |--------------------------------------------------------------------------
    |
    | **De la donnée.** Ajouter Cerebras, Perplexity ou un serveur local demande
    | une entrée ici, et rien d'autre.
    |
    | Un préréglage n'est pas une caution : `openrouter` y figure parce que son
    | protocole est compatible, pas parce qu'il satisfait l'exigence de
    | non-entraînement de l'ADR-0016. Sur un compte de la plateforme il est
    | écarté ; sur celui d'un client, c'est sa responsabilité.
    |
    */

    'presets' => [
        'openai' => ['driver' => 'openai', 'base_url' => 'https://api.openai.com/v1'],
        'gemini' => ['driver' => 'openai', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
        'deepseek' => ['driver' => 'openai', 'base_url' => 'https://api.deepseek.com/v1'],
        'mistral' => ['driver' => 'openai', 'base_url' => 'https://api.mistral.ai/v1'],
        'xai' => ['driver' => 'openai', 'base_url' => 'https://api.x.ai/v1'],
        'groq' => ['driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1'],
        'together' => ['driver' => 'openai', 'base_url' => 'https://api.together.xyz/v1'],
        'fireworks' => ['driver' => 'openai', 'base_url' => 'https://api.fireworks.ai/inference/v1'],
        'deepinfra' => ['driver' => 'openai', 'base_url' => 'https://api.deepinfra.com/v1/openai'],
        'openrouter' => ['driver' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1'],

        // Azure : même protocole, autre en-tête d'authentification.
        'azure' => ['driver' => 'openai', 'auth' => 'api-key', 'requires' => ['base_url']],

        // Local : pas de clé, pas de prix public.
        'ollama' => ['driver' => 'openai', 'base_url' => 'http://localhost:11434/v1'],
        'vllm' => ['driver' => 'openai', 'requires' => ['base_url']],

        'anthropic' => ['driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modèles
    |--------------------------------------------------------------------------
    |
    | Prix **par million de jetons**, indicatifs et versionnés avec le code : ils
    | bougent, souvent à la baisse, et une revue ne devrait pas être nécessaire
    | pour en profiter. Le prix **appliqué** est figé sur la ligne de la
    | génération — un tarif révisé ne réécrit jamais l'historique.
    |
    | `status` porte le cycle de vie : `preferred`, `deprecated` (journalisé),
    | `retired` (refusé, la tâche passe à son repli). C'est là que se paie la
    | dette de dépréciation que l'ADR-0015 nous a fait assumer.
    |
    */

    'models' => [

        'claude-sonnet-4-6' => [
            'family' => 'anthropic',
            'context' => 200_000,
            'capabilities' => ['json', 'tools', 'vision'],
            'price_in' => 3.00,
            'price_out' => 15.00,
        ],

        'claude-haiku-4-5' => [
            'family' => 'anthropic',
            'context' => 200_000,
            'capabilities' => ['json', 'tools', 'vision'],
            'price_in' => 1.00,
            'price_out' => 5.00,
        ],

        'deepseek-chat' => [
            'family' => 'openai',
            'context' => 128_000,
            'capabilities' => ['json', 'tools'],
            'price_in' => 0.28,
            'price_out' => 0.42,
        ],

        'gemini-2.5-flash' => [
            'family' => 'openai',
            'context' => 1_000_000,
            'capabilities' => ['json', 'tools', 'vision'],
            'price_in' => 0.30,
            'price_out' => 2.50,
        ],

        'mistral-large' => [
            'family' => 'openai',
            'context' => 128_000,
            'capabilities' => ['json', 'tools'],
            'price_in' => 2.00,
            'price_out' => 6.00,
        ],

        // Sans prix public : le coût dépend de l'hébergeur, pas du modèle.
        // `cost_micros` restera `null` — distinct de zéro.
        'llama-3.3-70b' => [
            'family' => 'openai',
            'context' => 128_000,
            'capabilities' => ['json'],
            'price_in' => null,
            'price_out' => null,
        ],

        // Le modèle des tests. Gratuit parce qu'il n'appelle rien.
        'fake-model' => [
            'family' => 'fake',
            'context' => 100_000,
            'capabilities' => ['json', 'tools'],
            'price_in' => 1.00,
            'price_out' => 2.00,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tâches
    |--------------------------------------------------------------------------
    |
    | **Le seul vocabulaire qu'un appelant connaisse.** Il n'existe aucun champ
    | `model` dans l'API : seule la plateforme nomme le modèle.
    |
    | `requires` déclare les capacités **nécessaires**, et un test vérifie que
    | chaque modèle d'une chaîne les satisfait. Un repli sans `json` sur une
    | tâche qui en exige produirait une sortie invalide sur le chemin le moins
    | testé — celui qui ne sert que quand le premier modèle est déjà tombé.
    |
    */

    'tasks' => [

        'summarize' => [
            'model' => 'claude-haiku-4-5',
            'fallback' => 'deepseek-chat',
            'max_input_tokens' => 100_000,
            'max_output_tokens' => 1_000,
            'temperature' => 0.2,
            'output' => 'text',
            'synchronous' => true,
            'inputs' => ['input' => 'required|string', 'language' => 'nullable|string|size:2'],
            'instructions' => 'Résume le texte fourni, fidèlement et sans ajouter d\'information.',
        ],

        'translate' => [
            'model' => 'claude-haiku-4-5',
            'fallback' => 'gemini-2.5-flash',
            'max_input_tokens' => 100_000,
            'max_output_tokens' => 8_000,
            'temperature' => 0.1,
            'output' => 'text',
            'synchronous' => true,
            'inputs' => ['input' => 'required|string', 'language' => 'required|string|size:2'],
            'instructions' => 'Traduis le texte en préservant sa mise en forme et son registre.',
        ],

        /*
        | `temperature: 0` n'est pas un réglage de confort : une extraction qui
        | varie d'un appel à l'autre est inexploitable, et un produit qui la
        | rapprocherait de sa base attribuerait les écarts à ses données.
        |
        | `fallback: null` est un choix. Une extraction réussie par un modèle et
        | ratée par un autre produirait deux formes de sortie ; mieux vaut
        | échouer franchement.
        */
        'extract' => [
            'model' => 'claude-sonnet-4-6',
            'fallback' => null,
            'max_input_tokens' => 100_000,
            'max_output_tokens' => 4_000,
            'temperature' => 0.0,
            'output' => 'json',
            'requires' => ['json'],
            'synchronous' => false,
            'inputs' => ['input' => 'required|string', 'fields' => 'required|array'],
            'instructions' => 'Extrais les champs demandés. Rends un objet JSON, sans texte autour.',
        ],

        'classify' => [
            'model' => 'claude-haiku-4-5',
            'fallback' => 'deepseek-chat',
            'max_input_tokens' => 32_000,
            'max_output_tokens' => 200,
            'temperature' => 0.0,
            'output' => 'json',
            'requires' => ['json'],
            'synchronous' => true,
            'inputs' => ['input' => 'required|string', 'labels' => 'required|array'],
            'instructions' => 'Range l\'entrée dans une des étiquettes proposées, et une seule.',
        ],

        /*
        | Les tâches **libres**.
        |
        | Elles ne contredisent pas l'ADR-0015 : ce qui y est refusé est que
        | l'appelant nomme le modèle, pas qu'il écrive librement. La plateforme
        | choisit toujours, les bornes tiennent le coût, le quota et le registre
        | s'appliquent.
        |
        | Ce qu'elles perdent est réel : aucun format de sortie n'est promis,
        | donc aucune validation.
        */
        'prompt' => [
            'model' => 'claude-sonnet-4-6',
            'fallback' => 'deepseek-chat',
            'max_input_tokens' => 32_000,
            'max_output_tokens' => 4_000,
            'temperature' => 0.7,
            'output' => 'text',
            'synchronous' => false,
            'accepts_history' => true,
            'inputs' => ['prompt' => 'required|string'],
        ],

        'prompt-fast' => [
            'model' => 'gemini-2.5-flash',
            'fallback' => 'deepseek-chat',
            'max_input_tokens' => 32_000,
            'max_output_tokens' => 1_000,
            'temperature' => 0.7,
            'output' => 'text',
            'synchronous' => true,
            'accepts_history' => true,
            'inputs' => ['prompt' => 'required|string'],
        ],

        'prompt-deep' => [
            'model' => 'claude-sonnet-4-6',
            'fallback' => null,
            'max_input_tokens' => 100_000,
            'max_output_tokens' => 16_000,
            'temperature' => 0.7,
            'output' => 'text',
            'synchronous' => false,
            'accepts_history' => true,
            'inputs' => ['prompt' => 'required|string'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Dépense
    |--------------------------------------------------------------------------
    |
    | Le plafond **absolu** de la plateforme, indépendant de tout plan. C'est le
    | garde-fou contre une boucle ou une clé fuitée : sans lui, une organisation
    | au plan illimité n'aurait aucune borne.
    |
    | Il ne remplace pas le quota du plan, et le quota ne le remplace pas — même
    | coexistence que `ChannelQuota` et `SpendGuard` côté Notify.
    |
    | ## L'unité est le millionième de **dollar**
    |
    | Les fournisseurs publient leurs tarifs en dollars par million de jetons, et
    | c'est en dollars qu'ils facturent. Convertir en francs à l'écriture
    | figerait un taux de change dans un registre scellé, et le total d'un mois
    | mêlerait des taux différents selon le jour de l'appel.
    |
    | La conversion en XAF appartiendra à Billing le jour où une facturation à
    | l'usage sera décidée — avec un taux daté, qui est une décision commerciale.
    |
    | 100 000 000 = 100 $ par mois, toutes organisations confondues.
    |
    */

    'spend_cap_micros' => (int) env('AI_SPEND_CAP_MICROS', 100_000_000),

    /*
    |--------------------------------------------------------------------------
    | Durées
    |--------------------------------------------------------------------------
    */

    'request_timeout' => 120,
    'sync_deadline' => 25,
    'probe_daily' => true,

    /*
    | Combien de temps une sortie **non conservée** reste lisible.
    |
    | Assez pour qu'un sondage la trouve et qu'une clé d'idempotence rejouée la
    | rende ; pas assez pour constituer un registre. Elle est de toute façon
    | effacée à la première lecture.
    */
    'output_window_hours' => 24,

    /*
    | Délai d'une livraison sortante. Court : un produit lent ne doit pas
    | occuper un travailleur, et la file réessaiera.
    */
    'delivery_timeout' => 10,

    /*
    | Au-delà, une génération encore `queued` ou `running` est considérée comme
    | abandonnée : aucun travailleur ne la reprendra.
    |
    | Largement au-dessus du délai de requête (120 s) et de ce qu'une file
    | chargée peut faire attendre. Trop court conclurait des générations en
    | cours ; trop long laisse un appelant sonder dans le vide.
    */
    'abandoned_after_minutes' => 60,

];
