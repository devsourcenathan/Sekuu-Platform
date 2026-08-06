<?php

declare(strict_types=1);

namespace Modules\AI\Application\Generation;

/**
 * Des entrées nommées vers un prompt.
 *
 * ## Pourquoi l'assemblage vit ici et pas dans le produit
 *
 * Si chaque produit écrivait son prompt, la tâche `summarize` n'aurait plus de
 * sens : ce serait sept formulations différentes sous un même nom, avec sept
 * qualités de sortie, et la première à dériver le ferait en silence.
 *
 * Les instructions viennent de la tâche — donc du dépôt, donc d'une revue. Ce
 * que l'appelant fournit est du **contenu**, jamais de la consigne.
 */
final class ComposePrompt
{
    /**
     * @param  array<string, mixed>  $inputs
     */
    public function handle(array $inputs): string
    {
        // Une tâche libre passe le texte tel quel : c'est tout son objet.
        if (isset($inputs['prompt'])) {
            return (string) $inputs['prompt'];
        }

        $morceaux = [];

        foreach ($inputs as $champ => $valeur) {
            if ($champ === 'input' || $valeur === null || $valeur === []) {
                continue;
            }

            $morceaux[] = $this->label($champ).' : '.$this->render($valeur);
        }

        $morceaux[] = "\n".(string) ($inputs['input'] ?? '');

        return trim(implode("\n", $morceaux));
    }

    private function label(string $champ): string
    {
        return match ($champ) {
            'language' => 'Langue cible',
            'fields' => 'Champs à extraire',
            'labels' => 'Étiquettes possibles',
            default => ucfirst(str_replace('_', ' ', $champ)),
        };
    }

    /**
     * Les listes sont rendues à plat.
     *
     * Du JSON imbriqué dans un prompt consomme des jetons pour de la ponctuation
     * que le modèle n'exploite pas, et qu'il facture au même prix que du texte.
     */
    private function render(mixed $valeur): string
    {
        if (is_array($valeur)) {
            return implode(', ', array_map(
                fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
                $valeur,
            ));
        }

        return (string) $valeur;
    }
}
