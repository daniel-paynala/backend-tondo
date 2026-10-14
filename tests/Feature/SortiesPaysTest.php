<?php

namespace Tests\Feature;

use App\Services\SortiesAutorisees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les sorties d'argent suivent la capacité du pays.
 *
 * Règle de Daniel : tant qu'aucune intégration ne sert le pays, les boutons
 * sont GRISÉS avec « Aucun moyen de paiement local pour le moment » — pas
 * retirés. Un bouton qui disparaît laisse croire que la collecte a changé de
 * nature ; un bouton éteint avec sa raison se comprend.
 */
class SortiesPaysTest extends TestCase
{
    private string $projet = 'proj-test';
    private string $gerant;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('type_compte')->nullable();
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
            $t->string('pays')->nullable();
            $t->boolean('transfert_bloque')->default(false);
            $t->boolean('paiement_marchand_bloque')->default(false);
        });
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
            $t->text('sorties_bloquees')->nullable();
        });

        $this->gerant = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->gerant, 'type_compte' => 'particulier']);
    }

    private function pays(string $pays, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => 'op' . $pays, 'pays' => $pays, 'actif' => true,
            'capacites' => json_encode($capacites),
        ]);
    }

    private function etat(?string $pays): array
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => $this->projet,
            'user_id' => $this->gerant, 'pays' => $pays,
        ]);

        return app(SortiesAutorisees::class)->pour($id, $this->projet);
    }

    public function test_un_pays_qui_reverse_laisse_sortir(): void
    {
        $this->pays('GA', ['payout' => true, 'marchand' => true]);

        $etat = $this->etat('GA');

        $this->assertTrue($etat['transfert']);
        $this->assertTrue($etat['marchand']);
        $this->assertNull($etat['raison']);
    }

    public function test_sans_decaissement_les_boutons_sont_grises_et_non_retires(): void
    {
        // La nuance pilote l'affichage : `marchand_actif` à faux retirerait le
        // bouton « Payer », là où `marchand` à faux le grise.
        $this->pays('SN', ['payin' => true, 'payout' => false]);

        $etat = $this->etat('SN');

        $this->assertFalse($etat['transfert']);
        $this->assertFalse($etat['marchand']);
        $this->assertTrue($etat['marchand_actif'], 'Le bouton doit rester construit, donc grisable.');
    }

    public function test_la_raison_est_celle_que_daniel_a_dictee(): void
    {
        $this->pays('SN', ['payout' => false]);

        $this->assertSame(
            'Aucun moyen de paiement local pour le moment.',
            $this->etat('SN')['raison'],
        );
    }

    public function test_une_collecte_sans_pays_suit_celui_de_son_organisateur(): void
    {
        // Symétrique du repli à l'entrée (CotisationPaysTest) : une collecte
        // d'avant le rattachement doit pouvoir reverser, sinon elle encaisse
        // sans jamais rendre — l'argent de ses cotisants resterait pris.
        $this->pays('GA', ['payout' => true]);
        DB::table('users')->where('id', $this->gerant)->update(['pays' => 'GA']);

        $this->assertTrue($this->etat(null)['transfert']);
    }

    public function test_une_collecte_sans_pays_ne_sort_pas(): void
    {
        // Ni en colonne, ni chez l'organisateur : on ferme, faute de savoir
        // où est la frontière.
        $this->pays('GA', ['payout' => true]);

        $this->assertFalse($this->etat(null)['transfert']);
    }

    public function test_sans_pays_nulle_part_la_raison_parle_de_regularisation(): void
    {
        // Et pas « aucun moyen de paiement local » : le pays du Gabon reverse
        // très bien, c'est le rattachement de la collecte qui manque. Un
        // message faux enverrait l'organisateur attendre une intégration qui
        // existe déjà.
        $this->pays('GA', ['payout' => true]);

        $this->assertStringContainsString('régularisation', $this->etat(null)['raison']);
    }
}
