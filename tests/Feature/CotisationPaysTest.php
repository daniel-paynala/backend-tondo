<?php

namespace Tests\Feature;

use App\Services\CollecteAutorisee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'étanchéité des écosystèmes, au moment de cotiser.
 *
 * Décision du 2026-10-14 : une collecte gabonaise et une collecte sénégalaise
 * sont deux mondes. XAF et XOF sont deux monnaies sous deux banques centrales,
 * et une cotisation d'un pays à l'autre serait un transfert transfrontalier
 * que nous ne savons pas assumer.
 *
 * Le pays sert de clé, jamais l'indicatif : celui-ci cohabitait sous « +241 »
 * et « 241 » en base, et une comparaison ratée tombe du mauvais côté — elle
 * laisse passer.
 */
class CotisationPaysTest extends TestCase
{
    private string $projet = 'proj-test';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id')->nullable();
            $t->string('type_compte')->nullable();
            $t->string('pays')->nullable();
            $t->timestamp('suspendu_at')->nullable();
        });
        Schema::create('tondo_project_config', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->boolean('actif')->nullable();
            $t->text('capacites')->nullable();
        });
    }

    private function pays(string $pays, array $capacites): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => 'op_' . strtolower($pays), 'pays' => $pays,
            'actif' => true, 'capacites' => json_encode($capacites),
        ]);
    }

    private function cotisant(?string $pays): object
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id, 'project_id' => $this->projet,
            'type_compte' => 'particulier', 'pays' => $pays,
        ]);

        return DB::table('users')->where('id', $id)->first();
    }

    /** Une collecte en mémoire : la garde ne lit que ses champs. */
    private function collecte(?string $pays, ?string $gerant = null): object
    {
        return (object) [
            'project_id'         => $this->projet,
            'user_id'            => $gerant,
            'pays'               => $pays,
            'visibilite'         => 'prive',
            'statut_validation'  => null,
            'type'               => 'cagnotte_ouverte',
            'date_fin'           => null,
            'cotisation_bloquee' => false,
        ];
    }

    private function refus(?string $paysCollecte, ?string $paysCotisant): ?string
    {
        return app(CollecteAutorisee::class)
            ->refus($this->collecte($paysCollecte), $this->cotisant($paysCotisant));
    }

    public function test_meme_pays_et_encaissement_disponible_passe(): void
    {
        $this->pays('GA', ['payin' => true, 'payout' => true]);

        $this->assertNull($this->refus('GA', 'GA'));
    }

    public function test_un_cotisant_d_un_autre_pays_est_refuse(): void
    {
        // Le cas concret : les liens d'invitation circulent sur WhatsApp, la
        // diaspora les reçoit. Le refus doit être clair, pas un bouton muet.
        $this->pays('GA', ['payin' => true, 'payout' => true]);
        $this->pays('SN', ['payin' => true, 'payout' => true]);

        $refus = $this->refus('SN', 'GA');

        $this->assertNotNull($refus);
        $this->assertStringContainsString('depuis votre pays', $refus);
    }

    public function test_sans_moyen_de_paiement_local_le_message_le_dit(): void
    {
        $this->pays('SN', ['payin' => false, 'payout' => true]);

        $this->assertSame(
            'Aucun moyen de paiement local pour le moment.',
            $this->refus('SN', 'SN'),
        );
    }

    public function test_un_cotisant_sans_pays_est_oriente_vers_le_support(): void
    {
        $this->pays('GA', ['payin' => true, 'payout' => true]);

        $this->assertStringContainsString('support', $this->refus('GA', null));
    }

    public function test_une_collecte_sans_pays_suit_celui_de_son_organisateur(): void
    {
        // Les collectes créées avant le rattachement n'ont pas de pays en
        // colonne. Elles restent ouvertes aux leurs, par le pays de celui qui
        // les a créées.
        $this->pays('GA', ['payin' => true, 'payout' => true]);
        $gerant = $this->cotisant('GA');

        $this->assertNull(app(CollecteAutorisee::class)->refus(
            $this->collecte(null, $gerant->id),
            $this->cotisant('GA'),
        ));
    }

    public function test_une_collecte_sans_pays_reste_fermee_aux_autres(): void
    {
        // La fuite que le repli ferme : sans lui, une collecte sans pays était
        // atteignable depuis TOUS les pays qui encaissent, et l'étanchéité
        // sautait silencieusement dès le deuxième pays ouvert.
        $this->pays('GA', ['payin' => true]);
        $this->pays('SN', ['payin' => true]);
        $gerant = $this->cotisant('GA');

        $this->assertStringContainsString('depuis votre pays', app(CollecteAutorisee::class)->refus(
            $this->collecte(null, $gerant->id),
            $this->cotisant('SN'),
        ));
    }

    public function test_une_collecte_sans_pays_ni_organisateur_est_fermee(): void
    {
        // Un pays qu'on ne sait pas nommer ne se compare pas. On ferme plutôt
        // que d'ouvrir faute de savoir où est la frontière, et le message dit
        // que ça se règle avec le support — pas que c'est définitif.
        $this->pays('GA', ['payin' => true]);

        $this->assertStringContainsString('régularisation', $this->refus(null, 'GA'));
    }

    public function test_l_etancheite_prime_sur_la_capacite(): void
    {
        // Un Gabonais devant une collecte sénégalaise doit lire qu'elle ne lui
        // est pas accessible — pas qu'il manque un moyen de paiement, ce qui
        // lui ferait croire que ça s'arrangera.
        $this->pays('GA', ['payin' => true]);
        $this->pays('SN', ['payin' => false]);

        $this->assertStringContainsString('depuis votre pays', $this->refus('SN', 'GA'));
    }
}
