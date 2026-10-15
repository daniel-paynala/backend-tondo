<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\AssociationController;
use App\Services\PaynalaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le type de compte s'enregistre là où aucun KYC ne peut le confirmer.
 *
 * Le 2026-10-15, un compte sénégalais créé sur la recette restait sans
 * `type_compte`. `POST /compte/type-compte` interrogeait le KYC Airtel POUR
 * TOUT LE MONDE : sur un numéro +221 Airtel ne connaît rien, rendait `null`,
 * et l'appel repartait en 422 « vérification temporairement indisponible ».
 * L'application avale cette erreur en silence — le gate du routeur reposait
 * donc la question du type de compte à quelqu'un qui venait d'y répondre.
 */
class TypeCompteSansKycTest extends TestCase
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
            $t->string('indicatif')->nullable();
            $t->text('prefixes')->nullable();
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
        });

        // Le KYC ne doit JAMAIS être appelé sur le chemin sans capacité : s'il
        // l'est, ce faux le fait échouer bruyamment plutôt qu'en silence.
        $this->app->bind(PaynalaPaymentService::class, fn () => new class extends PaynalaPaymentService
        {
            public function __construct() {}

            public function checkKycData(string $msisdn): ?array
            {
                throw new \RuntimeException('Le KYC ne doit pas être interrogé ici.');
            }
        });
    }

    private function operateur(string $op, string $pays, string $indicatif, array $prefixes, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => $op, 'pays' => $pays, 'indicatif' => $indicatif,
            'prefixes' => json_encode($prefixes), 'actif' => true,
            'capacites' => json_encode($capacites),
        ]);
    }

    /** Un utilisateur minimal, suffisant pour la méthode testée. */
    private function utilisateur(string $numero): object
    {
        return new class($this->projet, $numero) {
            public ?string $type_compte = null;
            public bool $enregistre = false;

            public function __construct(public string $project_id, public string $numero) {}

            public function save(): void { $this->enregistre = true; }

            public function toApiArray(): array
            {
                return ['type_compte' => $this->type_compte];
            }
        };
    }

    public function test_sans_kyc_la_declaration_est_retenue(): void
    {
        $this->operateur('orange', 'SN', '221', ['77'], ['kyc' => false]);

        $user    = $this->utilisateur('+221777870258');
        $requete = Request::create('/api/mobile/compte/type-compte', 'POST', [
            'type_compte' => 'particulier',
        ]);
        $requete->setUserResolver(fn () => $user);

        $reponse = app(AssociationController::class)->definirTypeCompte($requete);

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('particulier', $user->type_compte);
        $this->assertTrue($user->enregistre, 'Le choix doit être écrit, pas seulement accepté.');
    }

    public function test_une_association_declaree_est_retenue_aussi(): void
    {
        // Sans KYC on ne peut pas contredire la déclaration : la refuser
        // fermerait la création de comptes d'association dans ces pays.
        $this->operateur('orange', 'SN', '221', ['77'], ['kyc' => false]);

        $user    = $this->utilisateur('+221777870258');
        $requete = Request::create('/api/mobile/compte/type-compte', 'POST', [
            'type_compte' => 'association',
        ]);
        $requete->setUserResolver(fn () => $user);

        app(AssociationController::class)->definirTypeCompte($requete);

        $this->assertSame('association', $user->type_compte);
    }

    public function test_avec_kyc_le_controle_croise_reste_obligatoire(): void
    {
        // La garantie inverse : là où la vérification existe, elle s'applique.
        // Le faux KYC lève, ce qui prouve qu'il a bien été interrogé.
        $this->operateur('airtel', 'GA', '241', ['077'], ['kyc' => true]);

        $user    = $this->utilisateur('+24177607752');
        $requete = Request::create('/api/mobile/compte/type-compte', 'POST', [
            'type_compte' => 'particulier',
        ]);
        $requete->setUserResolver(fn () => $user);

        $this->expectExceptionMessage('Le KYC ne doit pas être interrogé ici.');
        app(AssociationController::class)->definirTypeCompte($requete);
    }
}
