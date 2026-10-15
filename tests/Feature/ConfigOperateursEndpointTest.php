<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ConfigController;
use App\Services\TondoConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'écran « Opérateurs & tarifs » du dashboard.
 *
 * Il est tombé en 500 le 2026-10-15 : la fermeture qui enrichit chaque ligne
 * utilisait `$request` sans le capturer. PHP n'hérite rien du contexte, et
 * aucun test ne touchait cet endpoint — la suite était verte pendant que la
 * page était morte.
 *
 * Ce test appelle la méthode directement, avec le service remplacé : ce qu'on
 * veut tenir, c'est que la fermeture s'exécute réellement sur plusieurs
 * lignes, pas la pile HTTP.
 */
class ConfigOperateursEndpointTest extends TestCase
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
        });

        // Un opérateur complet, un opérateur qui ne sait que recevoir.
        $this->config('airtel', 'GA', ['payin' => true, 'payout' => true]);
        $this->config('moov', 'GA', ['payin' => true, 'payout' => false]);

        // Le service est remplacé : seules les lignes comptent ici, pas la
        // façon dont elles sont lues.
        $this->app->bind(TondoConfigService::class, function () {
            return new class extends TondoConfigService
            {
                public function __construct() {}

                public function listOperatorConfigs(string $projectId): Collection
                {
                    return collect([
                        ['operateur' => 'airtel', 'pays' => 'GA'],
                        ['operateur' => 'moov',   'pays' => 'GA'],
                    ]);
                }
            };
        });
    }

    private function config(string $operateur, string $pays, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => $operateur, 'pays' => $pays, 'actif' => true,
            'capacites' => json_encode($capacites),
        ]);
    }

    private function appeler(): array
    {
        $requete = Request::create('/api/admin/config', 'GET');
        $requete->setUserResolver(fn () => (object) ['project_id' => $this->projet]);

        $reponse = app(ConfigController::class)->index($requete);

        return json_decode($reponse->getContent(), true);
    }

    public function test_la_liste_des_operateurs_repond(): void
    {
        // Le test qui manquait : la fermeture s'exécute, donc la page vit.
        $corps = $this->appeler();

        $this->assertCount(2, $corps['operateurs']);
    }

    public function test_chaque_ligne_porte_ses_capacites(): void
    {
        $corps = $this->appeler();

        $this->assertTrue($corps['operateurs'][0]['capacites']['payin']);
        $this->assertTrue($corps['operateurs'][0]['capacites']['payout']);
    }

    public function test_savoir_encaisser_sans_savoir_reverser_n_est_pas_une_integration(): void
    {
        // Collecter sans pouvoir rendre est pire que ne rien faire : le badge
        // ne doit pas dire « effective » pour un demi-service.
        $corps = $this->appeler();

        $moov = collect($corps['operateurs'])->firstWhere('operateur', 'moov');

        $this->assertSame('en_attente', $moov['integration_paiement']);
    }
}
