<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\AirtelFeesCalculator;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création d'une tontine périodique ou d'une cagnotte ouverte via le canal WhatsApp.
 *
 * Appelé depuis BotService après validation du récapitulatif par l'utilisateur.
 * Encapsule toute la logique de construction du modèle TondoCagnotte :
 * calcul des frais (via AirtelFeesCalculator), génération de la référence unique,
 * inscription automatique du créateur comme premier membre (tontines uniquement).
 */
class CreerCagnotteService
{
    public function __construct(private TondoConfigService $configSvc) {}

    /**
     * Crée et persiste une cagnotte ou tontine à partir des données de session.
     *
     * Les champs diffèrent selon le type :
     *
     * Pour 'tontine_periodique' :
     *   - cashBack          = montant récupéré par chaque bénéficiaire à son tour de passage
     *   - appliquerPlan()   = remplit montant_beneficiaire, total_a_envoyer, montant_avec_frais,
     *                         nombre_splits, nombre_envois depuis le plan AirtelFeesCalculator
     *   - Le créateur est automatiquement inscrit comme premier membre.
     *
     * Pour 'cagnotte_ouverte' :
     *   - montant_cible     = objectif optionnel (0 ou absent = pas de limite)
     *   - appliquerPlan()   est appelé uniquement si un montant cible est défini
     *   - Le créateur n'est PAS inscrit comme membre à la création.
     *
     * @param  array     $data  Données collectées étape par étape (session BotService)
     * @param  TondoUser $user  Créateur de la cagnotte (déjà authentifié et certifié)
     * @return TondoCagnotte    Modèle persisté en base
     *
     * @throws \RuntimeException Si la génération de référence unique échoue après 20 essais
     */
    public function creer(array $data, TondoUser $user): TondoCagnotte
    {
        $type         = $data['type'];
        // Récupérer la configuration opérateur (frais Airtel, commission Paynala)
        $airtelConfig = $this->configSvc->getOperatorConfig($user->project_id);
        $calc         = new AirtelFeesCalculator($airtelConfig);
        $commission   = (float) $airtelConfig['commission_paynala'];

        $cagnotte                        = new TondoCagnotte();
        $cagnotte->id                    = (string) Str::uuid();
        $cagnotte->project_id            = $user->project_id;
        $cagnotte->user_id               = $user->id;
        $cagnotte->titre                 = $data['titre'];
        $cagnotte->type                  = $type;
        $cagnotte->statut                = 'active';
        // Numéro de retrait : fourni par l'utilisateur ou celui du créateur par défaut
        $numeroRetrait                   = $data['numero_retrait'] ?? $user->numero;
        $cagnotte->numero_retrait        = $numeroRetrait;
        $cagnotte->numero_retrait_masque = $this->maskPhone($numeroRetrait);

        if ($type === 'tontine_periodique') {
            // cashBack = montant net que chaque membre recevra à son tour de bénéficiaire
            $cashBack = (int) $data['montant_par_cycle'];
            $plan     = $calc->plan($cashBack);
            // Remplir tous les champs financiers depuis le plan calculé
            $this->appliquerPlan($cagnotte, $plan, $cashBack, $commission);

            $cagnotte->montant_par_cycle   = $cashBack;
            $cagnotte->periodicite         = $data['periodicite'];
            $cagnotte->intervalle          = (int) ($data['intervalle'] ?? 1);
            $cagnotte->nombre_participants = (int) $data['nombre_participants'];
            $cagnotte->nombre_inscrits     = 0;   // le créateur sera inscrit en dessous
            $cagnotte->reversement_auto    = true; // les tontines reversent automatiquement

            // Stocker le jour de passage selon la périodicité
            if ($data['periodicite'] === 'hebdomadaire') {
                $cagnotte->jour_semaine = $data['jour'] ?? null;   // ex : 'lundi'
                $cagnotte->jour_mois    = null;
            } else {
                $cagnotte->jour_mois    = isset($data['jour']) ? (int) $data['jour'] : null;   // ex : 5, 7, 15
                $cagnotte->jour_semaine = null;
            }

            // Pénalité de retard : active par défaut, montant configurable
            $penaliteActive               = (bool) ($data['penalite_active'] ?? true);
            $cagnotte->penalite_active    = $penaliteActive;
            $cagnotte->penalite_montant   = $penaliteActive ? ((int) ($data['penalite_montant'] ?? 0) ?: null) : null;
            $cagnotte->penalite_frequence = $penaliteActive ? ($data['penalite_frequence'] ?? 'jour') : null;
        } else {
            // Cagnotte ouverte : montant cible optionnel (null = pas de limite)
            $montantCible = isset($data['montant_cible']) && (int) $data['montant_cible'] > 0
                ? (int) $data['montant_cible'] : null;

            $cagnotte->montant_cible       = $montantCible;
            $cagnotte->date_fin            = $data['date_fin'] ?? null;
            $cagnotte->nombre_participants = 0;   // incrémenté à chaque cotisation
            $cagnotte->nombre_inscrits     = 0;
            $cagnotte->reversement_auto    = false;   // le gérant initie le reversement manuellement

            if ($montantCible) {
                // Calculer les frais sur le montant cible pour informer le gérant
                $plan = $calc->plan($montantCible);
                $this->appliquerPlan($cagnotte, $plan, $montantCible, $commission);
            } else {
                // Pas de montant cible → champs financiers non applicables
                $cagnotte->montant_beneficiaire = null;
                $cagnotte->total_a_envoyer      = null;
                $cagnotte->montant_avec_frais   = null;
                $cagnotte->nombre_envois        = null;
                $cagnotte->nombre_splits        = null;
            }
        }

        // Générer une référence numérique unique (6 chiffres, avec boucle de collision)
        $cagnotte->reference     = $this->genererReference();
        $cagnotte->date_creation = now();
        $cagnotte->save();

        // Le créateur est automatiquement inscrit comme premier membre (tontine)
        if ($type === 'tontine_periodique') {
            DB::table('tondo_participants')->insert([
                'id'              => (string) Str::uuid(),
                'project_id'      => $user->project_id,
                'cagnotte_id'     => $cagnotte->id,
                'user_id'         => $user->id,
                'nom'             => $user->nom,
                'prenom'          => $user->prenom,
                'numero_masque'   => $this->maskPhone($user->numero ?? ''),
                'statut_paiement' => 'en_attente',
                'montant_paye'    => 0,
                'created_at'      => now(),
            ]);
            // Note : nombre_inscrits N'EST PAS incrémenté ici — le créateur compte comme +1
            // implicite dans BotService (voir handleCotiserRef, commentaire +1 magic)
        }

        return $cagnotte;
    }

    /**
     * Génère une référence numérique unique à 6 chiffres pour la cagnotte.
     *
     * Boucle jusqu'à 20 tentatives pour éviter les collisions (probabilité faible
     * mais non nulle en début de croissance). Lance une exception si toutes les
     * tentatives échouent (situation théoriquement impossible avant plusieurs millions
     * de cagnottes simultanées).
     *
     * @return string  Référence à 6 chiffres (ex : '315167')
     * @throws \RuntimeException Si 20 collisions consécutives
     */
    public function genererReference(): string
    {
        for ($i = 0; $i < 20; $i++) {
            // Référence aléatoire entre 100000 et 999999 (toujours 6 chiffres)
            $ref = (string) random_int(100000, 999999);
            if (! TondoCagnotte::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        throw new \RuntimeException('Impossible de générer une référence unique après 20 essais.');
    }

    /**
     * Remplit les champs financiers de la cagnotte à partir d'un plan AirtelFeesCalculator.
     *
     * Le plan contient les frais de retrait Airtel (splits, envois, total).
     * On ajoute ensuite la commission Paynala (2 %) sur total_a_envoyer
     * pour obtenir montant_avec_frais (ce que le cotisant devra réellement payer).
     *
     * @param  TondoCagnotte $cagnotte   Modèle à remplir (modifié par référence)
     * @param  array         $plan       Résultat de AirtelFeesCalculator::plan()
     * @param  int           $cashBack   Montant net que reçoit le bénéficiaire (FCFA)
     * @param  float         $commission Taux de commission Paynala (ex : 0.02)
     */
    private function appliquerPlan(TondoCagnotte $cagnotte, array $plan, int $cashBack, float $commission): void
    {
        $totalAEnvoyer                  = $plan['total_a_envoyer'];
        $cagnotte->montant_beneficiaire = $cashBack;          // ce que reçoit le bénéficiaire
        $cagnotte->total_a_envoyer      = $totalAEnvoyer;     // cashBack + frais retrait Airtel
        // montant_avec_frais = total_a_envoyer + commission Paynala (arrondi au-dessus)
        $cagnotte->montant_avec_frais   = (int) ceil($totalAEnvoyer * (1 + $commission));
        $cagnotte->nombre_splits        = $plan['nombre_splits'];   // nb de virements Airtel nécessaires
        $cagnotte->nombre_envois        = $plan['nombre_envois'];   // nb total d'envois
    }

    /**
     * Masque les chiffres centraux d'un numéro pour l'affichage.
     *
     * Identique à CotisationService::maskPhone() — dupliqué volontairement
     * pour éviter une dépendance de service à service (les deux services
     * sont instanciés séparément selon les flows).
     *
     * @param  string $phone  Numéro E.164 ou local
     * @return string         Numéro masqué (ex : +24177****56)
     */
    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
