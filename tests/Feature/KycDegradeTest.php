<?php

namespace Tests\Feature;

use App\Services\CapacitesOperateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S'inscrire là où l'opérateur n'expose pas de KYC.
 *
 * Règle de Daniel (2026-10-14) : sans vérification d'identité disponible, on
 * ne ferme pas la porte. L'utilisateur saisit ses informations, son e-mail
 * devient OBLIGATOIRE, et le code part par les deux canaux.
 *
 * L'e-mail n'est pas un confort : c'est la seule trace déclarée de l'identité,
 * et le seul canal de secours quand le SMS se perd — ce qui vient d'arriver
 * une semaine durant, du 7 au 14 octobre.
 */
class KycDegradeTest extends TestCase
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

    private function operateur(string $nom, string $pays, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => $nom, 'pays' => $pays, 'actif' => true,
            'capacites' => json_encode($capacites),
        ]);
    }

    private function aLeKyc(string $operateur, string $pays): bool
    {
        return app(CapacitesOperateur::class)
            ->a($this->projet, $operateur, $pays, 'kyc');
    }

    public function test_un_operateur_avec_kyc_le_declare(): void
    {
        $this->operateur('airtel', 'GA', ['kyc' => true, 'payin' => true]);

        $this->assertTrue($this->aLeKyc('airtel', 'GA'));
    }

    public function test_un_operateur_sans_kyc_ne_bloque_plus_l_inscription(): void
    {
        // Avant, le code testait le NOM de l'opérateur : tout ce qui n'était
        // pas Airtel était refusé d'office. Désormais c'est la capacité qui
        // décide, et son absence dégrade au lieu de fermer.
        $this->operateur('orange', 'SN', ['kyc' => false, 'payin' => true]);

        $this->assertFalse($this->aLeKyc('orange', 'SN'));
    }

    public function test_une_capacite_non_decrite_vaut_absence_de_kyc(): void
    {
        // Le défaut sûr : on ne suppose pas qu'une vérification existe.
        $this->operateur('wave', 'SN', ['payin' => true]);

        $this->assertFalse($this->aLeKyc('wave', 'SN'));
    }

    public function test_le_service_otp_accepte_une_adresse_directe(): void
    {
        // À l'inscription, le compte n'existe pas : sans cette adresse, le
        // repli par e-mail n'a aucune ligne `users` à interroger, donc aucun
        // canal de secours.
        $methode = new \ReflectionMethod(\App\Services\OtpService::class, 'sendOtp');

        $this->assertSame(2, $methode->getNumberOfParameters());
        $this->assertSame('emailDirect', $methode->getParameters()[1]->getName());
        $this->assertTrue($methode->getParameters()[1]->isOptional());
    }
}
