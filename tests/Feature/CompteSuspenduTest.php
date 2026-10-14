<?php

namespace Tests\Feature;

use App\Services\CompteSuspendu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ce qu'un compte suspendu ne peut plus faire, lui, où qu'il aille.
 *
 * La première version de la suspension ne fermait que les collectes de la
 * personne. Elle pouvait donc continuer à cotiser chez les autres, et surtout
 * **créer une collecte neuve** — qui n'était pas verrouillée, puisqu'elle
 * venait de naître. La mesure se contournait en trois clics.
 */
class CompteSuspenduTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->timestamp('suspendu_at')->nullable();
            // Le pays du compte : les décisions de sortie et de collecte le
            // lisent ici. Sans la colonne, SQLite rend la chaîne « pays »
            // au lieu d'échouer, et le repli n'est jamais exercé.
            $t->string('pays')->nullable();
        });

        $this->userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $this->userId]);
    }

    private function suspendre(): void
    {
        DB::table('users')->where('id', $this->userId)->update(['suspendu_at' => now()]);
    }

    public function test_un_compte_actif_n_est_pas_refuse(): void
    {
        $user = (object) ['id' => $this->userId, 'suspendu_at' => null];

        $this->assertNull(app(CompteSuspendu::class)->refus($user));
    }

    public function test_un_compte_suspendu_est_refuse(): void
    {
        $user = (object) ['id' => $this->userId, 'suspendu_at' => now()];

        $refus = app(CompteSuspendu::class)->refus($user);

        $this->assertNotNull($refus);
        // Le message parle de SON compte, pas de la collecte visée : la
        // personne est concernée directement, et doit savoir à qui s'adresser.
        $this->assertStringContainsString('Votre compte', $refus);
        $this->assertStringContainsString('support', $refus);
    }

    public function test_par_identifiant_la_lecture_se_fait_en_base(): void
    {
        // Le mouvement interne ne connaît que le `user_id` du solde source.
        $this->assertNull(app(CompteSuspendu::class)->refusParId($this->userId));

        $this->suspendre();

        $this->assertNotNull(app(CompteSuspendu::class)->refusParId($this->userId));
    }

    public function test_sans_utilisateur_rien_n_est_refuse(): void
    {
        // Une collecte sans gérant connu ne doit pas bloquer tout le monde :
        // refuser par défaut transformerait une donnée manquante en panne.
        $this->assertNull(app(CompteSuspendu::class)->refus(null));
        $this->assertNull(app(CompteSuspendu::class)->refusParId(null));
    }
}
