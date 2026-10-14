<?php

namespace Tests\Feature;

use App\Services\CotisationInterne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cotiser depuis son solde obéit aux mêmes règles que cotiser depuis Airtel.
 *
 * Ce chemin ne contrôlait RIEN — ni verrou de collecte, ni suspension de
 * l'organisateur, ni plafond, ni garde de modération. Il suffisait de passer
 * par son solde pour verser dans une collecte fermée. C'est la quatrième fois
 * que la même omission se produit sur un nouveau canal, d'où le contrôle placé
 * dans le service plutôt que dans le contrôleur : le transfert entre soldes
 * passera par là et en héritera sans qu'on y pense.
 */
class CotisationInterneGardeTest extends TestCase
{
    private string $projectId = 'proj-test';
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('type_compte')->nullable();
            $t->bigInteger('plafond_personnalise')->nullable();
            $t->timestamp('suspendu_at')->nullable();
            // Le pays du compte : les décisions de sortie et de collecte le
            // lisent ici. Sans la colonne, SQLite rend la chaîne « pays »
            // au lieu d'échouer, et le repli n'est jamais exercé.
            $t->string('pays')->nullable();
        });

        Schema::create('tondo_cagnottes', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('reference')->nullable();
            $t->string('titre')->nullable();
            $t->string('type')->nullable();
            $t->string('statut')->default('active');
            $t->string('visibilite')->default('prive');
            $t->string('statut_validation')->nullable();
            $t->boolean('cotisation_bloquee')->default(false);
            $t->bigInteger('montant_collecte')->default(0);
            $t->bigInteger('cumul_cotisations')->default(0);
            $t->timestamp('date_fin')->nullable();
            $t->timestamps();
        });

        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
        });

        // Le service inscrit le cotisant comme participant de la collecte.
        Schema::create('tondo_participants', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('cagnotte_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('nom')->nullable();
            $t->string('prenom')->nullable();
            $t->string('numero')->nullable();
            $t->string('statut_paiement')->nullable();
            $t->timestamps();
        });

        Schema::create('tondo_paiements', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('cagnotte_id')->nullable();
            $t->string('cagnotte_source_id')->nullable();
            $t->string('participant_id')->nullable();
            $t->string('user_id')->nullable();
            $t->bigInteger('montant')->default(0);
            $t->string('canal')->nullable();
            $t->string('trans_id')->nullable();
            $t->boolean('actif')->default(true);
            $t->text('commentaire')->nullable();
            $t->timestamp('date')->nullable();
            $t->timestamps();
        });

        $this->userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->userId, 'type_compte' => 'particulier']);
    }

    private function cagnotte(string $type, int $solde, array $extra = []): object
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert(array_merge([
            'id'               => $id,
            'project_id'       => $this->projectId,
            'user_id'          => $this->userId,
            'reference'        => (string) random_int(100000, 999999),
            'titre'            => 'X',
            'type'             => $type,
            'montant_collecte' => $solde,
        ], $extra));

        return DB::table('tondo_cagnottes')->where('id', $id)->first();
    }

    public function test_une_collecte_verrouillee_refuse_le_mouvement_interne(): void
    {
        $solde = $this->cagnotte('wallet', 50_000);
        $dest  = $this->cagnotte('cagnotte_ouverte', 0, ['cotisation_bloquee' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/suspendues/');

        app(CotisationInterne::class)->executer($solde, $dest, 10_000);
    }

    public function test_un_organisateur_suspendu_refuse_le_mouvement_interne(): void
    {
        $solde = $this->cagnotte('wallet', 50_000);
        $dest  = $this->cagnotte('cagnotte_ouverte', 0);
        DB::table('users')->where('id', $this->userId)->update(['suspendu_at' => now()]);

        $this->expectException(RuntimeException::class);

        app(CotisationInterne::class)->executer($solde, $dest, 10_000);
    }

    public function test_le_plafond_s_applique_au_mouvement_interne(): void
    {
        $solde = $this->cagnotte('wallet', 500_000);
        $dest  = $this->cagnotte('cagnotte_ouverte', 0, ['cumul_cotisations' => 2_500_000]);

        $this->expectException(RuntimeException::class);

        app(CotisationInterne::class)->executer($solde, $dest, 10_000);
    }

    public function test_une_collecte_ouverte_accepte_toujours(): void
    {
        // Le contrôle ne doit pas fermer ce qui marchait : sans lui, ce test
        // passerait aussi — il est là pour attraper un refus trop large.
        $solde = $this->cagnotte('wallet', 50_000);
        $dest  = $this->cagnotte('cagnotte_ouverte', 0);

        $resultat = app(CotisationInterne::class)->executer($solde, $dest, 10_000);

        $this->assertSame(40_000, $resultat['solde_source']);
    }
}
