<?php

namespace Tests\Feature;

use App\Services\Mail\MailgunSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Entrée du portail marchand : qui est refusé, et avec quelle raison.
 *
 * La réponse était volontairement neutre — la même que le numéro soit marchand
 * ou non — pour que cette page ne devienne pas un annuaire des commerçants.
 * Daniel a tranché le 2026-10-06 : laisser quelqu'un attendre devant un champ
 * à six chiffres un code qui ne viendra jamais coûte plus que ce risque. Un
 * numéro marchand est de toute façon un numéro commercial, affiché en vitrine,
 * et la limite de débit reste en place.
 *
 * Ce que ce test protège vraiment : les trois raisons de ne rien envoyer ne se
 * disent pas pareil. Confondre « pas marchand » avec « fiche sans adresse »
 * enverrait un commerçant bel et bien enregistré chercher une erreur de numéro
 * qu'il n'a pas commise.
 */
class PortailMarchandEntreeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['project.table_prefix' => 'tondo_']);
        $this->monterSchema();
    }

    /** Seules les colonnes que le portail consulte. */
    private function monterSchema(): void
    {
        Schema::dropIfExists('tondo_marchands');
        Schema::create('tondo_marchands', function ($t) {
            $t->uuid('id')->primary();
            $t->string('project_id');
            $t->string('nom');
            $t->string('numero_tel');
            $t->string('contact_email')->nullable();
            $t->boolean('actif')->default(true);
            $t->timestamps();
        });
    }

    private function creerMarchand(array $attrs = []): void
    {
        DB::table('tondo_marchands')->insert(array_merge([
            'id'            => (string) Str::uuid(),
            'project_id'    => 'proj-test',
            'nom'           => 'TRAITEUR LE BARACHOIS',
            'numero_tel'    => '+24177730634',
            'contact_email' => 'contact@barachois.ga',
            'actif'         => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $attrs));
    }

    /** Évite tout envoi réel : le test porte sur la décision, pas sur Mailgun. */
    private function faireSemblantDEnvoyer(bool $succes): void
    {
        $this->instance(MailgunSender::class, new class($succes) extends MailgunSender {
            public function __construct(private readonly bool $succes) {}

            public function envoyer(string $to, string $sujet, string $html): bool
            {
                return $this->succes;
            }
        });
    }

    public function test_un_numero_inconnu_est_refuse_des_le_premier_ecran(): void
    {
        $this->faireSemblantDEnvoyer(true);

        $this->postJson('/api/marchand/otp', ['numero' => '066000000'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'numero_inconnu');
    }

    public function test_une_fiche_desactivee_compte_comme_inconnue(): void
    {
        // Désactivée veut dire sortie du parcours : son titulaire ne doit plus
        // entrer, et il n'a pas à savoir que sa fiche existe encore en base.
        $this->creerMarchand(['actif' => false]);
        $this->faireSemblantDEnvoyer(true);

        $this->postJson('/api/marchand/otp', ['numero' => '077730634'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'numero_inconnu');
    }

    public function test_une_fiche_sans_adresse_ne_dit_pas_pas_marchand(): void
    {
        // LE cas qui justifie ce test : la fiche existe. Répondre « ce numéro
        // n'est pas marchand » ferait vérifier et re-vérifier un numéro juste.
        $this->creerMarchand(['contact_email' => null]);
        $this->faireSemblantDEnvoyer(true);

        $this->postJson('/api/marchand/otp', ['numero' => '077730634'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'envoi_impossible');
    }

    public function test_un_envoi_en_echec_ne_dit_pas_pas_marchand_non_plus(): void
    {
        $this->creerMarchand();
        $this->faireSemblantDEnvoyer(false);

        $this->postJson('/api/marchand/otp', ['numero' => '077730634'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'envoi_impossible');
    }

    public function test_un_marchand_joignable_recoit_son_code(): void
    {
        $this->creerMarchand();
        $this->faireSemblantDEnvoyer(true);

        $this->postJson('/api/marchand/otp', ['numero' => '077730634'])
            ->assertOk()
            ->assertJsonMissingPath('code');
    }

    /**
     * Sans code en cache, aucun jeton n'est délivré.
     *
     * C'est la garantie de fond : même si l'écran laissait passer, l'entrée
     * reste fermée. Elle valait déjà avant ce changement.
     */
    public function test_aucune_session_sans_code_valide(): void
    {
        $this->creerMarchand();

        $this->postJson('/api/marchand/session', ['numero' => '077730634', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'code_refuse');
    }
}
