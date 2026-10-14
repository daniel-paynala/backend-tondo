<?php

namespace Tests\Feature;

use App\Services\CollecteAutorisee;
use App\Services\QuotaCagnottes;
use App\Services\SortiesAutorisees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suspendre un organisateur ferme TOUT, sur TOUTES ses collectes.
 *
 * Ces cas appellent les vrais services, avec de vraies lectures en base —
 * contrairement au test des verrous, qui recopie la règle et ne prouve donc
 * rien sur le code qui tourne. Une suspension qui laisserait passer une seule
 * opération n'aurait aucune valeur : l'organisateur en a souvent plusieurs, il
 * lui suffirait de prendre une autre collecte.
 */
class SuspensionOrganisateurTest extends TestCase
{
    private string $userId;
    private string $projectId = 'proj-test';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('type_compte')->nullable();
            $t->timestamp('suspendu_at')->nullable();
            $t->string('suspendu_motif')->nullable();
            // Le pays du compte : les décisions de sortie et de collecte le
            // lisent ici. Sans la colonne, SQLite rend la chaîne « pays »
            // au lieu d'échouer, et le repli n'est jamais exercé.
            $t->string('pays')->nullable();
        });

        Schema::create('tondo_cagnottes', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('reference')->nullable();
            $t->string('type')->nullable();
            $t->string('statut')->default('active');
            $t->string('visibilite')->default('prive');
            $t->string('statut_validation')->nullable();
            $t->boolean('transfert_bloque')->default(false);
            $t->boolean('paiement_marchand_bloque')->default(false);
            $t->boolean('cotisation_bloquee')->default(false);
            $t->timestamp('date_fin')->nullable();
            // Depuis le rattachement par pays, une sortie exige que le pays de
            // la collecte sache reverser.
            $t->string('pays')->nullable();
        });

        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
        });

        // Le pays d'exploitation sait tout faire : ces cas-là portent sur la
        // suspension, pas sur les capacités.
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projectId,
            'operateur' => 'airtel', 'pays' => 'GA', 'actif' => true,
            'capacites' => json_encode([
                'kyc' => true, 'verification_numero' => true,
                'payin' => true, 'payout' => true,
                'frais' => true, 'plafonds' => true,
            ]),
        ]);

        $this->userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id'          => $this->userId,
            'project_id'  => $this->projectId,
            'type_compte' => 'particulier',
        ]);
    }

    /** Crée une collecte de cet organisateur et rend son identifiant. */
    private function collecte(string $statut = 'active', string $type = 'cagnotte_ouverte'): string
    {
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id'         => $id,
            'project_id' => $this->projectId,
            'user_id'    => $this->userId,
            'reference'  => (string) random_int(100000, 999999),
            'type'       => $type,
            'statut'     => $statut,
            'pays'       => 'GA',
        ]);

        return $id;
    }

    private function suspendre(): void
    {
        DB::table('users')->where('id', $this->userId)
            ->update(['suspendu_at' => now(), 'suspendu_motif' => 'Enquête en cours']);
    }

    public function test_sans_suspension_les_sorties_sont_ouvertes(): void
    {
        $id = $this->collecte();
        $etat = app(SortiesAutorisees::class)->pour($id, $this->projectId);

        $this->assertTrue($etat['transfert']);
        $this->assertTrue($etat['marchand']);
    }

    public function test_la_suspension_ferme_les_deux_sorties(): void
    {
        $id = $this->collecte();
        $this->suspendre();

        $etat = app(SortiesAutorisees::class)->pour($id, $this->projectId);

        $this->assertFalse($etat['transfert']);
        $this->assertFalse($etat['marchand']);

        // Mais le SERVICE reste présenté comme actif : c'est cette collecte-ci
        // qui est fermée, pas le paiement marchand en général.
        //
        // La nuance pilote l'affichage : `marchand_actif` à faux retire le
        // bouton « Payer », là où `marchand` à faux le grise. Une suspension
        // se lève — faire disparaître le bouton laisserait croire que la
        // collecte a changé de nature.
        $this->assertTrue($etat['marchand_actif']);
    }

    public function test_la_suspension_ferme_TOUTES_ses_collectes(): void
    {
        // Le cas qui donne son sens à la mesure : trois collectes, une seule
        // décision. En fermer une seule n'empêcherait rien.
        $ids = [$this->collecte(), $this->collecte(), $this->collecte()];
        $this->suspendre();

        foreach ($ids as $id) {
            $etat = app(SortiesAutorisees::class)->pour($id, $this->projectId);
            $this->assertFalse($etat['transfert'], "Collecte {$id} encore ouverte au transfert.");
            $this->assertFalse($etat['marchand'], "Collecte {$id} encore ouverte au paiement.");
        }
    }

    public function test_la_suspension_ferme_aussi_la_collecte(): void
    {
        $id = $this->collecte();
        $this->suspendre();

        $cagnotte = DB::table('tondo_cagnottes')->where('id', $id)->first();
        $refus = app(CollecteAutorisee::class)->refus($cagnotte);

        $this->assertNotNull($refus);
        $this->assertStringContainsString('suspendues', $refus);
    }

    public function test_le_cotisant_n_apprend_pas_que_l_organisateur_est_suspendu(): void
    {
        // Le message doit parler de la collecte, pas de la personne : c'est une
        // information interne, et le cotisant n'a rien à en faire.
        $id = $this->collecte();
        $this->suspendre();

        $refus = app(CollecteAutorisee::class)
            ->refus(DB::table('tondo_cagnottes')->where('id', $id)->first());

        $this->assertStringNotContainsString('organisateur', $refus);
        $this->assertStringNotContainsString('suspendu ', $refus);
    }

    public function test_lever_la_suspension_rouvre_tout(): void
    {
        $id = $this->collecte();
        $this->suspendre();
        DB::table('users')->where('id', $this->userId)
            ->update(['suspendu_at' => null, 'suspendu_motif' => null]);

        $etat = app(SortiesAutorisees::class)->pour($id, $this->projectId);

        $this->assertTrue($etat['transfert']);
        $this->assertTrue($etat['marchand']);
    }

    // ── Quota ───────────────────────────────────────────────────────────────

    public function test_sous_le_quota_la_creation_passe(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->collecte();
        }

        $this->assertNull(app(QuotaCagnottes::class)->refus($this->userId, $this->projectId));
    }

    public function test_au_quota_la_creation_est_refusee(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->collecte();
        }

        $refus = app(QuotaCagnottes::class)->refus($this->userId, $this->projectId);

        $this->assertNotNull($refus);
        // Le message donne le chiffre ET la sortie : sans ça, l'organisateur
        // appelle le support pour savoir quoi faire.
        $this->assertStringContainsString('5', $refus);
        $this->assertStringContainsString('Clôturez', $refus);
    }

    public function test_une_collecte_cloturee_ne_compte_plus(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->collecte();
        }
        DB::table('tondo_cagnottes')->limit(1)->update(['statut' => 'cloturee']);

        $this->assertNull(app(QuotaCagnottes::class)->refus($this->userId, $this->projectId));
    }

    public function test_le_solde_personnel_ne_compte_pas_dans_le_quota(): void
    {
        // Un wallet est une collecte au sens de la table, pas au sens du
        // quota : le compter priverait chacun d'une de ses cinq collectes.
        for ($i = 0; $i < 4; $i++) {
            $this->collecte();
        }
        $this->collecte(type: 'wallet');

        $this->assertNull(app(QuotaCagnottes::class)->refus($this->userId, $this->projectId));
    }
}
