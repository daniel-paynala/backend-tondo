<?php

namespace Tests\Feature;

use App\Support\RapportActivite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les chiffres du rapport d'activité.
 *
 * Deux dangers, et les deux ont déjà frappé ce mois-ci :
 *
 *  – compter ce qui n'a pas abouti, et annoncer un montant collecté qui n'est
 *    jamais entré en caisse ;
 *  – nommer une valeur d'énumération qui n'existe pas, ce qui fait échouer la
 *    requête ENTIÈRE et non la seule condition. SQLite ne le reproduit pas :
 *    le second test porte donc sur le SQL émis.
 */
class RapportActiviteTest extends TestCase
{
    private string $projectId = 'proj-test';
    private Carbon $debut;
    private Carbon $fin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debut = Carbon::parse('2026-10-01 00:00:00');
        $this->fin   = Carbon::parse('2026-10-31 23:59:59');

        Schema::create('tondo_paiements', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->bigInteger('montant')->default(0);
            $t->boolean('actif')->default(true);
            $t->timestamps();
        });
        Schema::create('tondo_payin', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('statut');
            $t->timestamp('date_creation')->nullable();
        });
        Schema::create('tondo_payout', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->bigInteger('montant')->default(0);
            $t->string('statut');
            $t->uuid('marchand_id')->nullable();
            $t->timestamp('date_creation')->nullable();
        });
        Schema::create('tondo_cagnottes', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('type');
            $t->timestamps();
        });
        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id')->nullable();
            $t->timestamps();
        });
    }

    private function paiement(int $montant, bool $actif = true, string $quand = '2026-10-10'): void
    {
        DB::table('tondo_paiements')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projectId,
            'montant' => $montant, 'actif' => $actif,
            'created_at' => $quand, 'updated_at' => $quand,
        ]);
    }

    private function payout(int $montant, string $statut, ?string $marchand = null): void
    {
        DB::table('tondo_payout')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projectId,
            'montant' => $montant, 'statut' => $statut, 'marchand_id' => $marchand,
            'date_creation' => '2026-10-10',
        ]);
    }

    private function rapport(): array
    {
        return app(RapportActivite::class)->pour($this->projectId, $this->debut, $this->fin);
    }

    public function test_les_cotisations_sont_comptees_et_sommees(): void
    {
        $this->paiement(5000);
        $this->paiement(3000);

        $c = $this->rapport()['cotisations'];

        $this->assertSame(2, $c['nombre']);
        $this->assertSame(8000, $c['montant']);
        $this->assertSame(4000, $c['moyenne']);
    }

    public function test_une_cotisation_annulee_ne_compte_pas(): void
    {
        // `actif = false` marque une ligne annulée. La compter annoncerait de
        // l'argent qui n'est pas en caisse.
        $this->paiement(5000);
        $this->paiement(99000, actif: false);

        $this->assertSame(5000, $this->rapport()['cotisations']['montant']);
    }

    public function test_hors_periode_rien_ne_compte(): void
    {
        $this->paiement(5000, quand: '2026-09-15');

        $this->assertSame(0, $this->rapport()['cotisations']['nombre']);
    }

    public function test_transferts_et_paiements_marchands_sont_separes(): void
    {
        // Les deux vivent dans la même table : les confondre ferait passer des
        // achats pour des retraits de bénéficiaires.
        $this->payout(10000, 'succes');
        $this->payout(2500, 'succes', marchand: (string) Str::uuid());

        $s = $this->rapport()['sorties'];

        $this->assertSame(1, $s['transferts']['nombre']);
        $this->assertSame(10000, $s['transferts']['montant']);
        $this->assertSame(1, $s['marchands']['nombre']);
        $this->assertSame(2500, $s['marchands']['montant']);
    }

    public function test_seules_les_sorties_abouties_sont_sommees(): void
    {
        $this->payout(10000, 'succes');
        $this->payout(50000, 'initie');
        $this->payout(70000, 'echec');

        $s = $this->rapport()['sorties'];

        $this->assertSame(10000, $s['transferts']['montant']);
        $this->assertSame(1, $s['en_echec']);
    }

    public function test_le_rapport_ne_nomme_aucun_statut_inexistant(): void
    {
        // `statut` est une énumération en production : nommer une valeur
        // absente fait échouer la requête entière, et SQLite ne le dit pas.
        $valides = ['initie', 'en_cours', 'succes', 'echec', 'annule'];

        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            foreach ($q->bindings as $b) {
                if (is_string($b)) {
                    $requetes[] = $b;
                }
            }
        });

        $this->rapport();

        foreach ($requetes as $valeur) {
            // On ne contrôle que ce qui ressemble à un statut : les autres
            // liaisons sont des identifiants, des dates ou des types.
            if (in_array($valeur, ['succes', 'echec', 'initie', 'en_cours', 'annule', 'refuse', 'rejete'], true)) {
                $this->assertContains($valeur, $valides, "« {$valeur} » n'est pas un statut admis.");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_le_solde_personnel_n_est_pas_une_creation(): void
    {
        // Il s'ouvre tout seul au premier accès : le compter ferait passer une
        // visite pour une création de collecte.
        foreach (['cagnotte_ouverte', 'tontine_periodique', 'wallet'] as $type) {
            DB::table('tondo_cagnottes')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->projectId,
                'type' => $type, 'created_at' => '2026-10-10', 'updated_at' => '2026-10-10',
            ]);
        }

        $this->assertSame(2, $this->rapport()['creations']['collectes']);
    }
}
