<?php

namespace Tests\Feature;

use App\Services\NumeroTelephone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les formats de numéro, déduits de la configuration plutôt que gravés.
 *
 * « +241 » apparaissait en dur à quatre endroits, dont deux copies de la même
 * fonction, et la validation d'un bénéficiaire imposait neuf chiffres —
 * la longueur gabonaise. Tant que ces formes restaient figées, aucun numéro
 * étranger ne franchissait la validation, même avec toutes les capacités
 * déclarées pour son pays.
 */
class NumeroTelephoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->string('indicatif')->nullable();
        });

        foreach ([['241', 'GA'], ['221', 'SN'], ['22', 'XX']] as [$ind, $pays]) {
            DB::table('tondo_project_config')->insert([
                'id' => (string) Str::uuid(), 'project_id' => 'p',
                'operateur' => 'op' . $pays, 'pays' => $pays, 'indicatif' => $ind,
            ]);
        }
    }

    private function svc(): NumeroTelephone
    {
        return app(NumeroTelephone::class);
    }

    public function test_une_saisie_nationale_prend_l_indicatif_par_defaut(): void
    {
        // « 077 73 06 34 » tapé au dashboard, pays d'exploitation gabonais.
        // Le zéro de tête du numéro national disparaît en E.164 — c'est le
        // comportement d'origine, conservé tel quel par le regroupement.
        $this->assertSame('+24177730634', $this->svc()->versE164('077 73 06 34', '241'));
    }

    public function test_une_saisie_qui_porte_son_indicatif_est_respectee(): void
    {
        // Le point qui compte : forcer le défaut réécrirait un numéro étranger
        // en numéro local, et l'argent partirait au mauvais endroit.
        $this->assertSame('+221770000000', $this->svc()->versE164('+221 77 000 00 00', '241'));
    }

    public function test_le_pays_se_deduit_de_l_indicatif(): void
    {
        $this->assertSame('GA', $this->svc()->paysDe('+24177730634'));
        $this->assertSame('SN', $this->svc()->paysDe('+221770000000'));
    }

    public function test_un_indicatif_court_ne_vole_pas_un_plus_long(): void
    {
        // « 22 » est déclaré aussi : testé avant « 221 », il rattacherait tous
        // les numéros sénégalais au mauvais pays. L'ordre de test est donc du
        // plus long au plus court.
        $this->assertSame('SN', $this->svc()->paysDe('+221770000000'));
    }

    public function test_un_indicatif_inconnu_ne_donne_pas_de_pays(): void
    {
        $this->assertNull($this->svc()->paysDe('+33612345678'));
        $this->assertFalse($this->svc()->estConnu('+33612345678'));
    }

    public function test_la_forme_nationale_retire_l_indicatif(): void
    {
        // Celle qu'attendent les API d'opérateur.
        $this->assertSame('077730634', $this->svc()->versLocal('+24177730634'));
        $this->assertSame('0770000000', $this->svc()->versLocal('+221770000000'));
    }

    public function test_un_numero_inconnu_n_est_pas_inventé(): void
    {
        // Deviner son indicatif fabriquerait un numéro qui n'existe pas.
        $this->assertSame('33612345678', $this->svc()->versLocal('+33612345678'));
    }

    public function test_sans_configuration_le_defaut_s_applique(): void
    {
        // Installation neuve ou contexte de test : une table absente ne doit
        // pas lever, le comportement retombe sur l'indicatif fourni.
        Schema::drop('tondo_project_config');

        $this->assertSame('+24177730634', app(NumeroTelephone::class)->versE164('077730634', '241'));
    }
}
