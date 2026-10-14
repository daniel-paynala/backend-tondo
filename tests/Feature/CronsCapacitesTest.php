<?php

namespace Tests\Feature;

use App\Services\CapacitesOperateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les mécanismes automatiques savent qu'un pays peut ne pas savoir reverser.
 *
 * Sans ce contrôle, une collecte d'un pays sans décaissement serait retentée
 * CHAQUE SOIR par le cron de 18 h, indéfiniment, avec une alerte à chaque
 * échec — un bruit quotidien qui finirait par masquer les vraies pannes. Et
 * pour une tontine c'est pire : c'est le tour d'un membre, il attend son
 * argent à une date connue de tous.
 *
 * Le test porte sur la décision, pas sur l'exécution du cron : c'est la
 * condition d'exclusion qui doit être juste, et elle est partagée.
 */
class CronsCapacitesTest extends TestCase
{
    private string $projet = 'proj-test';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
        });
    }

    private function pays(string $pays, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => 'op_' . strtolower($pays), 'pays' => $pays,
            'actif' => true, 'capacites' => json_encode($capacites),
        ]);
    }

    private function peutReverser(?string $pays): bool
    {
        return app(CapacitesOperateur::class)
            ->pourLePays($this->projet, $pays)['payout'];
    }

    public function test_un_pays_qui_reverse_est_traite(): void
    {
        $this->pays('GA', ['payout' => true]);

        $this->assertTrue($this->peutReverser('GA'));
    }

    public function test_un_pays_sans_decaissement_est_ecarte(): void
    {
        $this->pays('SN', ['payin' => true, 'payout' => false]);

        $this->assertFalse($this->peutReverser('SN'));
    }

    public function test_une_collecte_sans_pays_est_ecartee(): void
    {
        // Une collecte d'avant le rattachement. Le cron ne doit pas tenter de
        // reverser sans savoir où : il la signale et passe.
        $this->pays('GA', ['payout' => true]);

        $this->assertFalse($this->peutReverser(null));
    }

    public function test_retirer_la_capacite_ferme_le_cron(): void
    {
        // Le verrou de création empêche désormais ces collectes d'exister,
        // mais une capacité peut être retirée APRÈS coup — panne d'agrégateur,
        // contrat suspendu. Le cron doit suivre, pas s'acharner.
        $this->pays('GA', ['payout' => true]);
        $this->assertTrue($this->peutReverser('GA'));

        DB::table('tondo_project_config')
            ->where('pays', 'GA')
            ->update(['capacites' => json_encode(['payout' => false])]);

        // Une instance neuve : le service mémorise ses lectures le temps d'une
        // exécution, ce qui est voulu — un cron lit une fois par passage.
        $this->assertFalse(
            app()->makeWith(CapacitesOperateur::class, [])
                ->pourLePays($this->projet, 'GA')['payout'],
        );
    }
}
