<?php

namespace Tests\Feature;

use App\Services\CapacitesOperateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * On ne crée pas une collecte dans un pays qui ne sait pas reverser.
 *
 * Le contrôle est à la CRÉATION, pas au retrait, et c'est tout l'enjeu : une
 * collecte qui encaisse sans pouvoir reverser piège l'argent de ses cotisants.
 * Le refus arriverait au moment du retrait, quand le bénéficiaire a déjà
 * collecté et attend son dû — et là, on ne peut plus revenir en arrière.
 */
class CreationPaysTest extends TestCase
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

    private function refus(?string $pays): ?string
    {
        return app(CapacitesOperateur::class)->refusPourCreation($this->projet, $pays);
    }

    public function test_un_pays_qui_sait_reverser_accepte(): void
    {
        $this->pays('GA', ['payin' => true, 'payout' => true]);

        $this->assertNull($this->refus('GA'));
    }

    public function test_un_pays_sans_decaissement_refuse(): void
    {
        // Le cas qui justifie tout : encaisser sans reverser piège l'argent.
        $this->pays('SN', ['payin' => true, 'payout' => false]);

        $refus = $this->refus('SN');

        $this->assertNotNull($refus);
        $this->assertStringContainsString('pas encore disponibles', $refus);
    }

    public function test_un_pays_inconnu_refuse(): void
    {
        $this->assertNotNull($this->refus('CI'));
    }

    public function test_un_compte_sans_pays_refuse_et_oriente(): void
    {
        // Deux comptes sont dans ce cas en base. Les rattacher au Gabon par
        // défaut les ferait entrer dans le mauvais écosystème.
        $this->pays('GA', ['payin' => true, 'payout' => true]);

        $refus = $this->refus(null);

        $this->assertNotNull($refus);
        $this->assertStringContainsString('support', $refus);
    }

    public function test_l_encaissement_seul_ne_suffit_pas(): void
    {
        // Une collecte sans encaissement local est inutile mais inoffensive ;
        // sans décaissement, elle est un piège. C'est `payout` qui décide.
        $this->pays('SN', ['payin' => false, 'payout' => true]);

        $this->assertNull($this->refus('SN'));
    }

    public function test_le_motif_technique_ne_fuite_pas_vers_l_utilisateur(): void
    {
        $this->pays('SN', ['payout' => false]);
        $refus = $this->refus('SN');

        foreach (['payout', 'API', 'opérateur', 'capacit'] as $mot) {
            $this->assertStringNotContainsString($mot, $refus);
        }
    }
}
