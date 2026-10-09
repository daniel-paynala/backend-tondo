<?php

namespace Tests\Unit;

use App\Services\FraisSortie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La règle de gratuité, éprouvée sur ce qu'elle promet : **au pire une
 * franchise sortie gratuitement par organisateur et par mois**, quel que soit
 * le nombre de cagnottes ou de virements.
 */
class FraisSortieTest extends TestCase
{
    private const FRANCHISE = 50000;
    private const PLAFOND   = 5000;
    private const TAUX      = 0.02;

    private string $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('airtel.franchise_retrait', self::FRANCHISE);
        config()->set('airtel.plafond_frais_retrait', self::PLAFOND);
        config()->set('airtel.frais_retrait', [
            'cagnotte' => ['particulier' => self::TAUX, 'association' => self::TAUX],
            'tontine'  => ['particulier' => self::TAUX, 'association' => self::TAUX],
        ]);

        // Table vide : le service lit la config projet, ne trouve rien, et
        // retombe sur le fichier réglé ci-dessus.
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
        });
        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('type_compte')->nullable();
        });
        Schema::create('tondo_cagnottes', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->uuid('user_id');
            $t->string('type')->default('cagnotte_ouverte');
            $t->bigInteger('cumul_cotisations')->default(0);
        });
        Schema::create('tondo_paiements', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('cagnotte_id');
            $t->integer('montant');
            $t->boolean('actif')->default(true);
            $t->timestamp('date')->nullable();
        });
        Schema::create('tondo_payout', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('cagnotte_id');
            $t->string('statut');
            $t->integer('frais_attendus')->nullable();
            $t->timestamp('date_creation')->nullable();
        });

        $this->gerant = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->gerant, 'type_compte' => 'particulier']);
    }

    /** Crée une cagnotte ayant COLLECTÉ [$collecte], cotisations comprises. */
    private function cagnotte(int $collecte): object
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => 'p', 'user_id' => $this->gerant,
            'type' => 'cagnotte_ouverte', 'cumul_cotisations' => $collecte,
        ]);
        if ($collecte > 0) {
            DB::table('tondo_paiements')->insert([
                'id' => (string) Str::uuid(), 'cagnotte_id' => $id,
                'montant' => $collecte, 'actif' => true, 'date' => now(),
            ]);
        }

        return DB::table('tondo_cagnottes')->where('id', $id)->first();
    }

    /** Rejoue ce que fait un vrai reversement : il laisse sa trace. */
    private function reverser(object $cagnotte, int $montant): array
    {
        $frais = app(FraisSortie::class)->pour($cagnotte, $montant);

        DB::table('tondo_payout')->insert([
            'id' => (string) Str::uuid(), 'cagnotte_id' => $cagnotte->id,
            'statut' => 'succes', 'frais_attendus' => $frais['frais'],
            'date_creation' => now(),
        ]);

        return $frais;
    }

    public function test_une_petite_cagnotte_sort_gratuitement(): void
    {
        $frais = $this->reverser($this->cagnotte(40000), 40000);

        $this->assertTrue($frais['gratuit']);
        $this->assertSame(0, $frais['frais']);
    }

    public function test_decouper_le_virement_ne_sert_a_rien(): void
    {
        // La règle regarde ce que la cagnotte a REÇU, pas ce qu'on en sort :
        // une tranche sous la franchise ne rachète pas la gratuité.
        $grosse = $this->cagnotte(200000);

        $frais = app(FraisSortie::class)->pour($grosse, 49999);

        $this->assertFalse($frais['gratuit']);
        $this->assertSame(1000, $frais['frais']); // 2 % de 49 999
    }

    public function test_le_plafond_borne_le_prelevement(): void
    {
        $frais = app(FraisSortie::class)->pour($this->cagnotte(500000), 500000);

        $this->assertSame(self::PLAFOND, $frais['frais']); // 2 % feraient 10 000
    }

    public function test_dix_petites_cagnottes_ne_donnent_quune_gratuite(): void
    {
        $gratuites = 0;
        $sortiGratuitement = 0;

        for ($i = 0; $i < 10; $i++) {
            $cagnotte = $this->cagnotte(49999);
            $frais    = $this->reverser($cagnotte, 49999);
            if ($frais['gratuit']) {
                $gratuites++;
                $sortiGratuitement += 49999;
            }
        }

        $this->assertSame(1, $gratuites, 'la règle du mois n\'a pas tenu');
        $this->assertLessThanOrEqual(self::FRANCHISE, $sortiGratuitement);
    }

    public function test_plusieurs_cagnottes_sous_la_franchise_restent_gratuites(): void
    {
        // Cinq cagnottes de 10 000 : le cumul du mois atteint la franchise sans
        // la dépasser, donc tout est gratuit — et le total sorti gratuitement
        // vaut exactement une franchise. C'est l'invariant, pas une faille.
        $sortiGratuitement = 0;
        for ($i = 0; $i < 5; $i++) {
            $frais = $this->reverser($this->cagnotte(10000), 10000);
            $this->assertTrue($frais['gratuit'], "cagnotte $i");
            $sortiGratuitement += 10000;
        }

        $this->assertSame(self::FRANCHISE, $sortiGratuitement);

        // La sixième fait basculer le cumul du mois au-delà de la franchise, et
        // une gratuité a déjà été consommée : elle paie.
        $this->assertFalse($this->reverser($this->cagnotte(10000), 10000)['gratuit']);
    }

    public function test_sans_taux_regle_rien_n_est_preleve(): void
    {
        config()->set('airtel.frais_retrait', [
            'cagnotte' => ['particulier' => 0, 'association' => 0],
        ]);

        $frais = app(FraisSortie::class)->pour($this->cagnotte(500000), 500000);

        $this->assertSame(0, $frais['frais']);
        $this->assertSame('taux_nul', $frais['motif']);
    }

    public function test_les_doublons_neutralises_ne_comptent_pas(): void
    {
        // Le correctif du 2026-08-31 désactive les lignes en double. Les
        // compter rapprocherait du seuil sans argent réel derrière.
        $cagnotte = $this->cagnotte(40000);
        DB::table('tondo_paiements')->insert([
            'id' => (string) Str::uuid(), 'cagnotte_id' => $cagnotte->id,
            'montant' => 40000, 'actif' => false, 'date' => now(),
        ]);

        $this->assertTrue(app(FraisSortie::class)->pour($cagnotte, 40000)['gratuit']);
    }
}
