<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\AirtelFeesCalculator;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreerCagnotteService
{
    public function __construct(private TondoConfigService $configSvc) {}

    public function creer(array $data, TondoUser $user): TondoCagnotte
    {
        $type         = $data['type'];
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
        $numeroRetrait                   = $data['numero_retrait'] ?? $user->numero;
        $cagnotte->numero_retrait        = $numeroRetrait;
        $cagnotte->numero_retrait_masque = $this->maskPhone($numeroRetrait);

        if ($type === 'tontine_periodique') {
            $cashBack = (int) $data['montant_par_cycle'];
            $plan     = $calc->plan($cashBack);
            $this->appliquerPlan($cagnotte, $plan, $cashBack, $commission);

            $cagnotte->montant_par_cycle   = $cashBack;
            $cagnotte->periodicite         = $data['periodicite'];
            $cagnotte->intervalle          = (int) ($data['intervalle'] ?? 1);
            $cagnotte->nombre_participants = (int) $data['nombre_participants'];
            $cagnotte->nombre_inscrits     = 0;
            $cagnotte->reversement_auto    = true;

            if ($data['periodicite'] === 'hebdomadaire') {
                $cagnotte->jour_semaine = $data['jour'] ?? null;
                $cagnotte->jour_mois    = null;
            } else {
                $cagnotte->jour_mois    = isset($data['jour']) ? (int) $data['jour'] : null;
                $cagnotte->jour_semaine = null;
            }

            $penaliteActive               = (bool) ($data['penalite_active'] ?? true);
            $cagnotte->penalite_active    = $penaliteActive;
            $cagnotte->penalite_montant   = $penaliteActive ? ((int) ($data['penalite_montant'] ?? 0) ?: null) : null;
            $cagnotte->penalite_frequence = $penaliteActive ? ($data['penalite_frequence'] ?? 'jour') : null;
        } else {
            $montantCible = isset($data['montant_cible']) && (int) $data['montant_cible'] > 0
                ? (int) $data['montant_cible'] : null;

            $cagnotte->montant_cible       = $montantCible;
            $cagnotte->date_fin            = $data['date_fin'] ?? null;
            $cagnotte->nombre_participants = 0;
            $cagnotte->nombre_inscrits     = 0;
            $cagnotte->reversement_auto    = false;

            if ($montantCible) {
                $plan = $calc->plan($montantCible);
                $this->appliquerPlan($cagnotte, $plan, $montantCible, $commission);
            } else {
                $cagnotte->montant_beneficiaire = null;
                $cagnotte->total_a_envoyer      = null;
                $cagnotte->montant_avec_frais   = null;
                $cagnotte->nombre_envois        = null;
                $cagnotte->nombre_splits        = null;
            }
        }

        $cagnotte->reference     = $this->genererReference();
        $cagnotte->date_creation = now();
        $cagnotte->save();

        // Le créateur est automatiquement inscrit comme premier participant (tontine)
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
        }

        return $cagnotte;
    }

    public function genererReference(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $ref = (string) random_int(100000, 999999);
            if (! TondoCagnotte::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        throw new \RuntimeException('Impossible de générer une référence unique après 20 essais.');
    }

    private function appliquerPlan(TondoCagnotte $cagnotte, array $plan, int $cashBack, float $commission): void
    {
        $totalAEnvoyer                  = $plan['total_a_envoyer'];
        $cagnotte->montant_beneficiaire = $cashBack;
        $cagnotte->total_a_envoyer      = $totalAEnvoyer;
        $cagnotte->montant_avec_frais   = (int) ceil($totalAEnvoyer * (1 + $commission));
        $cagnotte->nombre_splits        = $plan['nombre_splits'];
        $cagnotte->nombre_envois        = $plan['nombre_envois'];
    }

    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
