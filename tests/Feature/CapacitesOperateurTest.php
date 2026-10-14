<?php

namespace Tests\Feature;

use App\Services\CapacitesOperateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ce que Tonji sait faire, pays par pays.
 *
 * Le cas qui compte n'est pas « la capacité est vraie » mais « on ne sait
 * rien » : un opérateur non décrit ne doit avoir AUCUNE capacité. Supposer
 * l'inverse ferait encaisser de l'argent qu'on ne saurait pas reverser, et
 * l'erreur ne se découvrirait qu'au moment du retrait — trop tard.
 */
class CapacitesOperateurTest extends TestCase
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

    private function config(string $operateur, string $pays, ?array $capacites, ?bool $actif = true): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => $operateur, 'pays' => $pays, 'actif' => $actif,
            'capacites' => $capacites === null ? null : json_encode($capacites),
        ]);
    }

    private function svc(): CapacitesOperateur
    {
        return app(CapacitesOperateur::class);
    }

    public function test_un_operateur_inconnu_n_a_aucune_capacite(): void
    {
        // Rien en base : le défaut sûr est de tout refuser.
        $c = $this->svc()->pour($this->projet, 'orange', 'SN');

        foreach (CapacitesOperateur::CONNUES as $capacite) {
            $this->assertFalse($c[$capacite], "« {$capacite} » ne devrait pas être acquise.");
        }
    }

    public function test_une_ligne_sans_capacites_decrites_n_en_donne_aucune(): void
    {
        // La ligne existe — l'opérateur est connu — mais personne n'a décrit ce
        // qu'il sait faire. C'est le piège : « configuré » n'est pas « capable ».
        $this->config('orange', 'SN', null);

        $this->assertFalse($this->svc()->a($this->projet, 'orange', 'SN', 'payin'));
    }

    public function test_une_description_partielle_ne_donne_que_ce_qu_elle_dit(): void
    {
        // `payout` absent du JSON ne doit pas valoir vrai.
        $this->config('orange', 'SN', ['payin' => true]);

        $c = $this->svc()->pour($this->projet, 'orange', 'SN');

        $this->assertTrue($c['payin']);
        $this->assertFalse($c['payout']);
    }

    public function test_les_capacites_decrites_sont_rendues(): void
    {
        $this->config('airtel', 'GA', [
            'kyc' => true, 'verification_numero' => true,
            'payin' => true, 'payout' => true,
            'frais' => true, 'plafonds' => true,
        ]);

        $this->assertTrue($this->svc()->a($this->projet, 'airtel', 'GA', 'payout'));
    }

    public function test_le_pays_cumule_ses_operateurs(): void
    {
        // Si Orange sait encaisser et Wave reverser, le PAYS sait faire les
        // deux : c'est la bonne maille pour décider si un écran s'ouvre.
        $this->config('orange', 'SN', ['payin' => true]);
        $this->config('wave', 'SN', ['payout' => true]);

        $c = $this->svc()->pourLePays($this->projet, 'SN');

        $this->assertTrue($c['payin']);
        $this->assertTrue($c['payout']);
        $this->assertFalse($c['kyc']);
    }

    public function test_un_operateur_desactive_n_apporte_rien_au_pays(): void
    {
        $this->config('orange', 'SN', ['payin' => true, 'payout' => true], actif: false);

        $this->assertFalse($this->svc()->pourLePays($this->projet, 'SN')['payin']);
    }

    public function test_un_pays_inconnu_n_a_aucune_capacite(): void
    {
        $this->assertFalse($this->svc()->pourLePays($this->projet, 'CI')['payin']);
        $this->assertFalse($this->svc()->pourLePays($this->projet, null)['payin']);
    }

    public function test_un_utilisateur_sans_pays_n_a_aucune_capacite(): void
    {
        // Sept comptes sont dans ce cas en base. Ils ne doivent être rattachés
        // à aucun écosystème, donc ne rien pouvoir faire — plutôt que de
        // tomber par défaut dans celui du Gabon.
        $this->config('airtel', 'GA', ['payin' => true, 'payout' => true]);

        $this->assertFalse($this->svc()->pour($this->projet, 'airtel', null)['payin']);
    }
}
