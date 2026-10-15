<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\PaysController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * On doit pouvoir créer un compte hors du Gabon.
 *
 * Le 2026-10-15, c'était impossible : `kyc-check` validait le numéro sur le
 * gabarit gabonais « 0 + 8 chiffres », puis le convertissait en collant
 * « +241 » devant. Un numéro sénégalais était refusé par la validation ; s'il
 * était passé, il aurait été réécrit en numéro gabonais, et l'OTP serait parti
 * dans le vide.
 */
class InscriptionEtrangereTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La registry des projets : `Project::tondoId()` la lit avant tout.
        Schema::create('projects', function ($t) {
            $t->string('id')->primary();
            $t->string('slug');
        });
        DB::table('projects')->insert([
            'id' => (string) Str::uuid(), 'slug' => config('project.slug'),
        ]);

        // Le contrôle « ce numéro est-il déjà inscrit ? » précède la détection.
        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('numero')->nullable();
            $t->string('compte_type')->nullable();
            $t->string('pays')->nullable();
        });

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
    }

    private function operateur(
        string $op,
        string $pays,
        string $indicatif,
        ?array $capacites = null,
        array $prefixes = [],
    ): void {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => \App\Models\Project::tondoId(),
            'operateur' => $op, 'pays' => $pays, 'indicatif' => $indicatif, 'actif' => true,
            'prefixes' => $prefixes ? json_encode($prefixes) : null,
            'capacites' => $capacites ? json_encode($capacites) : null,
        ]);
    }

    public function test_un_numero_etranger_passe_la_validation(): void
    {
        // Le point précis de la panne : un numéro à 9 chiffres ne commençant
        // pas par zéro était rejeté avant toute vérification.
        $this->operateur('orange', 'SN', '221', prefixes: ['77', '78']);

        $reponse = $this->getJson('/api/mobile/auth/kyc-check?numero=771234567&indicatif=221');

        $reponse->assertStatus(200);
        $this->assertArrayNotHasKey(
            'numero',
            (array) ($reponse->json('errors') ?? []),
            'Le format du numéro ne doit plus être celui du Gabon.',
        );
    }

    public function test_un_numero_senegalais_trouve_son_operateur(): void
    {
        // La validation ne suffisait pas : la détection ajoutait un zéro
        // devant le numéro local — « 077 » au Gabon — et un préfixe
        // sénégalais écrit « 77 » ne pouvait jamais correspondre.
        $this->operateur('orange', 'SN', '221', prefixes: ['77', '78']);

        $reponse = $this->getJson('/api/mobile/auth/kyc-check?numero=771234567&indicatif=221');

        $reponse->assertStatus(200);
        $this->assertSame('orange', $reponse->json('operateur'));
        $this->assertFalse($reponse->json('bloque'), 'Un pays sans KYC laisse passer, en mode dégradé.');
        $this->assertTrue($reponse->json('email_requis'), 'Sans KYC, l\'adresse devient obligatoire.');
    }

    public function test_le_numero_gabonais_habituel_passe_toujours(): void
    {
        // Les versions déjà publiées n'envoient pas d'indicatif : elles
        // doivent continuer de fonctionner sans rien changer.
        $this->operateur('airtel', 'GA', '241', prefixes: ['077', '076']);

        $reponse = $this->getJson('/api/mobile/auth/kyc-check?numero=077607752');

        $reponse->assertStatus(200);
    }

    public function test_l_adresse_peut_etre_exigee_la_ou_le_kyc_fonctionne(): void
    {
        // Le réglage demandé le 2026-10-15 : l'apparition du champ e-mail se
        // commande, elle n'est plus seulement la conséquence d'un KYC absent.
        $this->operateur('airtel', 'GA', '241', [
            'kyc' => true, 'email_obligatoire' => true,
        ], prefixes: ['077']);

        // Le KYC est remplacé : un test ne doit pas appeler l'API d'un tiers.
        // Sans ça celui-ci partait réellement sur le réseau — quatre secondes,
        // et rouge dès qu'on travaille hors ligne.
        $this->app->bind(\App\Services\PaynalaPaymentService::class, fn () => new class extends \App\Services\PaynalaPaymentService
        {
            public function __construct() {}

            public function checkKycData(string $msisdn): ?array
            {
                return [
                    'ok' => true, 'nom' => 'DOVI', 'prenom' => 'Daniel',
                    'type_client' => 'particulier', 'type_compte' => 'particulier',
                ];
            }
        });

        $reponse = $this->getJson('/api/mobile/auth/kyc-check?numero=077607752');

        $reponse->assertStatus(200);
        $this->assertTrue(
            $reponse->json('email_requis'),
            'Cocher la case doit faire apparaître le champ, même quand le KYC réussit.',
        );
    }

    public function test_sans_kyc_l_adresse_reste_exigee_meme_sans_la_case(): void
    {
        // L'exigence explicite AJOUTE, elle ne retire jamais : décocher la
        // case ne doit pas rouvrir une inscription sans aucune trace
        // d'identité là où l'opérateur ne vérifie rien.
        $this->operateur('orange', 'SN', '221', [
            'kyc' => false, 'email_obligatoire' => false,
        ], prefixes: ['77']);

        $reponse = $this->getJson('/api/mobile/auth/kyc-check?numero=771234567&indicatif=221');

        $this->assertTrue($reponse->json('email_requis'));
    }

    public function test_les_pays_servis_viennent_de_la_configuration(): void
    {
        $this->operateur('airtel', 'GA', '241', ['payin' => true, 'payout' => true]);
        $this->operateur('orange', 'SN', '221');

        $corps = json_decode(app(PaysController::class)->index()->getContent(), true);
        $codes = array_column($corps['pays'], 'code');

        $this->assertContains('GA', $codes);
        $this->assertContains('SN', $codes, 'Ouvrir un pays dans le dashboard doit suffire à le voir ici.');
    }

    public function test_un_pays_sans_integration_accepte_quand_meme_les_inscriptions(): void
    {
        // La règle dictée : pas d'API de paiement ne ferme pas la porte
        // d'entrée, ça grise les écrans d'argent ensuite.
        $this->operateur('orange', 'SN', '221');

        $corps = json_decode(app(PaysController::class)->index()->getContent(), true);
        $senegal = collect($corps['pays'])->firstWhere('code', 'SN');

        $this->assertTrue($senegal['inscription_possible']);
        $this->assertFalse($senegal['encaisse'], 'Sans capacité déclarée, le pays n\'encaisse pas.');
    }
}
