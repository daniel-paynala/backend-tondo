<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Jeton de session du portail marchand.
 *
 * **Sans état, volontairement.** Un marchand n'a pas de compte : lui créer une
 * ligne de session reviendrait à inventer l'objet que la décision de Daniel
 * écarte — « pas de création de compte, numéro, OTP, il entre et c'est bon ».
 *
 * Le chiffrement de Laravel est authentifié (AES-256 + MAC) : un jeton modifié
 * ne se déchiffre pas. Personne ne peut donc se fabriquer l'accès au suivi
 * d'un autre numéro.
 *
 * Contrepartie assumée : **pas de révocation**. Un jeton volé reste valable
 * jusqu'à son expiration. D'où une durée courte — le portail est en lecture
 * seule, il n'y a rien à y faire de dommageable, et se reconnecter ne coûte
 * qu'un e-mail.
 */
final class JetonMarchand
{
    /** Durée de vie, en secondes. Une session de travail, pas une journée. */
    public const DUREE = 7200;

    private function __construct() {}

    public static function creer(string $numeroE164, string $projectId): string
    {
        return Crypt::encrypt([
            'numero' => $numeroE164,
            'projet' => $projectId,
            'exp'    => now()->addSeconds(self::DUREE)->timestamp,
        ]);
    }

    /**
     * Contenu du jeton, ou null s'il est illisible, falsifié ou périmé.
     *
     * @return array{numero: string, projet: string}|null
     */
    public static function lire(?string $jeton): ?array
    {
        if ($jeton === null || $jeton === '') {
            return null;
        }

        try {
            $charge = Crypt::decrypt($jeton);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($charge)
            || ! isset($charge['numero'], $charge['projet'], $charge['exp'])
            || $charge['exp'] < now()->timestamp) {
            return null;
        }

        return ['numero' => (string) $charge['numero'], 'projet' => (string) $charge['projet']];
    }
}
