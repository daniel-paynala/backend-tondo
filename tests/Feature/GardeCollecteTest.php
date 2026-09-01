<?php

namespace Tests\Feature;

use App\Models\TondoUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests (sqlite en mémoire) des GARDES DE SÉCURITÉ associations, de bout
 * en bout (HTTP → validation → garde → réponse).
 *
 * On ne s'appuie pas sur la chaîne de migrations (les tables cœur sont en SQL
 * brut, pas en migrations Laravel) : on monte le schéma MINIMAL requis dans
 * setUp(). Les gardes rejettent en 422 AVANT tout appel config/paiement, donc
 * pas besoin de la config projet ni de mocks opérateur.
 */
class GardeCollecteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['project.table_prefix' => 'tondo_']);
        $this->monterSchema();
    }

    private function monterSchema(): void
    {
        Schema::dropIfExists('tondo_cagnottes');
        Schema::dropIfExists('tondo_organisations');
        Schema::dropIfExists('users');

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('nom')->nullable();
            $t->string('prenom')->nullable();
            $t->string('numero')->nullable();
            $t->string('type_client')->nullable();
            $t->string('type_compte')->nullable();
            $t->date('date_naissance')->nullable();
            $t->bigInteger('plafond_personnalise')->nullable();
            $t->boolean('kyc_valide')->default(false);
            $t->timestamps();
        });

        Schema::create('tondo_organisations', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('nom')->nullable();
            $t->string('statut')->default('en_attente');
            $t->bigInteger('plafond_fcfa')->nullable();
            $t->timestamps();
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
            $t->bigInteger('montant_collecte')->default(0);
            $t->timestamps();
        });
    }

    private function creerUser(string $typeCompte = 'particulier'): TondoUser
    {
        $u = new TondoUser();
        $u->id = (string) Str::uuid();
        $u->project_id = 'proj-test';
        $u->nom = 'Elom';
        $u->prenom = 'Daniel';
        $u->numero = '+2417700' . random_int(10000, 99999);
        $u->type_client = 'particulier';
        $u->type_compte = $typeCompte;
        $u->kyc_valide = true;
        $u->save();

        return $u;
    }

    private function creerOrg(string $userId, string $statut): void
    {
        DB::table('tondo_organisations')->insert([
            'id'          => (string) Str::uuid(),
            'project_id'  => 'proj-test',
            'user_id'     => $userId,
            'nom'         => 'Association Test',
            'statut'      => $statut,
            'plafond_fcfa' => 10000000,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function creerCagnotte(string $userId, array $attrs = []): string
    {
        $ref = $attrs['reference'] ?? '123456';
        DB::table('tondo_cagnottes')->insert(array_merge([
            'id'                => (string) Str::uuid(),
            'project_id'        => 'proj-test',
            'user_id'           => $userId,
            'reference'         => $ref,
            'titre'             => 'Cagnotte Test',
            'type'              => 'cagnotte_ouverte',
            'statut'            => 'active',
            'visibilite'        => 'prive',
            'statut_validation' => 'non_requis',
            'montant_collecte'  => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $attrs));

        return $ref;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_association_non_approuvee_ne_peut_pas_creer_de_cagnotte(): void
    {
        $u = $this->creerUser('association');
        $this->creerOrg($u->id, 'en_attente');
        Sanctum::actingAs($u, [], 'mobile');

        $this->postJson('/api/mobile/cagnottes', [
            'type'  => 'cagnotte_ouverte',
            'titre' => 'Ma cagnotte',
        ])->assertStatus(422);
    }

    public function test_cotisation_bloquee_si_association_suspendue(): void
    {
        $owner = $this->creerUser('association');
        $this->creerOrg($owner->id, 'suspendu');
        $ref = $this->creerCagnotte($owner->id); // privée, active

        $cotisant = $this->creerUser('particulier');
        Sanctum::actingAs($cotisant, [], 'mobile');

        $this->postJson('/api/mobile/cotisations', [
            'cagnotte_reference' => $ref,
            'montant'            => 1000,
        ])->assertStatus(422);
    }

    public function test_cotisation_bloquee_si_cagnotte_publique_non_validee(): void
    {
        $owner = $this->creerUser('association');
        $this->creerOrg($owner->id, 'approuve');
        $ref = $this->creerCagnotte($owner->id, [
            'visibilite'        => 'public',
            'statut_validation' => 'en_attente',
        ]);

        $cotisant = $this->creerUser('particulier');
        Sanctum::actingAs($cotisant, [], 'mobile');

        $this->postJson('/api/mobile/cotisations', [
            'cagnotte_reference' => $ref,
            'montant'            => 1000,
        ])->assertStatus(422);
    }

    public function test_cotisation_sur_cagnotte_cloturee_refusee(): void
    {
        // Cas de contrôle : le statut de cagnotte bloque aussi (non lié aux assos).
        $owner = $this->creerUser('particulier');
        $ref = $this->creerCagnotte($owner->id, ['statut' => 'cloturee']);

        $cotisant = $this->creerUser('particulier');
        Sanctum::actingAs($cotisant, [], 'mobile');

        $this->postJson('/api/mobile/cotisations', [
            'cagnotte_reference' => $ref,
            'montant'            => 1000,
        ])->assertStatus(422);
    }
}
