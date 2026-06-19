<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\AirtelFeesCalculator;
use App\Services\OperateurDetectorService;
use App\Services\PaynalaPaymentService;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Logique de paiement (payin) pour le canal WhatsApp.
 *
 * Miroir fonctionnel de CotisationsController sans la couche HTTP :
 * retourne des tableaux associatifs au lieu de JsonResponse.
 *
 * Deux flux de paiement sont supportés :
 *   - Airtel Money réel : push envoyé sur le téléphone du cotisant, confirmation
 *     asynchrone via webhook ou polling du scheduler.
 *   - Mock (développement / opérateur non reconnu) : crédite immédiatement,
 *     retourne 'succes' en synchrone.
 *
 * Valeurs de retour de initier() :
 *   ['statut' => 'initie',  'trans_id' => '...']          → Airtel, push envoyé
 *   ['statut' => 'succes',  'trans_id' => '...', ...]     → Mock, crédité immédiatement
 *   ['statut' => 'erreur',  'message'  => '...']          → échec technique
 *
 * Valeurs de retour de verifierStatut() :
 *   'initie' | 'succes' | 'echec'
 */
class CotisationService
{
    public function __construct(
        private readonly PaynalaPaymentService    $paynala,
        private readonly OperateurDetectorService $detector,
        private readonly TondoConfigService       $config,
    ) {}

    // ── Créer un compte light ────────────────────────────────────────────────

    /**
     * Crée un compte utilisateur minimal pour un cotisant WhatsApp.
     *
     * Compte "light" : aucune date de naissance réelle (placeholder 1900-01-01),
     * kyc_valide = false. Utilisé pour les cotisants dont on n'a pas collecté
     * l'identité complète (ex : paiement spontané sur une cagnotte ouverte).
     * Si un compte existe déjà pour ce numéro (suffixe), il est retourné tel quel.
     *
     * @param  string $nom        Nom de famille (peut être vide pour un compte anonyme)
     * @param  string $prenom     Prénom (peut être vide)
     * @param  string $numeroE164 Numéro Mobile Money au format E.164 (+24177XXXXXX)
     * @param  string $projectId  UUID du projet Tondo
     * @return TondoUser          Compte créé ou existant
     */
    public function creerCompteLight(
        string $nom,
        string $prenom,
        string $numeroE164,
        string $projectId,
    ): TondoUser {
        // Recherche par suffixe (9 derniers chiffres) pour ignorer les variantes de préfixe (+241 vs 0)
        $suffixe  = substr(preg_replace('/\D/', '', $numeroE164), -9);
        $existant = TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();

        if ($existant) {
            return $existant;
        }

        $user = new TondoUser();
        $user->id             = (string) Str::uuid();
        $user->project_id     = $projectId;
        $user->nom            = mb_strtoupper(trim($nom));
        $user->prenom         = ucfirst(mb_strtolower(trim($prenom)));
        $user->numero         = $numeroE164;
        $user->indicatif      = '+241';
        $user->pays           = 'GA';
        $user->operateur      = null;
        $user->date_naissance = '1900-01-01';   // placeholder — compte light WhatsApp
        $user->kyc_valide     = false;           // pas de vérification KYC opérateur
        $user->type_client    = 'particulier';
        $user->created_at     = now();
        $user->updated_at     = now();
        $user->save();

        return $user;
    }

    /**
     * Crée ou met à jour un compte utilisateur complet (certifié majeur).
     *
     * Compte "full" : date de naissance réelle fournie, certifie_majeur = true.
     * Utilisé lors de la création d'une cagnotte/tontine, où l'identité est requise.
     * Si un compte light existe déjà pour ce numéro, il est promu en compte full
     * (mise à jour du nom, prénom, date de naissance, certifie_majeur).
     *
     * @param  string $nom            Nom de famille
     * @param  string $prenom         Prénom
     * @param  string $numeroE164     Numéro E.164
     * @param  string $projectId      UUID du projet
     * @param  string $dateNaissance  Date au format Y-m-d (ex : '2000-01-01' — placeholder WA)
     * @return TondoUser              Compte créé ou mis à jour
     */
    public function creerCompteFull(
        string $nom,
        string $prenom,
        string $numeroE164,
        string $projectId,
        string $dateNaissance,
    ): TondoUser {
        // Recherche par suffixe pour tolérer les variantes de préfixe
        $suffixe  = substr(preg_replace('/\D/', '', $numeroE164), -9);
        $existant = TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();

        if ($existant) {
            // Mise à jour du profil : promotion compte light → full
            $existant->nom             = mb_strtoupper(trim($nom));
            $existant->prenom          = ucfirst(mb_strtolower(trim($prenom)));
            $existant->date_naissance  = $dateNaissance;
            $existant->certifie_majeur = true;   // l'utilisateur a certifié avoir 18 ans
            $existant->updated_at      = now();
            $existant->save();
            return $existant;
        }

        $user = new TondoUser();
        $user->id              = (string) Str::uuid();
        $user->project_id      = $projectId;
        $user->nom             = mb_strtoupper(trim($nom));
        $user->prenom          = ucfirst(mb_strtolower(trim($prenom)));
        $user->numero          = $numeroE164;
        $user->indicatif       = '+241';
        $user->pays            = 'GA';
        $user->operateur       = null;
        $user->date_naissance  = $dateNaissance;
        $user->certifie_majeur = true;    // certifié majeur lors de la création
        $user->kyc_valide      = false;   // KYC opérateur non encore vérifié
        $user->type_client     = 'particulier';
        $user->created_at      = now();
        $user->updated_at      = now();
        $user->save();

        return $user;
    }

    // ── Initier un paiement ───────────────────────────────────────────────────

    /**
     * Initie le payin (Airtel push ou mock selon l'opérateur détecté).
     *
     * Calcul des frais :
     *   - Airtel : utilise AirtelFeesCalculator (plan) + commission Paynala sur le total
     *   - Mock   : commission Paynala seule (2 % par défaut)
     * Les frais sont toujours à la charge du cotisant (montant_brut > montant_net).
     *
     * Pour les tontines : une pénalité peut s'ajouter au montant brut si des cycles
     * ont déjà été complétés et que la tontine est configurée avec pénalité de retard.
     *
     * Ne nécessite pas de Request — opère directement sur les modèles Eloquent.
     *
     * @param  TondoUser    $user     Cotisant (le numéro de paiement est dans $user->numero)
     * @param  TondoCagnotte $cagnotte Cagnotte ou tontine cible
     * @param  int           $montant  Montant net voulu par le bénéficiaire (FCFA)
     * @return array{statut: string, trans_id?: string, montant_net?: int, frais?: int, montant_brut?: int, message?: string}
     */
    public function initier(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montant,
    ): array {
        // Calcul de la pénalité (tontine uniquement, si paiement en retard)
        $penalite = 0;
        if ($cagnotte->type === 'tontine_periodique') {
            // Nombre de cycles déjà reversés pour évaluer le retard éventuel
            $cyclesCompletes = DB::table('tondo_payout')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->count();
            try {
                $penalite = app(\App\Services\TontineService::class)
                    ->calculerPenalite($cagnotte, $cyclesCompletes);
            } catch (\Throwable) {
                // TontineService peut ne pas être disponible ou la pénalité vaut 0 par défaut
                $penalite = 0;
            }
        }

        // Détection de l'opérateur du numéro cotisant (Airtel, Moov, inconnu…)
        $operateurInfo = $this->detector->detect($cagnotte->project_id, $user->numero);
        $isAirtel      = $operateurInfo && $operateurInfo['operateur'] === 'airtel';

        // Calcul des frais selon l'opérateur
        if ($isAirtel) {
            // Airtel : frais de retrait calculés par AirtelFeesCalculator + commission Paynala
            $airtelConfig = $this->config->getOperatorConfig($cagnotte->project_id);
            $calc         = new AirtelFeesCalculator($airtelConfig);
            $commission   = (float) $airtelConfig['commission_paynala'];
            $plan         = $calc->plan($montant);
            // total_a_envoyer inclut déjà les frais Airtel ; on y ajoute la commission Paynala
            $montantBrut  = (int) ceil($plan['total_a_envoyer'] * (1 + $commission));
        } else {
            // Mock / opérateur non reconnu : seule la commission Paynala (2 % par défaut)
            $configData  = $this->config->getOperatorConfig($cagnotte->project_id);
            $commission  = (float) ($configData['commission_paynala'] ?? 0.02);
            $montantBrut = (int) round($montant * (1 + $commission));
        }

        // Frais = différence entre ce que paie le cotisant et ce que reçoit le bénéficiaire
        $frais        = $montantBrut - $montant;
        // La pénalité s'ajoute en sus du montant brut (cotisant la supporte aussi)
        $montantTotal = $montantBrut + $penalite;

        if ($isAirtel) {
            return $this->initierAirtel(
                user: $user, cagnotte: $cagnotte,
                montantNet: $montant, frais: $frais,
                montantBrut: $montantTotal, penalite: $penalite,
                operateurIndicatif: $operateurInfo['indicatif'],
            );
        }

        return $this->initierMock(
            user: $user, cagnotte: $cagnotte,
            montantNet: $montant, frais: $frais,
            montantBrut: $montantTotal, penalite: $penalite,
        );
    }

    // ── Vérifier le statut d'une transaction ─────────────────────────────────

    /**
     * Interroge l'API Paynala pour mettre à jour le statut d'un payin Airtel.
     *
     * Appelé soit manuellement (l'utilisateur tape "OK" dans le bot),
     * soit automatiquement par le scheduler toutes les minutes.
     *
     * Logique :
     *   1. Lit la ligne tondo_payin — si déjà 'succes' ou 'echec', retourne directement.
     *   2. Appelle PaynalaPaymentService::checkStatus() pour interroger Airtel.
     *   3. Sur SUCCESS : appelle crediterSurSucces() qui met à jour la DB en transaction.
     *   4. Sur FAILED  : marque la ligne 'echec' en DB.
     *   5. Sinon       : retourne 'initie' (toujours en attente).
     *
     * @param  string $transId    Identifiant interne de la transaction (ex : TONDOPAYIN...)
     * @param  string $projectId  UUID du projet Tondo
     * @return string             'initie' | 'succes' | 'echec'
     */
    public function verifierStatut(string $transId, string $projectId): string
    {
        $payin = DB::table('tondo_payin')
            ->where('trans_id', $transId)
            ->where('project_id', $projectId)
            ->first();

        if (! $payin) {
            // Transaction introuvable → considérée en échec
            return 'echec';
        }

        // Statut déjà terminal : pas besoin d'interroger l'API
        if (in_array($payin->statut, ['succes', 'echec'])) {
            return $payin->statut;
        }

        try {
            $statusData = $this->paynala->checkStatus($transId);
        } catch (\Throwable $e) {
            // Erreur réseau ou API Paynala indisponible : on conserve 'initie'
            Log::warning('WhatsApp CotisationService::verifierStatut erreur', ['err' => $e->getMessage()]);
            return 'initie';
        }

        // L'API Paynala retourne 'status' en majuscules (SUCCESS, FAILED, PENDING…)
        $apiStatus = strtoupper($statusData['status'] ?? 'PENDING');

        if ($apiStatus === 'SUCCESS') {
            // Créditer le bénéficiaire et mettre à jour la DB en une transaction atomique
            $this->crediterSurSucces($payin, $statusData);
            return 'succes';
        }

        if ($apiStatus === 'FAILED') {
            DB::table('tondo_payin')
                ->where('trans_id', $transId)
                ->update(['statut' => 'echec', 'response' => json_encode($statusData), 'updated_at' => now()]);
            return 'echec';
        }

        // PENDING ou tout autre statut inconnu : toujours en attente
        return 'initie';
    }

    // ── Privé — flux Airtel ───────────────────────────────────────────────────

    /**
     * Initie un paiement Airtel Money (push USSD sur le téléphone du cotisant).
     *
     * Séquence :
     *   1. Génère un identifiant de transaction interne (TONDOPAYIN + random).
     *   2. Convertit le numéro E.164 en format local Airtel (0XXXXXXXX).
     *   3. Appelle PaynalaPaymentService::createPayment() — envoie la demande push à Airtel.
     *   4. En DB (transaction atomique) : crée ou met à jour le participant,
     *      incrémente nombre_inscrits/nombre_participants, insère la ligne tondo_payin
     *      avec statut 'initie'.
     *
     * Le statut reste 'initie' jusqu'à confirmation Airtel (webhook ou polling).
     *
     * @param  TondoUser    $user               Cotisant
     * @param  TondoCagnotte $cagnotte           Cagnotte cible
     * @param  int           $montantNet         Montant que reçoit le bénéficiaire (FCFA)
     * @param  int           $frais              Commission Paynala + frais Airtel (FCFA)
     * @param  int           $montantBrut        Ce que débite réellement le cotisant (FCFA)
     * @param  int           $penalite           Pénalité de retard éventuelle (FCFA)
     * @param  string        $operateurIndicatif Indicatif Airtel (ex : '+241')
     * @return array{statut: string, trans_id?: string, montant_net?: int, frais?: int, montant_brut?: int, message?: string}
     */
    private function initierAirtel(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montantNet,
        int           $frais,
        int           $montantBrut,
        int           $penalite,
        string        $operateurIndicatif,
    ): array {
        // Identifiant de transaction interne unique (traçabilité)
        $transId       = 'TONDOPAYIN' . strtoupper(Str::random(10));
        // Numéro sans "+" pour extraire les chiffres locaux
        $phoneE164     = ltrim($user->numero, '+');
        // Supprime l'indicatif pour obtenir la partie locale (ex : 77123456)
        $localSansZero = substr($phoneE164, strlen(ltrim($operateurIndicatif, '+')));
        // Format Airtel local : préfixe "0" (ex : 077123456)
        $phoneAirtel   = '0' . $localSansZero;

        try {
            $paymentData = $this->paynala->createPayment(
                requestId: $transId,
                amount:    $montantBrut,   // le cotisant paie le montant brut (frais inclus)
                phone:     $phoneAirtel,
                firstName: $user->prenom ?? '',
                lastName:  $user->nom    ?? '',
            );
        } catch (\RuntimeException $e) {
            return ['statut' => 'erreur', 'message' => $e->getMessage()];
        }

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $transId,
                $montantNet, $montantBrut, $frais, $penalite, $paymentData, $phoneAirtel
            ) {
                // Vérifier si l'utilisateur est déjà participant (paiement partiel antérieur)
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                $isNew = ! $participant;

                if ($isNew) {
                    // Nouveau participant : insérer la ligne et incrémenter les compteurs
                    DB::table('tondo_participants')->insert([
                        'id'               => (string) Str::uuid(),
                        'project_id'       => $cagnotte->project_id,
                        'cagnotte_id'      => $cagnotte->id,
                        'user_id'          => $user->id,
                        'nom'              => $user->nom,
                        'prenom'           => $user->prenom,
                        'numero_masque'    => $this->maskPhone($user->numero),
                        'statut_paiement'  => 'en_attente',   // sera mis à jour sur succès
                        'montant_paye'     => 0,
                        'created_at'       => now(),
                    ]);
                    // Pour les cagnottes ouvertes : incrémenter le nombre de participants uniques
                    if ($cagnotte->type === 'cagnotte_ouverte') {
                        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                            ->increment('nombre_participants');
                    }
                    // nombre_inscrits compte tous types (cagnotte + tontine)
                    DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                        ->increment('nombre_inscrits');
                }

                // Insérer la ligne de payin avec statut 'initie' (push en attente de validation)
                DB::table('tondo_payin')->insert([
                    'id'               => (string) Str::uuid(),
                    'project_id'       => $cagnotte->project_id,
                    'cagnotte_id'      => $cagnotte->id,
                    'user_id'          => $user->id,
                    'trans_id'         => $transId,
                    'operateur_id'     => $paymentData['paymentId'] ?? null,  // ID Airtel retourné
                    'numero_tel'       => $user->numero,
                    'montant'          => $montantBrut,   // montant débité au cotisant

                    'statut'           => 'initie',
                    'request'          => json_encode([
                        'request_id'  => $transId,
                        'amount'      => $montantBrut,
                        'montant_net' => $montantNet,   // nécessaire pour crediterSurSucces
                        'phone'       => $phoneAirtel,
                        'canal'       => 'whatsapp',
                    ]),
                    'response'         => json_encode($paymentData),
                    'date_creation'    => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            });
        } catch (\Throwable $e) {
            return ['statut' => 'erreur', 'message' => 'Erreur enregistrement : ' . $e->getMessage()];
        }

        return [
            'statut'      => 'initie',
            'trans_id'    => $transId,
            'montant_net' => $montantNet,
            'frais'       => $frais,
            'montant_brut'=> $montantBrut,
        ];
    }

    // ── Privé — flux Mock ─────────────────────────────────────────────────────

    /**
     * Simule un paiement réussi immédiatement (sans appel externe).
     *
     * Utilisé en développement ou lorsque l'opérateur n'est pas Airtel.
     * Crédite directement montant_collecte et insère un paiement confirmé.
     * Retourne 'succes' en synchrone, contrairement au flux Airtel.
     *
     * @param  TondoUser    $user        Cotisant
     * @param  TondoCagnotte $cagnotte   Cagnotte cible
     * @param  int           $montantNet Montant net (bénéficiaire)
     * @param  int           $frais      Frais simulés
     * @param  int           $montantBrut Montant total débité (fictif)
     * @param  int           $penalite   Pénalité éventuelle
     * @return array{statut: string, trans_id: string, montant_net: int, frais: int, montant_brut: int}
     */
    private function initierMock(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montantNet,
        int           $frais,
        int           $montantBrut,
        int           $penalite,
    ): array {
        $transId = 'TONDOPAYIN' . strtoupper(Str::random(10));

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $transId, $montantNet, $montantBrut, $penalite
            ) {
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                // Réutiliser l'UUID existant ou en générer un nouveau
                $participantId = $participant?->id ?? (string) Str::uuid();

                if (! $participant) {
                    // Nouveau participant : insérer avec statut déjà 'paye' (mock = succès immédiat)
                    DB::table('tondo_participants')->insert([
                        'id'              => $participantId,
                        'project_id'      => $cagnotte->project_id,
                        'cagnotte_id'     => $cagnotte->id,
                        'user_id'         => $user->id,
                        'nom'             => $user->nom,
                        'prenom'          => $user->prenom,
                        'numero_masque'   => $this->maskPhone($user->numero),
                        'statut_paiement' => 'paye',
                        'montant_paye'    => $montantNet,
                        'date_dernier_paiement' => now(),
                        'created_at'      => now(),
                    ]);
                    if ($cagnotte->type === 'cagnotte_ouverte') {
                        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->increment('nombre_participants');
                    }
                    DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->increment('nombre_inscrits');
                } else {
                    // Participant existant : cumuler le montant payé (paiements multiples possibles)
                    DB::table('tondo_participants')->where('id', $participantId)->update([
                        'statut_paiement'       => 'paye',
                        'montant_paye'          => DB::raw('montant_paye + ' . $montantNet),
                        'date_dernier_paiement' => now(),
                    ]);
                }

                // Insérer l'enregistrement dans l'historique des paiements confirmés
                DB::table('tondo_paiements')->insert([
                    'id'             => (string) Str::uuid(),
                    'project_id'     => $cagnotte->project_id,
                    'cagnotte_id'    => $cagnotte->id,
                    'participant_id' => $participantId,
                    'user_id'        => $user->id,
                    'montant'        => $montantNet,   // montant net (ce que reçoit le bénéficiaire)
                    'date'           => now(),
                    'created_at'     => now(),
                ]);

                // Ligne de payin avec statut 'succes' dès le départ (mock)
                DB::table('tondo_payin')->insert([
                    'id'            => (string) Str::uuid(),
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $user->id,
                    'trans_id'      => $transId,
                    'operateur_id'  => 'MOCK-' . substr($transId, -8),   // ID fictif traçable
                    'numero_tel'    => $user->numero,
                    'montant'          => $montantBrut,

                    'statut'           => 'succes',
                    'request'          => json_encode(['note' => 'mock whatsapp', 'canal' => 'whatsapp']),
                    'response'         => json_encode(['ok' => true, 'mocked' => true]),
                    'date_creation'    => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                // Créditer le solde de la cagnotte (montant_net uniquement, pas les frais)
                DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                    ->increment('montant_collecte', $montantNet);
            });
        } catch (\Throwable $e) {
            return ['statut' => 'erreur', 'message' => $e->getMessage()];
        }

        return [
            'statut'       => 'succes',
            'trans_id'     => $transId,
            'montant_net'  => $montantNet,
            'frais'        => $frais,
            'montant_brut' => $montantBrut,
        ];
    }

    // ── Créditer sur succès Airtel (appelé par verifierStatut) ───────────────

    /**
     * Met à jour la DB après confirmation Airtel (webhook ou polling).
     *
     * Exécuté dans une transaction DB atomique :
     *   1. Marque la ligne tondo_payin à 'succes'.
     *   2. Met à jour le participant (statut_paiement = 'paye', cumul montant_paye).
     *   3. Insère un enregistrement dans tondo_paiements (historique).
     *   4. Incrémente montant_collecte de la cagnotte (montant net).
     *
     * Le montant net est lu depuis le champ 'request' JSON enregistré lors de initierAirtel.
     * Fallback : 98 % du montant brut si la clé 'montant_net' est absente.
     *
     * @param  object $payin      Ligne tondo_payin (stdClass)
     * @param  array  $statusData Réponse de l'API Paynala (statut SUCCESS)
     */
    private function crediterSurSucces(object $payin, array $statusData): void
    {
        // Récupérer le montant net depuis la requête initiale stockée en JSON
        $requestMeta = json_decode($payin->request, true) ?? [];
        $netAmount   = $requestMeta['montant_net'] ?? (int) round($payin->montant * 0.98);

        DB::transaction(function () use ($payin, $statusData, $netAmount) {
            // 1. Marquer le payin comme confirmé
            DB::table('tondo_payin')->where('trans_id', $payin->trans_id)->update([
                'statut'     => 'succes',
                'response'   => json_encode($statusData),
                'updated_at' => now(),
            ]);

            // 2. Mettre à jour le participant correspondant
            $participant = DB::table('tondo_participants')
                ->where('cagnotte_id', $payin->cagnotte_id)
                ->where('user_id', $payin->user_id)
                ->first();

            if ($participant) {
                DB::table('tondo_participants')->where('id', $participant->id)->update([
                    'statut_paiement'       => 'paye',
                    'montant_paye'          => DB::raw('montant_paye + ' . $netAmount),
                    'date_dernier_paiement' => now(),
                ]);

                // 3. Insérer dans l'historique des paiements confirmés
                DB::table('tondo_paiements')->insert([
                    'id'             => (string) Str::uuid(),
                    'project_id'     => $payin->project_id,
                    'cagnotte_id'    => $payin->cagnotte_id,
                    'participant_id' => $participant->id,
                    'user_id'        => $payin->user_id,
                    'montant'        => $netAmount,
                    'date'           => now(),
                    'created_at'     => now(),
                ]);
            }

            // 4. Créditer la cagnotte (montant net seulement)
            DB::table('tondo_cagnottes')->where('id', $payin->cagnotte_id)
                ->increment('montant_collecte', $netAmount);
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Masque les chiffres centraux d'un numéro pour l'affichage (protection vie privée).
     *
     * Exemple : +24177123456 → +241771****56
     * Conserve le préfixe et les 2 derniers chiffres, masque le reste.
     *
     * @param  string $phone  Numéro E.164 ou local
     * @return string         Numéro masqué
     */
    private function maskPhone(string $phone): string
    {
        // Conserver uniquement les chiffres et le "+"
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        // Le préfixe = tout sauf les 6 derniers caractères
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
