<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'audit voit-il une frontière percée ?
 *
 * Les pays sont séparés mais partagent un seul `project_id` : la frontière est
 * tenue par du CODE, pas par la structure. Une requête qui l'oublierait ne
 * casserait rien — elle laisserait passer, en silence.
 *
 * Ce contrôle est la contrepartie du choix d'un projet unique (Daniel,
 * 2026-10-14). Sans lui, « on fera attention » serait la seule garantie.
 */
class EtancheitePaysAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id')->nullable();
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
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->text('capacites')->nullable();
        });
    }

    private function user(?string $pays): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'project_id' => 'p', 'pays' => $pays]);

        return $id;
    }

    private function collecte(string $gerant, ?string $pays): string
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => 'p', 'user_id' => $gerant,
            'reference' => (string) random_int(100000, 999999), 'pays' => $pays,
        ]);

        return $id;
    }

    private function cotisation(string $collecte, string $cotisant): void
    {
        DB::table('tondo_paiements')->insert([
            'id' => (string) Str::uuid(),
            'cagnotte_id' => $collecte, 'user_id' => $cotisant,
        ]);
    }

    /**
     * La sortie de l'audit, capturée.
     *
     * `$this->artisan()` ne rend rien tant qu'on ne l'exécute pas : c'est un
     * objet d'assertions, pas un appel. `Artisan::call()` exécute vraiment et
     * remplit le tampon.
     */
    private function sortieAudit(): string
    {
        \Illuminate\Support\Facades\Artisan::call('tonji:audit', ['--donnees' => true]);

        return \Illuminate\Support\Facades\Artisan::output();
    }

    public function test_sans_fuite_l_audit_le_dit(): void
    {
        $gabonais = $this->user('GA');
        $this->cotisation($this->collecte($gabonais, 'GA'), $gabonais);

        $this->assertStringContainsString('Aucune cotisation transfrontalière', $this->sortieAudit());
    }

    public function test_une_cotisation_transfrontaliere_est_une_anomalie(): void
    {
        // Le cas que le code est censé empêcher. S'il arrive quand même,
        // c'est qu'un chemin a oublié la règle — et il faut le savoir.
        $gabonais   = $this->user('GA');
        $senegalais = $this->user('SN');
        $this->cotisation($this->collecte($senegalais, 'SN'), $gabonais);

        $sortie = $this->sortieAudit();

        $this->assertStringContainsString("d'un pays vers une collecte d'un autre", $sortie);
        $this->assertStringContainsString('percée', $sortie);
        // Une anomalie, pas un avertissement : une frontière percée n'est pas
        // un point à regarder un jour, c'est de l'argent dans le mauvais
        // écosystème. Le libellé est aligné par des espaces, d'où l'expression
        // plutôt qu'une chaîne littérale.
        $this->assertMatchesRegularExpression(
            '/ÉCHEC\s+pays/u',
            $sortie,
            'La fuite doit être classée en anomalie, pas en avertissement.',
        );
    }

    public function test_une_collecte_qui_a_derive_de_son_organisateur(): void
    {
        // Le pays est recopié à la création et censé être immuable. Un écart
        // signifie qu'on l'a modifié après coup.
        $gabonais = $this->user('GA');
        $this->collecte($gabonais, 'SN');

        $this->assertStringContainsString(
            'diffère de celui de leur organisateur',
            $this->sortieAudit(),
        );
    }

    public function test_ce_qui_n_est_rattache_a_rien_est_signale(): void
    {
        // Pas une fuite, une impasse : sans pays, personne ne peut rien faire.
        $this->collecte($this->user(null), null);

        $sortie = $this->sortieAudit();

        $this->assertStringContainsString('collecte(s) sans pays', $sortie);
        $this->assertStringContainsString('compte(s) sans pays', $sortie);
    }

    public function test_un_operateur_sans_capacites_decrites_est_signale(): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => 'p',
            'operateur' => 'orange', 'pays' => 'SN', 'capacites' => null,
        ]);

        $this->assertStringContainsString(
            'sans capacités décrites',
            $this->sortieAudit(),
        );
    }
}
