<?php

namespace Tests\Feature;

use App\Services\PlafondCollecte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sur quelle valeur le plafond s'applique.
 *
 * Le cœur du sujet : il lisait le SOLDE, qui baisse à chaque reversement. On
 * collectait donc 2,5 M, on transférait, et on recollectait 2,5 M — sans fin.
 * Ces cas-là décrivent le contournement et sa fermeture ; s'ils tombent, le
 * plafond redevient décoratif sans que rien d'autre ne casse.
 */
class PlafondCollecteTest extends TestCase
{
    private string $userId;
    private array $config = ['plafond_cagnotte_particulier' => 2_500_000];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('type_compte')->nullable();
            $t->bigInteger('plafond_personnalise')->nullable();
        });

        $this->userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->userId, 'type_compte' => 'particulier']);
    }

    /** Une collecte en mémoire — le service ne lit que ses champs. */
    private function collecte(string $type, int $solde, int $cumul): object
    {
        return (object) [
            'user_id'           => $this->userId,
            'type'              => $type,
            'montant_collecte'  => $solde,
            'cumul_cotisations' => $cumul,
        ];
    }

    public function test_une_cagnotte_ouverte_est_plafonnee_sur_le_cumul(): void
    {
        // Solde à zéro — tout a été reversé — mais 2,5 M déjà collectés.
        // C'est LE cas du contournement : avant, cette cotisation passait.
        $c = $this->collecte('cagnotte_ouverte', solde: 0, cumul: 2_500_000);

        $this->assertNotNull(
            app(PlafondCollecte::class)->refus($c, 1_000, $this->config),
            'Vider le solde par un reversement rouvrirait le plafond.',
        );
    }

    public function test_sous_le_plafond_la_cotisation_passe(): void
    {
        $c = $this->collecte('cagnotte_ouverte', solde: 0, cumul: 2_400_000);

        $this->assertNull(app(PlafondCollecte::class)->refus($c, 100_000, $this->config));
    }

    public function test_une_tontine_reste_plafonnee_sur_son_solde(): void
    {
        // Douze cycles de 500 000 déjà passés : le cumul dépasse largement le
        // plafond, mais elle n'a jamais détenu plus d'un cycle à la fois. Un
        // plafond à vie la bloquerait pour toujours.
        $c = $this->collecte('tontine_periodique', solde: 0, cumul: 6_000_000);

        $this->assertNull(app(PlafondCollecte::class)->refus($c, 500_000, $this->config));
    }

    public function test_une_tontine_trop_chargee_est_refusee(): void
    {
        $c = $this->collecte('tontine_periodique', solde: 2_500_000, cumul: 2_500_000);

        $this->assertNotNull(app(PlafondCollecte::class)->refus($c, 1_000, $this->config));
    }

    public function test_un_solde_personnel_n_est_pas_plafonne_a_vie(): void
    {
        // Régression introduite en passant le plafond sur le cumul : un solde
        // ayant vu passer 3 M au fil de l'année n'aurait plus jamais pu être
        // rechargé, même à zéro. Un solde circule, il n'accumule pas.
        $c = $this->collecte('wallet', solde: 0, cumul: 3_000_000);

        $this->assertNull(app(PlafondCollecte::class)->refus($c, 50_000, $this->config));
    }

    public function test_un_solde_personnel_trop_charge_est_refuse(): void
    {
        // Ce qu'on borne pour lui, c'est ce qu'il détient.
        $c = $this->collecte('wallet', solde: 2_500_000, cumul: 2_500_000);

        $this->assertNotNull(app(PlafondCollecte::class)->refus($c, 1_000, $this->config));
    }

    public function test_le_message_dit_ce_qu_il_reste(): void
    {
        // « a atteint son plafond de 2 500 000 » était faux à 2 450 000, et ne
        // disait pas quel montant passerait.
        $c = $this->collecte('cagnotte_ouverte', solde: 2_450_000, cumul: 2_450_000);

        $refus = app(PlafondCollecte::class)->refus($c, 100_000, $this->config);

        $this->assertStringContainsString('50 000', $refus);
        $this->assertStringContainsString('reste', $refus);
    }

    public function test_au_plafond_exact_le_message_le_dit(): void
    {
        $c = $this->collecte('cagnotte_ouverte', solde: 0, cumul: 2_500_000);

        $this->assertStringContainsString(
            'atteint son plafond',
            app(PlafondCollecte::class)->refus($c, 1_000, $this->config),
        );
    }

    public function test_l_assiette_est_celle_que_les_clients_affichent(): void
    {
        // La barre de progression doit suivre la même valeur que le contrôle,
        // sinon elle promet ce que le serveur refusera.
        $svc = app(PlafondCollecte::class);

        $this->assertSame(
            2_400_000,
            $svc->assiette($this->collecte('cagnotte_ouverte', solde: 10, cumul: 2_400_000)),
        );
        $this->assertSame(
            10,
            $svc->assiette($this->collecte('tontine_periodique', solde: 10, cumul: 2_400_000)),
        );
    }
}
