<?php

namespace Tests\Unit;

use App\Services\CotisationInterne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Le mouvement interne touche au chemin de l'argent : il débite un contenant
 * et en crédite un autre. Ce qui doit tenir, c'est l'INVARIANT — la somme des
 * soldes ne change pas, parce que rien n'entre ni ne sort de Tonji.
 */
class CotisationInterneTest extends TestCase
{
    private string $gerant;
    private object $solde;
    private object $collecte;

    protected function setUp(): void
    {
        parent::setUp();

        // Un mouvement interne obéit désormais aux règles de collecte : le
        // service lit le gérant de la destination et la config du projet.
        // Tables vides : rien n'est verrouillé, rien n'est suspendu, ce qui
        // est l'état qu'attendent les cas ci-dessous.
        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('type_compte')->nullable();
            $t->bigInteger('plafond_personnalise')->nullable();
            $t->timestamp('suspendu_at')->nullable();
        });
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
        });
        Schema::create('tondo_cagnottes', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->uuid('user_id');
            $t->string('reference');
            $t->string('titre');
            $t->string('type');
            $t->string('statut')->default('active');
            $t->string('visibilite')->default('prive');
            $t->string('statut_validation')->nullable();
            $t->boolean('cotisation_bloquee')->default(false);
            $t->timestamp('date_fin')->nullable();
            $t->bigInteger('montant_collecte')->default(0);
            $t->bigInteger('cumul_cotisations')->default(0);
            $t->timestamps();
        });
        Schema::create('tondo_participants', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('cagnotte_id');
            $t->uuid('user_id')->nullable();
            $t->string('statut_paiement')->nullable();
            $t->bigInteger('montant_paye')->default(0);
            $t->timestamp('date_dernier_paiement')->nullable();
        });
        Schema::create('tondo_paiements', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->uuid('cagnotte_id');
            $t->uuid('participant_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('canal')->nullable();
            $t->string('commentaire')->nullable();
            $t->string('trans_id')->nullable();
            $t->uuid('cagnotte_source_id')->nullable();
            $t->bigInteger('montant');
            $t->timestamp('date')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->gerant = (string) Str::uuid();
        $this->solde    = $this->creer('wallet', 'Mon solde', '100001', 20000);
        $this->collecte = $this->creer('cagnotte_ouverte', 'Anniversaire', '742416', 0);
    }

    private function creer(string $type, string $titre, string $ref, int $solde): object
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => 'p', 'user_id' => $this->gerant,
            'reference' => $ref, 'titre' => $titre, 'type' => $type,
            'statut' => 'active', 'montant_collecte' => $solde,
            'cumul_cotisations' => $solde,
        ]);

        return DB::table('tondo_cagnottes')->where('id', $id)->first();
    }

    private function solde(string $id): int
    {
        return (int) DB::table('tondo_cagnottes')->where('id', $id)->value('montant_collecte');
    }

    public function test_l_argent_change_de_contenant_sans_se_creer(): void
    {
        $avant = $this->solde($this->solde->id) + $this->solde($this->collecte->id);

        app(CotisationInterne::class)
            ->executer($this->solde, $this->collecte, 5000);

        $this->assertSame(15000, $this->solde($this->solde->id));
        $this->assertSame(5000, $this->solde($this->collecte->id));

        // L'invariant : la somme des soldes ne bouge pas. C'est ce qui garantit
        // qu'elle continue d'égaler ce que Paynala détient pour nous.
        $this->assertSame(
            $avant,
            $this->solde($this->solde->id) + $this->solde($this->collecte->id),
        );
    }

    public function test_la_ligne_dit_d_ou_vient_l_argent(): void
    {
        app(CotisationInterne::class)->executer($this->solde, $this->collecte, 5000);

        $ligne = DB::table('tondo_paiements')->first();

        $this->assertSame($this->collecte->id, $ligne->cagnotte_id);
        $this->assertSame($this->solde->id, $ligne->cagnotte_source_id);
        $this->assertStringStartsWith('TONJIINTERNE', $ligne->trans_id);
    }

    public function test_le_cumul_de_la_destination_monte_avec_son_solde(): void
    {
        // L'argent reçu est une cotisation comme une autre pour la collecte qui
        // l'encaisse : il compte pour sa gratuité de reversement.
        app(CotisationInterne::class)->executer($this->solde, $this->collecte, 5000);

        $this->assertSame(
            5000,
            (int) DB::table('tondo_cagnottes')
                ->where('id', $this->collecte->id)
                ->value('cumul_cotisations'),
        );
    }

    public function test_un_solde_insuffisant_ne_deplace_rien(): void
    {
        try {
            app(CotisationInterne::class)
                ->executer($this->solde, $this->collecte, 50000);
            $this->fail('le service aurait dû refuser');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('insuffisant', $e->getMessage());
        }

        $this->assertSame(20000, $this->solde($this->solde->id));
        $this->assertSame(0, $this->solde($this->collecte->id));
        $this->assertSame(0, DB::table('tondo_paiements')->count());
    }

    public function test_deux_cotisations_concurrentes_ne_vident_pas_deux_fois(): void
    {
        // La garde vit dans le WHERE du débit, pas dans un contrôle préalable :
        // la seconde requête n'affecte aucune ligne et s'arrête. Ici on la
        // rejoue séquentiellement, ce qui suffit à éprouver la condition.
        app(CotisationInterne::class)->executer($this->solde, $this->collecte, 15000);

        $soldeRelu = DB::table('tondo_cagnottes')->where('id', $this->solde->id)->first();

        $this->expectException(RuntimeException::class);
        app(CotisationInterne::class)->executer($soldeRelu, $this->collecte, 15000);
    }

    public function test_on_ne_cotise_pas_a_son_propre_solde(): void
    {
        $this->expectException(RuntimeException::class);
        app(CotisationInterne::class)->executer($this->solde, $this->solde, 1000);
    }

    public function test_le_minimum_est_respecte(): void
    {
        $this->expectException(RuntimeException::class);
        app(CotisationInterne::class)->executer($this->solde, $this->collecte, 50);
    }
}
