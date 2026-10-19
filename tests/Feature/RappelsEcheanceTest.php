<?php

namespace Tests\Feature;

use App\Contracts\PushNotifier;
use App\Services\Mail\MailgunSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les rappels avant clôture automatique.
 *
 * Deux garanties : le bon palier part au bon jour, et il ne part qu'une fois.
 * La seconde compte autant que la première — un organisateur qui reçoit cinq
 * fois « votre cagnotte ferme demain » coupe ses notifications.
 */
class RappelsEcheanceTest extends TestCase
{
    /** La durée réglée pour ces cas : une année pleine. */
    private const DUREE = 365;

    private string $projet;
    private array  $pushEnvoyes = [];
    private array  $mailsEnvoyes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->projet = (string) Str::uuid();

        Schema::create('projects', function ($t) {
            $t->string('id')->primary();
            $t->string('slug');
        });
        DB::table('projects')->insert(['id' => $this->projet, 'slug' => config('project.slug')]);

        Schema::create('users', function ($t) {
            $t->string('id')->primary();
            $t->string('email')->nullable();
        });

        Schema::create('tondo_project_config', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('operateur');
            $t->string('pays');
            $t->integer('duree_max_cagnotte_jours')->nullable();
        });

        Schema::create('tondo_cagnottes', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('user_id');
            $t->string('reference');
            // `titre`, comme en base : une fixture qui invente un nom de
            // colonne fait passer le test et casser la production.
            $t->string('titre')->nullable();
            $t->string('type');
            $t->string('statut');
            $t->timestamp('date_creation');
            $t->date('date_fin')->nullable();
            $t->date('date_echeance')->nullable();
        });

        Schema::create('tondo_rappels_echeance', function ($t) {
            $t->string('id')->primary();
            $t->string('project_id');
            $t->string('cagnotte_id');
            $t->integer('palier');
            $t->string('canaux')->nullable();
            $t->timestamp('date_creation')->useCurrent();
            $t->unique(['cagnotte_id', 'palier']);
        });

        // Les deux canaux sont capturés plutôt qu'envoyés.
        $this->app->bind(PushNotifier::class, fn () => new class($this) implements PushNotifier {
            public function __construct(private $test) {}

            public function notify(array $userIds, string $titleFr, string $bodyFr, array $data = []): void
            {
                $this->test->noterPush($data);
            }

            public function notifyOne(string $userId, string $titleFr, string $bodyFr, array $data = []): void
            {
                $this->test->noterPush($data);
            }
        });

        $this->app->bind(MailgunSender::class, fn () => new class($this) extends MailgunSender {
            public function __construct(private $test) {}

            public function envoyer(string $to, string $subject, string $html): bool
            {
                $this->test->noterMail($to);
                return true;
            }
        });
    }

    public function noterPush(array $data): void { $this->pushEnvoyes[] = $data; }
    public function noterMail(string $to): void { $this->mailsEnvoyes[] = $to; }

    private function reglage(?int $jours): void
    {
        DB::table('tondo_project_config')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->projet,
            'operateur' => 'airtel', 'pays' => 'GA',
            'duree_max_cagnotte_jours' => $jours,
        ]);
    }

    /**
     * Une cagnotte dont l'échéance tombera dans `$dans` jours.
     *
     * La date de création est calculée À REBOURS depuis la durée réglée :
     * l'échéance étant dérivée, la poser directement ne servirait à rien — la
     * commande la recalculerait au premier passage.
     */
    private function cagnotte(int $dans, ?string $email = null): string
    {
        $userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $userId, 'email' => $email]);

        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => $this->projet, 'user_id' => $userId,
            'reference' => (string) random_int(100000, 999999),
            'titre' => 'Mariage de Fidèle', 'type' => 'cagnotte_ouverte', 'statut' => 'active',
            'date_creation' => now()->timezone('Africa/Libreville')->startOfDay()
                ->addDays($dans)->subDays(self::DUREE),
        ]);

        return $id;
    }

    public function test_le_rappel_part_au_palier_attendu(): void
    {
        $this->reglage(self::DUREE);
        $this->cagnotte(7);

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(1, $this->pushEnvoyes);
        $this->assertSame('7', $this->pushEnvoyes[0]['palier']);
    }

    public function test_aucun_rappel_un_jour_qui_n_est_pas_un_palier(): void
    {
        $this->reglage(self::DUREE);
        $this->cagnotte(6);

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(0, $this->pushEnvoyes);
    }

    public function test_un_palier_ne_part_jamais_deux_fois(): void
    {
        // La garantie tenue par l'index d'unicité : deux passages le même jour
        // — un cron qui se chevauche, une relance à la main — ne doivent pas
        // envoyer deux fois le même message.
        $this->reglage(self::DUREE);
        $this->cagnotte(3);

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();
        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(1, $this->pushEnvoyes);
        $this->assertSame(1, DB::table('tondo_rappels_echeance')->count());
    }

    public function test_avant_une_semaine_le_courriel_ne_part_pas(): void
    {
        $this->reglage(self::DUREE);
        $this->cagnotte(14, email: 'daniel@example.ga');

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(1, $this->pushEnvoyes);
        $this->assertCount(0, $this->mailsEnvoyes, 'À deux semaines, le push suffit.');
    }

    public function test_a_partir_d_une_semaine_le_courriel_double_le_push(): void
    {
        $this->reglage(self::DUREE);
        $this->cagnotte(3, email: 'daniel@example.ga');

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(1, $this->pushEnvoyes);
        $this->assertSame(['daniel@example.ga'], $this->mailsEnvoyes);
    }

    public function test_sans_adresse_connue_seul_le_push_part(): void
    {
        $this->reglage(self::DUREE);
        $this->cagnotte(1); // aucune adresse

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $this->assertCount(1, $this->pushEnvoyes);
        $this->assertCount(0, $this->mailsEnvoyes);
    }

    public function test_le_reglage_pose_l_echeance_sur_une_cagnotte_qui_n_en_a_pas(): void
    {
        $this->reglage(365);

        $userId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $userId]);
        $id = (string) Str::uuid();
        DB::table('tondo_cagnottes')->insert([
            'id' => $id, 'project_id' => $this->projet, 'user_id' => $userId,
            'reference' => '123456', 'titre' => 'Rentrée', 'type' => 'cagnotte_ouverte',
            'statut' => 'active', 'date_creation' => now()->subDays(10),
        ]);

        $this->artisan('tonji:echeances-cagnottes')->assertSuccessful();

        $pose = DB::table('tondo_cagnottes')->where('id', $id)->value('date_echeance');
        $this->assertSame(
            now()->timezone('Africa/Libreville')->subDays(10)->startOfDay()->addDays(365)->toDateString(),
            $pose,
        );
    }
}
