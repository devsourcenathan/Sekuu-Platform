<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

/**
 * Les guidelines imposent que toute API supporte `Accept-Language` et que les
 * messages soient traduisibles, le code d'erreur restant la seule référence
 * stable.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class LocalisationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_language_is_english(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@sekuu.com', 'password' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error.message', 'The provided credentials are incorrect.');
    }

    public function test_a_french_request_gets_a_french_message(): void
    {
        $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'inconnu@sekuu.com', 'password' => 'x'],
            ['Accept-Language' => 'fr'],
        )
            ->assertStatus(401)
            ->assertJsonPath('error.message', 'Les identifiants fournis sont incorrects.');
    }

    /**
     * Le code est la référence stable : il ne doit **jamais** dépendre de la
     * langue, sinon la logique cliente casserait au premier changement de
     * locale.
     */
    public function test_the_error_code_never_changes_with_the_language(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $this->postJson(
                '/api/v1/auth/login',
                ['email' => 'inconnu@sekuu.com', 'password' => 'x'],
                ['Accept-Language' => $locale],
            )->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        }
    }

    public function test_the_response_declares_the_language_it_used(): void
    {
        $response = $this->getJson('/api/v1/health', ['Accept-Language' => 'fr']);

        $response->assertOk();
        $this->assertSame('fr', $response->headers->get('Content-Language'));

        // Sans `Vary`, un cache intermédiaire servirait la mauvaise langue.
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
    }

    /**
     * La région ne change pas la traduction : `fr-CA` doit être compris.
     */
    public function test_a_regional_variant_falls_back_to_its_language(): void
    {
        $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'inconnu@sekuu.com', 'password' => 'x'],
            ['Accept-Language' => 'fr-CA'],
        )->assertJsonPath('error.message', 'Les identifiants fournis sont incorrects.');
    }

    public function test_the_quality_order_is_respected(): void
    {
        // L'allemand n'est pas supporté : le français, de qualité inférieure,
        // doit l'emporter.
        $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'inconnu@sekuu.com', 'password' => 'x'],
            ['Accept-Language' => 'de;q=1.0, fr;q=0.8'],
        )->assertJsonPath('error.message', 'Les identifiants fournis sont incorrects.');
    }

    /**
     * Mieux vaut répondre dans la langue par défaut qu'exposer une clé de
     * traduction brute.
     */
    public function test_an_unsupported_language_falls_back_to_the_default(): void
    {
        $this->postJson(
            '/api/v1/auth/login',
            ['email' => 'inconnu@sekuu.com', 'password' => 'x'],
            ['Accept-Language' => 'de-DE'],
        )->assertJsonPath('error.message', 'The provided credentials are incorrect.');
    }

    /**
     * Un navigateur envoie sa propre langue sans que l'utilisateur l'ait
     * demandé ; le profil, lui, est un choix explicite.
     */
    public function test_the_user_preference_wins_over_the_browser_header(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
            'language' => 'fr',
        ])->assertCreated()->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/workspaces', ['Accept-Language' => 'en'])
            ->assertStatus(403)
            ->assertJsonPath('error.message', "Sélectionnez une organisation active avant d'appeler ce point d'entrée.");
    }

    public function test_a_user_without_preference_follows_the_header(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nathan',
            'last_name' => 'Tchinda',
            'email' => 'nathan@sekuu.com',
            'password' => 'un-mot-de-passe-long',
            'language' => 'en',
        ])->assertCreated()->json('data.access_token');

        User::query()->where('email', 'nathan@sekuu.com')->update(['language' => 'en']);

        $this->withToken($token)
            ->getJson('/api/v1/workspaces', ['Accept-Language' => 'en'])
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'Select an active organization before calling this endpoint.');
    }

    /**
     * Une clé absente d'une langue afficherait la clé brute au client. Le test
     * compare les jeux de clés plutôt que d'attendre qu'un utilisateur le
     * découvre.
     */
    public function test_every_key_exists_in_every_supported_language(): void
    {
        $missing = [];

        foreach ($this->translationFiles() as $label => $paths) {
            $reference = require $paths['en'];

            foreach ($paths as $locale => $path) {
                $keys = array_keys(require $path);

                foreach (array_diff(array_keys($reference), $keys) as $key) {
                    $missing[] = "{$label} [{$locale}] : {$key}";
                }

                foreach (array_diff($keys, array_keys($reference)) as $key) {
                    $missing[] = "{$label} [{$locale}] : {$key} (absent de en)";
                }
            }
        }

        $this->assertSame([], $missing, "Traductions incomplètes :\n".implode("\n", $missing));
    }

    /**
     * **Toute clé citée dans le code doit exister.**
     *
     * ## Le défaut que ce test attrape, et qui existait vraiment
     *
     * Le test précédent compare les langues entre elles : deux fichiers
     * cohérents mais tous deux incomplets le satisfont. Deux clés d'AI —
     * `already_started` et `activate_unverified` — étaient citées par un
     * contrôleur et absentes des deux langues.
     *
     * Rien ne l'a signalé, parce que Laravel rend la clé brute quand la
     * traduction manque, et que le test d'API n'assertait que le code d'erreur.
     * Un client aurait reçu `ai::messages.already_started` en guise de phrase.
     */
    public function test_every_key_the_code_cites_actually_exists(): void
    {
        $missing = [];

        foreach (File::allFiles(base_path('Modules')) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Tests')) {
                continue;
            }

            preg_match_all("/__\('([a-z]+)::messages\.([a-z0-9_]+)'/", $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as [, $namespace, $key]) {
                if (! Lang::has("{$namespace}::messages.{$key}", 'fr')) {
                    $missing[] = $file->getRelativePathname()." : {$namespace}::messages.{$key}";
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            'Clés citées par le code mais absentes des traductions :
'.implode('
', array_unique($missing)),
        );
    }

    /**
     * Un `__()` recevant une phrase entière plutôt qu'une clé fonctionne par
     * accident : il renvoie sa propre entrée tant que rien ne la traduit.
     */
    public function test_no_literal_sentence_is_passed_to_the_translator(): void
    {
        $offenders = [];

        foreach (['app', 'Modules'] as $base) {
            foreach (File::allFiles(base_path($base)) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Tests')) {
                    continue;
                }

                preg_match_all("/__\('([^']*)'/", $file->getContents(), $matches);

                foreach ($matches[1] as $argument) {
                    // Une clé ne contient ni espace ni majuscule initiale.
                    if (str_contains($argument, ' ') || preg_match('/^[A-Z]/', $argument)) {
                        $offenders[] = $file->getRelativePathname().' : '.$argument;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Chaînes littérales non extraites :\n".implode("\n", $offenders));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function translationFiles(): array
    {
        return [
            'platform' => [
                'en' => base_path('lang/en/platform.php'),
                'fr' => base_path('lang/fr/platform.php'),
            ],
            'identity' => [
                'en' => base_path('Modules/Identity/Resources/lang/en/messages.php'),
                'fr' => base_path('Modules/Identity/Resources/lang/fr/messages.php'),
            ],
            'notify' => [
                'en' => base_path('Modules/Notify/Resources/lang/en/messages.php'),
                'fr' => base_path('Modules/Notify/Resources/lang/fr/messages.php'),
            ],
        ];
    }
}
