<?php

namespace App\Services;

/**
 * Définit ce qu'est un numéro de retrait acceptable — pour TOUS les canaux.
 *
 * Il existait auparavant autant de réponses que de canaux : l'application
 * vérifiait le compte Mobile Money, le web aussi, et le bot WhatsApp pas du
 * tout — il se contentait de valider le format. Or le numéro de retrait devient
 * immuable dès la création de la cagnotte (RÈGLE 3) : un numéro sans compte
 * Airtel Money actif s'y gravait définitivement, et le reversement échouait des
 * semaines plus tard, sans recours.
 *
 * Cette classe rend les FAITS ; la formulation des messages reste à chaque
 * canal, parce qu'un message WhatsApp et une réponse JSON ne se rédigent pas de
 * la même façon. Seule exception assumée : {@see pourWhatsApp()}, qui fournit la
 * formulation commune aux deux chemins WhatsApp (texte et Flow), afin qu'un
 * refus ne soit pas dit de deux manières différentes selon le parcours.
 */
class VerificationNumeroRetrait
{
    /**
     * Établit l'opérateur, l'état du compte Mobile Money et le titulaire.
     *
     * @param  string $numero     +241XXXXXXXX ou 0XXXXXXXX.
     * @param  string $projectId  Projet, pour la détection d'opérateur.
     *
     * @return array{operateur:string, kycOk:?bool, titulaire:?string}
     *         `kycOk` : true vérifié · false pas de compte · null indisponible
     *         ou non applicable (Moov ne propose aucun KYC).
     */
    public function verifier(string $numero, string $projectId): array
    {
        $e164  = $this->versE164($numero);
        $local = '0' . substr($e164, 4);

        $operateur = strtolower(
            app(TondoConfigService::class)->detectOperateur($e164, $projectId)['operateur'] ?? 'inconnu'
        );

        if ($operateur !== 'airtel') {
            // Moov ne propose pas de KYC, et un opérateur inconnu n'a rien à
            // interroger : dans les deux cas on ne sait pas, on ne prétend pas.
            return ['operateur' => $operateur, 'kycOk' => null, 'titulaire' => null];
        }

        // Format local : c'est sous cette forme que l'inscription et
        // l'application mettent le KYC en cache pour 24 h. Interroger en E.164
        // manquerait le cache et rappellerait l'API pour rien.
        $kyc = app(PaynalaPaymentService::class)->checkKycData($local);

        if ($kyc === null) {
            return ['operateur' => 'airtel', 'kycOk' => null, 'titulaire' => null];
        }

        if (($kyc['ok'] ?? false) !== true) {
            return ['operateur' => 'airtel', 'kycOk' => false, 'titulaire' => null];
        }

        return [
            'operateur' => 'airtel',
            'kycOk'     => true,
            'titulaire' => self::composerTitulaire($kyc),
        ];
    }

    /**
     * Traduit les faits en verdict et message pour les parcours WhatsApp.
     *
     * @return array{ok:bool, titulaire:?string, message:string}
     *         `ok` à false : la création doit s'arrêter ; `message` est destiné
     *         à être renvoyé tel quel à l'utilisateur.
     */
    public function pourWhatsApp(string $numero, string $projectId): array
    {
        return self::verdictWhatsApp($this->verifier($numero, $projectId));
    }

    /**
     * Traduit des faits déjà établis en verdict WhatsApp — sans aucun appel
     * réseau ni accès base, afin d'être éprouvée directement par les tests.
     *
     * @param  array{operateur:string, kycOk:?bool, titulaire:?string} $faits
     * @return array{ok:bool, titulaire:?string, message:string}
     */
    public static function verdictWhatsApp(array $faits): array
    {
        if ($faits['operateur'] === 'moov') {
            // Aucun KYC disponible : on laisse passer sans prétendre avoir
            // vérifié, exactement comme le fait l'application.
            return ['ok' => true, 'titulaire' => null, 'message' => ''];
        }

        if ($faits['operateur'] !== 'airtel') {
            return [
                'ok'        => false,
                'titulaire' => null,
                'message'   => "⚠️ Opérateur non reconnu pour ce numéro.\n"
                    . "Indique un numéro Airtel Money ou Moov Money.",
            ];
        }

        if ($faits['kycOk'] === null) {
            // Service injoignable. On bloque plutôt que de laisser passer : un
            // numéro gravé sans vérification est irrattrapable, quelques
            // minutes d'attente ne le sont pas.
            return [
                'ok'        => false,
                'titulaire' => null,
                'message'   => "⏳ Vérification indisponible pour l'instant.\n"
                    . "Réessaie dans quelques minutes.",
            ];
        }

        if ($faits['kycOk'] === false) {
            return [
                'ok'        => false,
                'titulaire' => null,
                'message'   => "⚠️ Ce numéro n'a pas de compte Airtel Money actif.\n"
                    . "Le reversement ne pourrait pas aboutir, et ce numéro ne sera "
                    . "plus modifiable après la création.\n\nIndique un autre numéro.",
            ];
        }

        return ['ok' => true, 'titulaire' => $faits['titulaire'], 'message' => ''];
    }

    /**
     * Compose le nom affichable du titulaire.
     *
     * Airtel renvoie prénom et nom séparément, et l'un des deux est parfois
     * vide. On renvoie null plutôt qu'une chaîne vide : un libellé vide sous le
     * numéro laisserait croire à une vérification qui n'aurait rien appris.
     *
     * @param  array<string, mixed> $kyc
     */
    public static function composerTitulaire(array $kyc): ?string
    {
        $nom = trim(
            trim((string) ($kyc['prenom'] ?? '')) . ' ' . trim((string) ($kyc['nom'] ?? ''))
        );

        return $nom === '' ? null : $nom;
    }

    /** Ramène un numéro gabonais au format E.164, quel que soit celui d'entrée. */
    private function versE164(string $numero): string
    {
        $chiffres = preg_replace('/\D/', '', $numero) ?? '';

        if (str_starts_with($chiffres, '241')) {
            return '+' . $chiffres;
        }

        return '+241' . ltrim($chiffres, '0');
    }
}
