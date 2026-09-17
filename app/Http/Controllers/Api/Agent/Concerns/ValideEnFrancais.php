<?php

namespace App\Http\Controllers\Api\Agent\Concerns;

use Illuminate\Http\Request;

/**
 * Validation des requêtes d'agent, avec des messages en français.
 *
 * Le message d'une erreur de saisie s'affiche sur le terminal, devant l'agent :
 * l'application est configurée en anglais, et « The montant field is
 * required. » n'a rien à faire au comptoir.
 */
trait ValideEnFrancais
{
    /**
     * Nommée `validerSaisie` et non `valider` : RetraitsController a déjà une
     * action `valider` (validation d'un retrait), et en PHP la méthode d'une
     * classe remplace EN SILENCE celle d'un trait du même nom — chaque appel
     * aboutissait alors dans l'action, avec les mauvais arguments.
     *
     * @param  array<string, mixed> $regles
     * @return array<string, mixed>
     */
    protected function validerSaisie(Request $request, array $regles): array
    {
        return $request->validate($regles, [
            'required' => 'Le champ :attribute est obligatoire.',
            'string'   => 'Le champ :attribute doit être du texte.',
            'integer'  => 'Le champ :attribute doit être un nombre entier.',
            'min'      => 'Le champ :attribute doit valoir au moins :min.',
            'max'      => 'Le champ :attribute est trop long.',
            'regex'    => 'Le champ :attribute n\'est pas au bon format.',
        ], [
            'identifiant' => 'identifiant',
            'pin'         => 'PIN',
            'pin_actuel'  => 'PIN actuel',
            'nouveau_pin' => 'nouveau PIN',
            'cagnotte'    => 'cagnotte',
            'montant'     => 'montant',
            'code'        => 'code',
        ]);
    }
}
