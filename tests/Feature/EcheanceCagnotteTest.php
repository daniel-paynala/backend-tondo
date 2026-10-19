<?php

namespace Tests\Feature;

use App\Services\EcheanceCagnotte;
use App\Services\TondoConfigService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * La durée de vie d'une cagnotte ouverte.
 *
 * Tout tient dans un service, et ces tests en sont le contrat : l'échéance est
 * dérivée de la date de création, elle s'allonge quand le réglage s'allonge,
 * et une cagnotte déjà trop vieille n'est jamais fermée le soir même.
 */
class EcheanceCagnotteTest extends TestCase
{
    private Carbon $aujourdhui;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aujourdhui = Carbon::parse('2026-10-19')->startOfDay();
    }

    /** Le service, avec une durée réglée (ou aucune). */
    private function service(?int $jours): EcheanceCagnotte
    {
        return new EcheanceCagnotte(new class($jours) extends TondoConfigService {
            public function __construct(private ?int $jours) {}

            public function getOperatorConfig(string $projectId, ?string $operateur = null, ?string $pays = null): array
            {
                return ['duree_max_cagnotte_jours' => $this->jours];
            }
        });
    }

    private function cagnotte(string $creation, ?string $echeance = null, ?string $fin = null, string $type = 'cagnotte_ouverte'): object
    {
        return (object) [
            'type'          => $type,
            'date_creation' => $creation,
            'date_echeance' => $echeance,
            'date_fin'      => $fin,
        ];
    }

    public function test_sans_reglage_aucune_echeance(): void
    {
        // Poser la colonne ne doit fermer aucune cagnotte tant que personne
        // n'a décidé d'une durée. L'absence ne vaut pas zéro.
        $this->assertNull(
            $this->service(null)->calculer($this->cagnotte('2026-01-01'), null, $this->aujourdhui),
        );
    }

    public function test_l_echeance_se_compte_depuis_la_creation(): void
    {
        $service = $this->service(180);

        $this->assertSame(
            '2026-12-28',
            $service->calculer($this->cagnotte('2026-07-01'), 180, $this->aujourdhui),
        );
    }

    public function test_allonger_le_reglage_allonge_les_cagnottes_existantes(): void
    {
        // La demande explicite du 2026-10-19 : passer de six mois à un an doit
        // rallonger le parc existant, sans toucher une ligne à la main. C'est
        // ce que donne un calcul dérivé plutôt qu'une date figée.
        $creee = '2026-07-01';

        $aSixMois = $this->service(180)->calculer($this->cagnotte($creee), 180, $this->aujourdhui);
        $aUnAn    = $this->service(365)->calculer($this->cagnotte($creee, $aSixMois), 365, $this->aujourdhui);

        $this->assertSame('2026-12-28', $aSixMois);
        $this->assertSame('2027-07-01', $aUnAn);
        $this->assertGreaterThan($aSixMois, $aUnAn, 'La cagnotte doit avoir gagné du temps.');
    }

    public function test_une_cagnotte_deja_trop_vieille_obtient_un_repit(): void
    {
        // Le jour où la durée est fixée, une cagnotte plus ancienne ne doit pas
        // disparaître le soir même : elle reçoit trente jours, le temps que la
        // série de rappels se déroule.
        $echeance = $this->service(30)->calculer(
            $this->cagnotte('2026-01-01'), 30, $this->aujourdhui,
        );

        $this->assertSame('2026-11-18', $echeance);
    }

    public function test_le_repit_ne_se_repousse_pas_chaque_jour(): void
    {
        // Le piège de la dérivation : recalculer aveuglément redonnerait
        // trente jours à chaque passage, et la cagnotte ne se fermerait
        // JAMAIS. Une fois posé, le répit est collant.
        $service = $this->service(30);
        $vieille = $this->cagnotte('2026-01-01');

        $premier = $service->calculer($vieille, 30, $this->aujourdhui);
        $second  = $service->calculer(
            $this->cagnotte('2026-01-01', $premier), 30, $this->aujourdhui->copy()->addDays(10),
        );

        $this->assertSame($premier, $second, 'Le répit doit rester à sa date.');
    }

    public function test_une_tontine_n_est_pas_concernee(): void
    {
        // Sa durée tient au nombre de participants multiplié par la
        // périodicité. La couper fermerait un cycle en cours.
        $this->assertNull(
            $this->service(30)->calculer(
                $this->cagnotte('2026-01-01', type: 'tontine_periodique'), 30, $this->aujourdhui,
            ),
        );
    }

    public function test_la_duree_plafonne_mais_ne_prolonge_pas(): void
    {
        // L'organisateur s'est donné trois semaines : il les garde, même si le
        // réglage autorise un an.
        $service = $this->service(365);

        $this->assertSame(
            '2026-11-09',
            $service->effective($this->cagnotte('2026-10-19', echeance: '2027-10-19', fin: '2026-11-09')),
        );
    }

    public function test_l_echeance_ferme_quand_elle_precede_la_date_choisie(): void
    {
        $service = $this->service(30);

        $this->assertSame(
            '2026-11-18',
            $service->effective($this->cagnotte('2026-10-19', echeance: '2026-11-18', fin: '2027-01-01')),
        );
    }

    public function test_la_date_s_affiche_avant_meme_que_le_cron_soit_passe(): void
    {
        // Le défaut trouvé le 2026-10-19 : la durée était réglée, et l'app
        // n'affichait rien — la colonne n'est écrite que par le passage de 8 h.
        $service = $this->service(365);

        $this->assertSame(
            '2027-07-01',
            $service->pourAffichage($this->cagnotte('2026-07-01'), 365, $this->aujourdhui),
            'La date montrée doit être celle que le prochain passage écrira.',
        );
    }

    public function test_l_affichage_respecte_le_repit(): void
    {
        // Même avec rien en base, une cagnotte trop vieille montre la date de
        // grâce — pas une date passée qui laisserait croire à une fermeture
        // imminente.
        $service = $this->service(30);

        $this->assertSame(
            '2026-11-18',
            $service->pourAffichage($this->cagnotte('2026-01-01'), 30, $this->aujourdhui),
        );
    }

    public function test_l_affichage_prefere_la_colonne_quand_elle_existe(): void
    {
        // La colonne fait foi dès qu'elle est posée : c'est elle que le cron
        // de clôture lira, l'écran ne doit pas annoncer autre chose.
        $service = $this->service(365);

        $this->assertSame(
            '2026-11-18',
            $service->pourAffichage($this->cagnotte('2026-07-01', echeance: '2026-11-18'), 365, $this->aujourdhui),
        );
    }

    public function test_les_paliers_tombent_sur_le_jour_exact(): void
    {
        $service = $this->service(30);

        $this->assertSame(7, $service->palierDuJour('2026-10-26', $this->aujourdhui));
        $this->assertSame(1, $service->palierDuJour('2026-10-20', $this->aujourdhui));
        $this->assertNull($service->palierDuJour('2026-10-25', $this->aujourdhui), 'J-6 n\'est pas un palier.');
        $this->assertNull($service->palierDuJour(null, $this->aujourdhui));
    }

    public function test_le_courriel_ne_double_le_push_qu_a_partir_d_une_semaine(): void
    {
        // À un mois, l'échéance n'est pas une urgence et un courriel de plus
        // se range sans être lu.
        $this->assertNotContains(30, EcheanceCagnotte::PALIERS_AVEC_EMAIL);
        $this->assertNotContains(14, EcheanceCagnotte::PALIERS_AVEC_EMAIL);
        $this->assertSame([7, 3, 1], EcheanceCagnotte::PALIERS_AVEC_EMAIL);
    }
}
