<?php

namespace Tests\Feature;

use App\Services\WhatsApp\BotUiMenus;
use Tests\TestCase;

/**
 * Un seul chemin pour sortir l'argent d'une collecte, sur tous les canaux.
 *
 * Ce test est structurel et non fonctionnel, parce que la panne qu'il prévient
 * est structurelle : trois canaux avaient chacun leur décaissement, et celui du
 * bot WhatsApp avait silencieusement cessé de consulter le verrou des sorties.
 * Rien ne l'avait signalé — chaque copie fonctionnait, elles ne faisaient
 * simplement plus la même chose.
 *
 * Il échoue donc dès qu'un quatrième décaissement apparaît ailleurs, ou qu'un
 * chemin existant cesse de passer par le point de sortie unique.
 */
class SortiesUnifieesTest extends TestCase
{
    /**
     * Fichiers autorisés à appeler `disburse()` sur Paynala.
     *
     * - `SortieArgent` : le point de sortie unique (app, web, WhatsApp).
     * - `ReversementService` : rapatriement du solde ENTIER vers le numéro de
     *   retrait immuable (suppression de compte, cron de 18 h). Destination non
     *   choisie par un humain, d'où un chemin distinct.
     * - `TraiterRetraitsTontines` : rotation d'une tontine, qui consulte le
     *   verrou par tontine.
     * - `PaynalaPaymentService` : le client HTTP lui-même.
     */
    private const AUTORISES = [
        'app/Services/SortieArgent.php',
        'app/Services/ReversementService.php',
        'app/Console/Commands/TraiterRetraitsTontines.php',
        'app/Services/PaynalaPaymentService.php',
    ];

    public function test_aucun_quatrieme_decaissement_n_apparait(): void
    {
        $coupables = [];

        foreach ($this->fichiersPhp() as $chemin => $contenu) {
            if (in_array($chemin, self::AUTORISES, true)) {
                continue;
            }
            // `->disburse(` et non `disburse` seul : les commentaires et les
            // docblocks parlent légitimement du décaissement.
            if (str_contains($contenu, '->disburse(')) {
                $coupables[] = $chemin;
            }
        }

        $this->assertSame([], $coupables, implode("\n", array_merge(
            ['Un décaissement a été écrit hors du point de sortie unique :'],
            $coupables,
            ['Passer par App\Services\SortieArgent, qui consulte le verrou, '
                . 'compense le solde sur refus et notifie l\'enseigne.'],
        )));
    }

    public function test_chaque_chemin_de_sortie_consulte_le_verrou(): void
    {
        // Chemin => marque attendue. Soit le fichier appelle le point de sortie
        // unique (qui consulte le verrou pour lui), soit il le consulte
        // lui-même parce qu'il boucle sur plusieurs collectes.
        $attendus = [
            'app/Http/Controllers/Api/Mobile/ReversementsController.php' => 'SortieArgent',
            'app/Services/WhatsApp/GererCagnotteService.php'             => 'SortieArgent',
            'app/Console/Commands/TraiterReversementsAutoCagnottes.php'  => 'SortiesAutorisees',
            'app/Console/Commands/TraiterRetraitsTontines.php'           => 'SortiesAutorisees',
        ];

        foreach ($attendus as $chemin => $marque) {
            $contenu = file_get_contents(base_path($chemin)) ?: '';
            $this->assertStringContainsString(
                $marque,
                $contenu,
                "{$chemin} ne consulte plus le verrou des sorties ({$marque} absent).",
            );
        }
    }

    public function test_le_bot_consulte_le_verrou_avant_chaque_sortie(): void
    {
        $bot = file_get_contents(base_path('app/Services/WhatsApp/BotService.php')) ?: '';

        // Le verrou est relu au clic, comme l'app le relit avant d'ouvrir
        // l'écran : une fois pour le transfert, une fois pour le paiement.
        $this->assertSame(
            2,
            substr_count($bot, '$this->sortiesDe($cagnotte)[\''),
            'Le bot doit relire le verrou à l\'entrée du transfert ET du paiement marchand.',
        );
    }

    /**
     * `payout.canal` n'est pas le canal d'origine, et ne doit pas le devenir.
     *
     * La colonne dit le RAIL du décaissement — 'mobile_money' ou 'especes' —
     * et une contrainte CHECK en base n'accepte que ces deux valeurs. Y écrire
     * « app », « web » ou « whatsapp » ferait échouer CHAQUE transfert, sur
     * tous les canaux à la fois. Le canal d'origine vit dans `request.canal`.
     */
    public function test_le_canal_d_origine_ne_part_pas_dans_la_colonne_rail(): void
    {
        $sortie = file_get_contents(base_path('app/Services/SortieArgent.php')) ?: '';

        $this->assertStringNotContainsString(
            "'canal'         => \$canal",
            $sortie,
            'Le canal d\'origine ne doit pas être écrit dans la colonne payout.canal '
                . "(contrainte CHECK : 'mobile_money' ou 'especes' uniquement).",
        );

        // Et il doit bien rester tracé quelque part, sinon on ne sait plus d'où
        // part l'argent.
        $this->assertStringContainsString(
            "'canal'               => \$canal",
            $sortie,
            'Le canal d\'origine doit rester tracé dans payout.request.',
        );
    }

    /**
     * Le bot formate le taux marchand avec le formateur partagé, pas à la main.
     *
     * Les taux sont stockés en DÉCIMAL — 0.03 vaut 3 %. Oublier la conversion
     * ne fait rien échouer : ça annonce « 0,03 % » à qui sera prélevé de 3 %.
     * C'est arrivé sur trois écrans à la fois, d'où ce garde-fou là où un
     * `number_format` écrit à la main le réintroduirait.
     */
    public function test_le_taux_marchand_passe_par_le_formateur_partage(): void
    {
        $bot = file_get_contents(base_path('app/Services/WhatsApp/BotService.php')) ?: '';

        $this->assertStringContainsString(
            'Taux::pourcentage($frais)',
            $bot,
            'Le taux marchand doit être formaté par App\\Support\\Taux.',
        );
        $this->assertStringNotContainsString(
            'number_format($frais',
            $bot,
            'Un taux formaté à la main oublierait la conversion décimal → pourcentage.',
        );
    }

    /**
     * Les numéros du menu texte et ceux de la liste tappable sont les mêmes.
     *
     * WhatsApp renvoie l'`id` de la ligne choisie, qui est réinjecté tel quel
     * dans le dispatch du bot texte. Un décalage entre les deux ferait fermer
     * une cagnotte à qui a tapé « Payer ».
     */
    public function test_les_identifiants_du_menu_tappable_suivent_le_texte(): void
    {
        foreach ([false, true] as $marchandActif) {
            config(['tondo.paiement_marchand_actif' => $marchandActif]);

            $spec = BotUiMenus::pour('gerer.cagnotte');
            $ids  = array_column($spec['sections'][0]['lignes'], 'id');

            $attendus = $marchandActif
                ? ['1', '2', '3', '4', '5']
                : ['1', '2', '4', '5'];

            $this->assertSame(
                $attendus,
                $ids,
                'Les numéros du menu ne doivent pas glisser quand « Payer » est fermé.',
            );
        }
    }

    public function test_payer_un_commerce_reste_derriere_son_drapeau(): void
    {
        config(['tondo.paiement_marchand_actif' => false]);
        $ids = array_column(BotUiMenus::pour('gerer.cagnotte')['sections'][0]['lignes'], 'id');
        $this->assertNotContains('3', $ids);

        config(['tondo.paiement_marchand_actif' => true]);
        $ids = array_column(BotUiMenus::pour('gerer.cagnotte')['sections'][0]['lignes'], 'id');
        $this->assertContains('3', $ids);
    }

    /**
     * Tous les fichiers PHP de `app/`, indexés par chemin relatif.
     *
     * @return array<string, string>
     */
    private function fichiersPhp(): array
    {
        $fichiers  = [];
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app')),
        );

        foreach ($iterateur as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }
            $chemin = ltrim(str_replace(base_path(), '', $fichier->getPathname()), '/');
            $fichiers[$chemin] = file_get_contents($fichier->getPathname()) ?: '';
        }

        return $fichiers;
    }
}
