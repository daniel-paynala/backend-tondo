<?php

namespace Tests\Feature;

use App\Models\TondoProjectConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les capacités déclarées depuis le dashboard arrivent jusqu'en base.
 *
 * Le 2026-10-15, un opérateur créé depuis l'écran « Opérateurs & tarifs »
 * n'apparaissait pas dans l'application. Cause : le formulaire n'envoyait pas
 * les capacités, et `upsert()` ne les écrivait pas. La ligne naissait avec
 * `capacites` à NULL — donc, par la règle « l'absence vaut faux », sans aucune
 * capacité, et aucun réglage ne permettait d'y remédier.
 */
class CapacitesEnregistrementTest extends TestCase
{
    private string $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projet = (string) Str::uuid();

        Schema::create('tondo_project_config', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
            $t->text('tranches')->nullable();
            $t->text('prefixes')->nullable();
            $t->string('indicatif')->nullable();
            $t->string('logo')->nullable();
            $t->float('commission_paynala')->nullable();
            $t->integer('plafond_par_envoi')->nullable();
            $t->integer('plafond_journalier')->nullable();
            $t->timestamps();
        });
    }

    /** @param array<string,bool>|null $capacites */
    private function enregistrer(?array $capacites, string $operateur = 'orange'): TondoProjectConfig
    {
        $data = [
            'commission_paynala' => 0.02,
            'plafond_par_envoi'  => 500000,
            'plafond_journalier' => 2500000,
            'tranches'           => [],
        ];

        if ($capacites !== null) {
            $data['capacites'] = $capacites;
        }

        return TondoProjectConfig::upsert($this->projet, $operateur, 'SN', $data);
    }

    public function test_les_capacites_declarees_sont_ecrites(): void
    {
        $this->enregistrer(['payin' => true, 'payout' => false]);

        $relu = TondoProjectConfig::where('operateur', 'orange')->first();

        $this->assertTrue($relu->capacites['payin']);
        $this->assertFalse($relu->capacites['payout']);
    }

    public function test_une_capacite_retiree_est_bien_retiree(): void
    {
        // Le cas qui compte : décocher doit fermer. Si l'écriture ne gardait
        // que les clés à vrai, une capacité retirée resterait acquise en base.
        $this->enregistrer(['payin' => true, 'payout' => true]);
        $this->enregistrer(['payin' => true, 'payout' => false]);

        $relu = TondoProjectConfig::where('operateur', 'orange')->first();

        $this->assertFalse($relu->capacites['payout']);
    }

    public function test_un_appelant_qui_ne_parle_pas_de_capacites_ne_les_efface_pas(): void
    {
        // Les tarifs se modifient par d'autres chemins que l'écran des
        // capacités : ne pas en parler ne doit pas valoir « aucune ».
        $this->enregistrer(['payin' => true, 'payout' => true]);
        $this->enregistrer(null);

        $relu = TondoProjectConfig::where('operateur', 'orange')->first();

        $this->assertTrue($relu->capacites['payout']);
    }
}
