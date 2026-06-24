<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Système OTP interne Paynala.
 *
 * Responsabilités :
 *  - Générer un code à 6 chiffres aléatoire.
 *  - Le stocker en cache (TTL 10 min, clé = numéro E.164).
 *  - Compter les tentatives et invalider après [MAX_TRIES] erreurs.
 *  - Déléguer la livraison SMS à [WirepickSmsService].
 *
 * Ce service remplace Twilio Verify pour le driver `paynala` dans [AuthController].
 * La logique de vérification est identique à ce que Twilio Verify fait côté serveur,
 * mais sous notre contrôle (pas de dépendance externe pour la gestion du code).
 */
class PaynalaOtpService
{
    /** Durée de validité d'un code OTP en secondes (5 minutes). */
    private const TTL_SECONDS = 300;

    /** Nombre maximum de tentatives de vérification avant invalidation. */
    private const MAX_TRIES = 5;

    /**
     * @param WirepickSmsService $wirepick  Service de livraison SMS injecté.
     */
    public function __construct(private WirepickSmsService $wirepick) {}

    /**
     * Génère un code OTP, le stocke en cache, et l'envoie par SMS via Wirepick.
     *
     * Un précédent code éventuel pour ce numéro est écrasé — l'utilisateur peut
     * demander un renvoi sans que l'ancien code reste valide.
     *
     * @param string $phoneE164  Numéro destinataire au format E.164 (ex : "+24177123456").
     * @throws RuntimeException  Si Wirepick rejette l'envoi (propagé depuis WirepickSmsService).
     */
    public function sendOtp(string $phoneE164): void
    {
        // Génère un code à 6 chiffres avec zéro-padding (ex : "007341").
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Stocke code + compteur de tentatives — écrase tout code précédent.
        Cache::put(
            $this->cacheKey($phoneE164),
            ['code' => $code, 'tries' => 0],
            self::TTL_SECONDS
        );

        $message = "Tonji - Votre code de vérification : {$code}. Valable 5 minutes. Ne le partagez jamais.";

        $this->wirepick->send($phoneE164, $message);

        Log::info("[paynala-otp] code envoyé à {$phoneE164}");
    }

    /**
     * Vérifie le code soumis par l'utilisateur.
     *
     * - Retourne `false` si le cache est vide (expiré ou jamais demandé).
     * - Incrémente le compteur à chaque échec.
     * - Invalide et retourne `false` si [MAX_TRIES] est atteint.
     * - Supprime le code du cache dès qu'il est validé (usage unique).
     *
     * @param string $phoneE164  Numéro au format E.164.
     * @param string $code       Code soumis par l'utilisateur (6 chiffres).
     * @return bool  true si le code est correct et encore valide.
     */
    public function checkOtp(string $phoneE164, string $code): bool
    {
        $key  = $this->cacheKey($phoneE164);
        $data = Cache::get($key);

        // OTP expiré ou jamais demandé.
        if ($data === null) {
            return false;
        }

        // Trop de tentatives — le code est brûlé.
        if ($data['tries'] >= self::MAX_TRIES) {
            Cache::forget($key);
            Log::warning("[paynala-otp] trop de tentatives pour {$phoneE164} — code invalidé");
            return false;
        }

        if ($data['code'] !== $code) {
            // Incrémente les tentatives mais conserve le TTL restant.
            Cache::put($key, ['code' => $data['code'], 'tries' => $data['tries'] + 1], self::TTL_SECONDS);
            return false;
        }

        // Code correct — supprime immédiatement (usage unique).
        Cache::forget($key);
        Log::info("[paynala-otp] code validé pour {$phoneE164}");
        return true;
    }

    /**
     * Clé de cache unique par numéro E.164.
     * Le `+` est retiré pour éviter des problèmes d'encodage selon le driver cache.
     */
    private function cacheKey(string $phoneE164): string
    {
        return 'paynala_otp.' . ltrim($phoneE164, '+');
    }
}
