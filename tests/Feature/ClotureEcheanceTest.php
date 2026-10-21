<?php

namespace Tests\Feature;

use App\Console\Commands\TraiterReversementsAutoCagnottes as Cron;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * À l'échéance, la cagnotte est fermée — sans exception.
 *
 * Le cron de 18 h ne regardait que les cagnottes au reversement automatique
 * et au solde positif. Une cagnotte arrivée au bout de sa durée mais dont le
 * gérant avait coupé le reversement, ou dont le solde était vide, lui
 * échappait : elle serait restée ouverte indéfiniment, ce que la durée
 * maximale est justement censée empêcher.
 */
class ClotureEcheanceTest extends TestCase
{
    private string $aujourdhui;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aujourdhui = now()->timezone('Africa/Libreville')->toDateString();

        Schema::create('tondo_cagnottes', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('reference');
            $t->string('type');
            $t->string('statut');
            $t->string('numero_retrait')->nullable();
            $t->boolean('reversement_auto')->default(true);
            $t->bigInteger('montant_collecte')->default(0);
            $t->date('date_echeance')->nullable();
        });
    }

    private function cagnotte(array $attributs = []): string
    {
        $id = (string) Str::uuid();

        DB::table('tondo_cagnottes')->insert(array_merge([
            'id' => $id, 'project_id' => (string) Str::uuid(),
            'reference' => (string) random_int(100000, 999999),
            'type' => 'cagnotte_ouverte', 'statut' => 'active',
            'numero_retrait' => '077607752',
            'reversement_auto' => true, 'montant_collecte' => 5000,
            'date_echeance' => null,
        ], $attributs));

        return $id;
    }

    /** @return string[] références retenues par la requête du cron */
    private function retenues(): array
    {
        return Cron::eligibles($this->aujourdhui)->pluck('id')->all();
    }

    public function test_le_cas_habituel_reste_retenu(): void
    {
        $id = $this->cagnotte();

        $this->assertContains($id, $this->retenues());
    }

    public function test_une_cagnotte_a_echeance_est_retenue_malgre_le_reversement_coupe(): void
    {
        // Le gérant a désactivé le reversement automatique. Sa cagnotte arrive
        // au bout de sa durée : elle doit quand même se fermer.
        $id = $this->cagnotte([
            'reversement_auto' => false,
            'date_echeance'    => $this->aujourdhui,
        ]);

        $this->assertContains($id, $this->retenues());
    }

    public function test_une_cagnotte_a_echeance_est_retenue_malgre_un_solde_vide(): void
    {
        // Rien à rapatrier, mais c'est sa DURÉE qui expire, pas sa collecte.
        $id = $this->cagnotte([
            'montant_collecte' => 0,
            'date_echeance'    => $this->aujourdhui,
        ]);

        $this->assertContains($id, $this->retenues());
    }

    public function test_une_echeance_encore_lointaine_ne_declenche_rien(): void
    {
        $id = $this->cagnotte([
            'reversement_auto' => false,
            'montant_collecte' => 0,
            'date_echeance'    => now()->addDays(10)->toDateString(),
        ]);

        $this->assertNotContains($id, $this->retenues());
    }

    public function test_une_cagnotte_deja_cloturee_n_est_pas_reprise(): void
    {
        $id = $this->cagnotte([
            'statut'        => 'cloturee',
            'date_echeance' => $this->aujourdhui,
        ]);

        $this->assertNotContains($id, $this->retenues());
    }

    public function test_une_tontine_n_est_jamais_reprise_par_ce_cron(): void
    {
        $id = $this->cagnotte([
            'type'          => 'tontine_periodique',
            'date_echeance' => $this->aujourdhui,
        ]);

        $this->assertNotContains($id, $this->retenues());
    }
}
