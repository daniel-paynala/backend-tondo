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

        // On isole la CHARGE de l'insert, pas le fichier entier : `canal` est
        // un mot légitime partout ailleurs — dans le JSON de la requête, dans
        // les journaux, dans les commentaires. Ce qui est interdit, c'est une
        // clé de colonne.
        $ouvre  = strpos($sortie, "DB::table(project_table('payout'))->insert([");
        $this->assertNotFalse($ouvre, "L'insert du payout est introuvable — test à réécrire.");

        // Jusqu'à `request`, où commence le JSON : au-delà, `canal` est une
        // clé de ce JSON et non une colonne.
        $jusqua  = strpos($sortie, "'request'", $ouvre);
        $colonnes = substr($sortie, $ouvre, $jusqua - $ouvre);

        $this->assertStringNotContainsString(
            "'canal'",
            $colonnes,
            "La colonne payout.canal dit le RAIL du décaissement et n'accepte que "
                . "'mobile_money' ou 'especes'. Y écrire le canal d'origine ferait échouer "
                . 'CHAQUE transfert, sur tous les canaux à la fois.',
        );

        // Et le canal d'origine doit bien rester tracé, sinon on ne sait plus
        // d'où part l'argent. Insensible à l'alignement : un test qui échoue
        // parce qu'une colonne a bougé d'un espace apprend à être ignoré.
        $this->assertMatchesRegularExpression(
            "/'canal'\s*=>\s*\\\$canal,/",
            substr($sortie, $jusqua),
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
            $spec = BotUiMenus::pour('gerer.cagnotte', ['menu_marchand_actif' => $marchandActif]);
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

    /**
     * « Payer » suit l'interrupteur du dashboard, et rien d'autre.
     *
     * Il dépendait d'un drapeau de compilation, donc d'un rebuild et d'une
     * variable d'environnement par canal. Désormais c'est le verrou global du
     * type de compte qui décide — un seul interrupteur, qui ferme partout.
     */
    public function test_payer_un_commerce_suit_l_interrupteur_du_dashboard(): void
    {
        $ids = array_column(
            BotUiMenus::pour('gerer.cagnotte', ['menu_marchand_actif' => false])['sections'][0]['lignes'],
            'id',
        );
        $this->assertNotContains('3', $ids);

        $ids = array_column(
            BotUiMenus::pour('gerer.cagnotte', ['menu_marchand_actif' => true])['sections'][0]['lignes'],
            'id',
        );
        $this->assertContains('3', $ids);
    }

    /**
     * Contexte absent = service fermé.
     *
     * Une session sans l'information ne doit pas faire apparaître une option
     * que le texte n'a pas annoncée : c'est le désalignement qu'on évite.
     */
    public function test_sans_contexte_payer_n_apparait_pas(): void
    {
        $ids = array_column(
            BotUiMenus::pour('gerer.cagnotte')['sections'][0]['lignes'],
            'id',
        );

        $this->assertSame(['1', '2', '4', '5'], $ids);
    }

    /**
     * Le verrou distingue « service fermé » de « collecte suspendue ».
     *
     * Les deux rendent le paiement impossible, mais pas de la même façon à
     * l'écran : service fermé → le bouton n'est pas construit ; collecte
     * verrouillée → il est grisé avec sa raison. Sans cette distinction, un
     * service non ouvert s'afficherait comme « momentanément suspendu ».
     */
    public function test_le_verrou_expose_la_disponibilite_du_service(): void
    {
        $retour = new \ReflectionMethod(\App\Services\SortiesAutorisees::class, 'pour');

        $this->assertStringContainsString(
            'marchand_actif',
            (string) $retour->getDocComment(),
            'pour() doit documenter marchand_actif : les interfaces s\'en servent '
                . 'pour ne PAS construire le bouton quand le service est fermé.',
        );
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
