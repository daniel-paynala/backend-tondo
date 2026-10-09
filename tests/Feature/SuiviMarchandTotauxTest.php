<?php

namespace Tests\Feature;

use App\Support\JetonMarchand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Le suivi marchand construit DEUX requêtes sur la même base : le détail
 * paginé, et les totaux groupés par statut.
 *
 * Elles ne peuvent pas partager leur liste de colonnes. `selectRaw` AJOUTE à
 * cette liste au lieu de la remplacer : quand la requête commune portait les
 * huit colonnes du détail, elles se retrouvaient dans un SELECT groupé sur le
 * seul statut, et PostgreSQL refusait — « column p.trans_id must appear in the
 * GROUP BY clause » (42803). L'écran du portail était donc mort à chaque appel.
 *
 * Le test se fait sur le SQL ÉMIS, et non sur la réponse : SQLite, qui porte
 * les tests, accepte ce que PostgreSQL refuse. Une vérification du résultat
 * passerait donc avec le bug en place.
 */
class SuiviMarchandTotauxTest extends TestCase
{
    private const NUMERO = '+24177851716';
    private const PROJET = 'projet-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        // Schéma minimal : la requête joint quatre tables, elles doivent
        // exister. Aucune ligne n'est nécessaire — c'est la FORME du SQL qu'on
        // éprouve, pas son résultat.
        Schema::create('tondo_payout', function ($table) {
            $table->uuid('id')->primary();
            $table->string('project_id')->nullable();
            $table->uuid('cagnotte_id')->nullable();
            $table->uuid('marchand_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('trans_id')->nullable();
            $table->integer('montant')->default(0);
            $table->string('statut')->nullable();
            $table->timestamp('date_creation')->nullable();
        });
        Schema::create('tondo_marchands', function ($table) {
            $table->uuid('id')->primary();
            $table->string('nom')->nullable();
            $table->string('numero_tel')->nullable();
        });
        Schema::create('tondo_cagnottes', function ($table) {
            $table->uuid('id')->primary();
            $table->string('titre')->nullable();
        });
        Schema::create('users', function ($table) {
            $table->uuid('id')->primary();
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
        });

        // Une ligne, et une seule : sans elle le paginateur s'arrête au
        // `count` et n'émet jamais la requête de détail — le second test
        // n'aurait rien à regarder.
        DB::table('tondo_marchands')->insert([
            'id' => 'm-1', 'nom' => 'Chez Paulette', 'numero_tel' => self::NUMERO,
        ]);
        DB::table('tondo_cagnottes')->insert(['id' => 'c-1', 'titre' => 'Anniversaire']);
        DB::table('tondo_payout')->insert([
            'id'            => 'p-1',
            'project_id'    => self::PROJET,
            'cagnotte_id'   => 'c-1',
            'marchand_id'   => 'm-1',
            'user_id'       => null,
            'trans_id'      => 'TONJIPAYOUT1',
            'montant'       => 3000,
            'statut'        => 'succes',
            'date_creation' => now()->subDay(),
        ]);
    }

    /** @return list<string> le SQL de chaque requête émise par l'appel */
    private function sqlEmisParLAppel(): array
    {
        $jeton = JetonMarchand::creer(self::NUMERO, self::PROJET);

        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            $requetes[] = strtolower($q->sql);
        });

        $this->withToken($jeton)
            ->getJson('/api/marchand/transactions')
            ->assertOk();

        return $requetes;
    }

    public function test_la_requete_des_totaux_ne_selectionne_que_ses_agregats(): void
    {
        $groupees = array_values(array_filter(
            $this->sqlEmisParLAppel(),
            fn ($sql) => str_contains($sql, 'group by'),
        ));

        $this->assertNotEmpty($groupees, 'aucune requête groupée : le suivi ne calcule plus ses totaux');

        foreach ($groupees as $sql) {
            // La colonne qui déclenchait l'erreur, et la plus parlante des huit.
            $this->assertStringNotContainsString(
                'trans_id',
                $sql,
                "la requête groupée traîne les colonnes du détail :\n$sql",
            );
            $this->assertStringContainsString('count(*)', $sql);
        }
    }

    /** Le détail, lui, doit garder ses colonnes — sinon la liste est vide. */
    public function test_la_requete_du_detail_garde_ses_colonnes(): void
    {
        $detail = array_values(array_filter(
            $this->sqlEmisParLAppel(),
            fn ($sql) => str_contains($sql, 'trans_id'),
        ));

        $this->assertNotEmpty($detail, 'le détail ne sélectionne plus la référence de transaction');
    }
}
