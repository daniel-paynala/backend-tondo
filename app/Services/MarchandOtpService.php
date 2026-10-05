<?php

namespace App\Services;

use App\Services\Mail\MailgunSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accès du marchand à son suivi : code à usage unique, envoyé par e-mail.
 *
 * Pas de compte, pas de mot de passe — décision de Daniel. Le marchand entre
 * son numéro Airtel, reçoit un code par e-mail, et il est dedans.
 *
 * **Pourquoi le numéro à l'entrée et le code par e-mail**, alors que les deux
 * pourraient aller ensemble : le numéro est ce que le marchand connaît par
 * cœur et ce qui définit le périmètre — c'est lui qui a encaissé. L'e-mail est
 * ce qui prouve qu'on parle bien au titulaire de la fiche, et il ne coûte rien
 * à l'envoi, contrairement au SMS.
 *
 * **Numéro partagé** : depuis le 031, plusieurs fiches peuvent porter le même
 * numéro. Le code part alors vers **toutes les adresses distinctes des fiches
 * actives** de ce numéro, en nommant le demandeur. Elles désignent toutes des
 * titulaires légitimes de ce qu'a encaissé ce numéro ; prévenir celui qui n'a
 * rien demandé est une gêne, pas une fuite — il faudrait encore qu'il saisisse
 * le code.
 *
 * Le code est conservé **haché** : les entrées de cache finissent dans une
 * table, et un code en clair y serait un mot de passe temporaire lisible.
 */
class MarchandOtpService
{
    /** Validité d'un code, en secondes. Assez pour relever ses mails. */
    private const DUREE = 600;

    /** Essais tolérés sur un même code avant de l'invalider. */
    private const ESSAIS_MAX = 5;

    public function __construct(private readonly MailgunSender $mail) {}

    /**
     * Envoie un code aux adresses rattachées à ce numéro.
     *
     * @return int Nombre d'adresses atteintes. Zéro signifie « numéro inconnu
     *             ou sans adresse » — l'appelant ne doit PAS le répercuter
     *             tel quel au client, sous peine de transformer cette route en
     *             annuaire des marchands.
     */
    public function envoyer(string $numeroE164): int
    {
        $adresses = $this->adresses($numeroE164);
        if ($adresses === []) {
            return 0;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->cle($numeroE164), [
            'empreinte' => hash('sha256', $code),
            'essais'    => 0,
        ], self::DUREE);

        $numeroLisible = $this->lisible($numeroE164);
        $sujet = 'Tonji — code d\'accès à votre suivi';
        $corps = '<p>Bonjour,</p>'
            . '<p>Quelqu\'un demande l\'accès au suivi des paiements du numéro <b>'
            . e($numeroLisible) . '</b>.</p>'
            . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . $code . '</p>'
            . '<p>Ce code est valable ' . (self::DUREE / 60) . ' minutes.</p>'
            . '<p style="color:#4A5568">Si vous n\'êtes pas à l\'origine de cette demande, '
            . 'ignorez ce message : sans ce code, personne n\'entre.</p>';

        $atteintes = 0;
        foreach ($adresses as $adresse) {
            try {
                if ($this->mail->envoyer($adresse, $sujet, $corps)) {
                    $atteintes++;
                }
            } catch (\Throwable $e) {
                Log::error('[portail_marchand] code non envoyé', [
                    'erreur' => $e->getMessage(),
                ]);
            }
        }

        return $atteintes;
    }

    /**
     * Vérifie un code et le consomme.
     *
     * Le compteur d'essais vit avec le code : sans lui, la limite de débit par
     * IP suffirait à qui tourne sur plusieurs adresses.
     */
    public function verifier(string $numeroE164, string $code): bool
    {
        $cle     = $this->cle($numeroE164);
        $entree  = Cache::get($cle);
        if (! is_array($entree)) {
            return false;
        }

        if ($entree['essais'] >= self::ESSAIS_MAX) {
            Cache::forget($cle);

            return false;
        }

        // Comparaison à temps constant : un `===` sur des chaînes fuit, par sa
        // durée, le nombre de caractères devinés.
        if (! hash_equals($entree['empreinte'], hash('sha256', $code))) {
            $entree['essais']++;
            Cache::put($cle, $entree, self::DUREE);

            return false;
        }

        // Usage unique : un code relu dans une boîte partagée ne doit pas
        // rouvrir une session plus tard.
        Cache::forget($cle);

        return true;
    }

    /**
     * Adresses distinctes des fiches actives portant ce numéro.
     *
     * @return array<int, string>
     */
    public function adresses(string $numeroE164): array
    {
        return DB::table(project_table('marchands'))
            ->where('numero_tel', $numeroE164)
            ->where('actif', true)
            ->whereNotNull('contact_email')
            ->where('contact_email', '!=', '')
            ->pluck('contact_email')
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cle(string $numeroE164): string
    {
        return 'marchand_otp:' . $numeroE164;
    }

    /** `+24177730634` devient `+241 77 73 06 34`. */
    private function lisible(string $numeroE164): string
    {
        $chiffres = preg_replace('/\D/', '', $numeroE164) ?? '';
        if (strlen($chiffres) < 11) {
            return $numeroE164;
        }
        $local  = substr($chiffres, 3);
        $paires = str_split($local, 2);

        return '+241 ' . implode(' ', $paires);
    }
}
