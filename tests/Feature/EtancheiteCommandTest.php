<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'audit d'étanchéité trouve-t-il ce qu'il cherche ?
 *
 * Il est la contrepartie du projet unique : la frontière entre pays est tenue
 * par du code, donc elle doit être vérifiée et non supposée. Un audit qui ne
 * verrait pas une fuite serait pire que pas d'audit — il donnerait une
 * confiance infondée.
 */
class EtancheiteCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('pays')->nullable();
        });
        Schema::create('tondo_cagnottes', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('reference')->nullable();
            $t->string('pays')->nullable();
        });
        Schema::create('tondo_paiements', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('cagnotte_id')->nullable();
            $t->uuid('user_id')->nullable();
        });
        Schema::create('tondo_payout', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('cagnotte_id')->nullable();
            $t->string('trans_id')->nullable();
            $t->string('numero_tel')->nullable();
            $t->uuid('marchand_id')->nullable();
        });
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->string('indicatif')->nullable();
            $t->text('capacites')->nullable();
        });

        foreach ([['241', 'GA'], ['221', 'SN']] as [$ind, $pays]) {
            DB::table('tondo_project_config')->insert([
                'id' => (string) Str::uuid(), 'project_id' => 'p',
                'operateur' => 'op' . $pays, 'pays' => $pays,
                'indicatif' => $ind, 'capacites' => json_encode(['payin' => true]),
            ]);
        }
    }

    private function user(?string $pays): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'pays' => $pays]);

        return $id;
    }

    private function collecte(string $gerant, ?string $pays, string $ref = '100001'): string
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => 'p', 'user_id' => $gerant,
            'reference' => $ref, 'pays' => $pays,
        ]);

        return $id;
    }

    private function lancer(bool $details = false): array
    {
        $code = Artisan::call('tonji:etancheite', $details ? ['--details' => true] : []);

        return [$code, Artisan::output()];
    }

    public function test_sans_fuite_la_commande_reussit(): void
    {
        $ga = $this->user('GA');
        $this->collecte($ga, 'GA');

        [$code, $sortie] = $this->lancer();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Aucune fuite', $sortie);
    }

    public function test_une_cotisation_transfrontaliere_fait_echouer(): void
    {
        // Le code doit être non nul : c'est ce qui permet d'en faire une
        // alerte, et non une ligne de plus dans un journal que personne ne lit.
        $sn = $this->user('SN');
        $ga = $this->user('GA');
        DB::table('tondo_paiements')->insert([
            'id' => (string) Str::uuid(),
            'cagnotte_id' => $this->collecte($sn, 'SN'), 'user_id' => $ga,
        ]);

        [$code, $sortie] = $this->lancer();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Cotisations venues d'un autre pays", $sortie);
    }

    public function test_un_decaissement_vers_un_numero_etranger_est_vu(): void
    {
        // Ici il n'y a pas d'utilisateur : le bénéficiaire est un tiers dont on
        // n'a que le numéro. Le pays se déduit de l'indicatif.
        $ga = $this->user('GA');
        DB::table('tondo_payout')->insert([
            'id' => (string) Str::uuid(),
            'cagnotte_id' => $this->collecte($ga, 'GA'),
            'trans_id' => 'T1', 'numero_tel' => '+221770000000',
        ]);

        [$code, $sortie] = $this->lancer(details: true);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('numéro SN', $sortie);
    }

    public function test_une_collecte_qui_a_derive_est_vue(): void
    {
        $this->collecte($this->user('GA'), 'SN');

        [, $sortie] = $this->lancer();

        $this->assertStringContainsString('diffère de leur organisateur', $sortie);
    }

    public function test_une_donnee_manquante_n_est_pas_une_fuite(): void
    {
        // Confondre les deux ferait crier à la fraude là où il n'y a qu'un
        // champ vide — et l'alerte perdrait tout crédit.
        $this->collecte($this->user(null), null);

        [$code, $sortie] = $this->lancer();

        $this->assertSame(0, $code, 'Une donnée manquante ne doit pas faire échouer l\'audit.');
        $this->assertStringContainsString('sans pays', $sortie);
        $this->assertStringContainsString('Aucune fuite', $sortie);
    }

    public function test_le_detail_nomme_les_lignes_fautives(): void
    {
        // Un compte sans le détail serait inexploitable : on saurait qu'il y a
        // une fuite, pas laquelle.
        $sn = $this->user('SN');
        DB::table('tondo_paiements')->insert([
            'id' => (string) Str::uuid(),
            'cagnotte_id' => $this->collecte($sn, 'SN', '999888'), 'user_id' => $this->user('GA'),
        ]);

        [, $sortie] = $this->lancer(details: true);

        $this->assertStringContainsString('999888', $sortie);
    }
}
