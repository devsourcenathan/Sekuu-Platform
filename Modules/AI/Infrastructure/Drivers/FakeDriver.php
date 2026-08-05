<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Modules\AI\Domain\Models\AiAccount;
use Throwable;

/**
 * Le fournisseur des tests.
 *
 * ## Pourquoi il existe
 *
 * Il permet d'éprouver toute la chaîne — résolution de compte, quota, plafond,
 * bascule, registre de dépense — **sans clé et sans réseau**.
 *
 * C'est le pendant du pilote `local` de Storage, et il vient du même
 * enseignement : le pilote S3 avait été écrit, documenté et recommandé sans
 * jamais être instancié, faute d'un chemin exécutable hors ligne. Son adaptateur
 * manquait, et 561 tests ne l'ont pas vu.
 *
 * Les deux vrais pilotes sont éprouvés séparément, contre `Http::fake` : en-têtes,
 * format des messages, lecture des jetons, classification des erreurs.
 */
final class FakeDriver implements AiDriver
{
    /** Ce que la prochaine génération rendra. */
    public static string $output = 'réponse factice';

    /** Jetons rapportés. */
    public static int $inputTokens = 100;

    public static int $outputTokens = 50;

    /** Une panne à simuler, le cas échéant. */
    public static ?Throwable $failure = null;

    /** Comptes des appels, pour vérifier qu'une bascule a bien eu lieu. */
    public static int $calls = 0;

    /** @var list<string> */
    public static array $seenModels = [];

    public static function reset(): void
    {
        self::$output = 'réponse factice';
        self::$inputTokens = 100;
        self::$outputTokens = 50;
        self::$failure = null;
        self::$calls = 0;
        self::$seenModels = [];
    }

    /**
     * La panne suivante, une seule fois.
     *
     * Permet d'écrire « le premier compte refuse, le second répond » sans
     * enchaîner des conditions dans le test.
     */
    public static function failOnce(string $code, int $status = 503): void
    {
        self::$failure = new DomainException($code, 'panne simulée', $status);
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(json: true, tools: true, history: true);
    }

    public function serves(AiAccount $account, string $model): bool
    {
        $autorises = $account->models;

        return $autorises === null || $autorises === [] || in_array($model, $autorises, true);
    }

    public function generate(AiAccount $account, GenerationRequest $request): GenerationResult
    {
        self::$calls++;
        self::$seenModels[] = $request->model;

        if (self::$failure !== null) {
            $panne = self::$failure;
            self::$failure = null;

            throw $panne;
        }

        return new GenerationResult(
            output: self::$output,
            inputTokens: self::$inputTokens,
            outputTokens: self::$outputTokens,
            latencyMs: 12,
        );
    }

    public function probe(AiAccount $account): void
    {
        if (self::$failure !== null) {
            $panne = self::$failure;
            self::$failure = null;

            throw $panne;
        }
    }
}
