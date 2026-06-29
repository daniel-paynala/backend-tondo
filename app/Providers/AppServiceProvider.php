<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Fournisseur de services principal de l'application Tondo.
 *
 * Responsabilités typiques de ce provider :
 *  – `register()` : lier des interfaces à leurs implémentations dans le container IoC.
 *  – `boot()`     : bootstrapper des comportements globaux (macros, observers, gates…).
 *
 * Actuellement vide — les bindings spécifiques Tondo sont répartis dans
 * des providers dédiés (ex : PaymentServiceProvider, WhatsAppServiceProvider)
 * ou gérés par l'auto-discovery Laravel.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistre les liaisons dans le container IoC.
     *
     * Appelé avant boot() — à utiliser pour les bindings dont d'autres providers
     * pourraient dépendre au démarrage.
     */
    public function register(): void
    {
        //
    }

    /**
     * Initialise les services après que tous les providers ont été enregistrés.
     *
     * Bon endroit pour : model observers, gate definitions, macro Blade,
     * configuration de la queue, validation rules personnalisées, etc.
     */
    public function boot(): void
    {
        $this->configurerRateLimiters();
    }

    /**
     * Définit les limiteurs de débit des endpoints publics sensibles.
     *
     * Au Gabon, les opérateurs mobiles placent beaucoup d'abonnés derrière peu
     * d'adresses IP (NAT) : un throttle PAR IP bloquerait des utilisateurs
     * légitimes. On limite donc en priorité PAR NUMÉRO de téléphone, avec un
     * garde-fou IP volontairement large (anti-abus massif uniquement).
     */
    private function configurerRateLimiters(): void
    {
        // Envoi d'OTP : limite le nombre de SMS (coût Wirepick) par numéro.
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = $this->cleNumero($request);
            return [
                Limit::perMinutes(10, 3)->by("otpreq:phone:{$phone}"), // 3 SMS / 10 min / numéro
                Limit::perHour(8)->by("otpreq:phoneh:{$phone}"),       // 8 SMS / h / numéro
                Limit::perMinute(40)->by("otpreq:ip:{$request->ip()}"),// garde-fou IP large
            ];
        });

        // Vérification d'OTP : en plus du MAX_TRIES=5 par code (PaynalaOtpService),
        // borne les essais par numéro pour empêcher le brute-force multi-codes.
        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = $this->cleNumero($request);
            return [
                Limit::perMinutes(10, 10)->by("otpver:phone:{$phone}"),
                Limit::perMinute(60)->by("otpver:ip:{$request->ip()}"),
            ];
        });

        // KYC check (public) : freine l'énumération de numéros Airtel/Tonji.
        RateLimiter::for('kyc-check', function (Request $request) {
            $phone = $this->cleNumero($request);
            return [
                Limit::perMinutes(10, 15)->by("kyc:phone:{$phone}"),
                Limit::perMinute(40)->by("kyc:ip:{$request->ip()}"),
            ];
        });

        // Login admin : par e-mail + IP (anti brute-force mot de passe).
        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            return [Limit::perMinute(5)->by("adminlogin:{$email}|{$request->ip()}")];
        });
    }

    /**
     * Clé de limitation basée sur le numéro saisi (chiffres uniquement), pour
     * regrouper indépendamment du format (+241…, 0…, indicatif séparé ou non).
     */
    private function cleNumero(Request $request): string
    {
        $numero = (string) $request->input('numero');
        return preg_replace('/\D/', '', $numero) ?: 'inconnu';
    }
}
