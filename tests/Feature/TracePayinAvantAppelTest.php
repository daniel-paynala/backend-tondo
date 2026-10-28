<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La trace d'un encaissement est écrite AVANT que l'opérateur soit appelé.
 *
 * L'ordre inverse a laissé passer un trou par lequel de l'argent disparaît
 * sans laisser de trace : la notification Airtel partait, le client pouvait
 * confirmer et être débité, et si l'écriture en base échouait ensuite, aucune
 * ligne `payin` n'existait. Or `tonji:reconcilier-payins` ne balaie que les
 * payins `initie` — sans ligne, il n'avait rien à rattraper.
 *
 * Un double appui suffisait : le second passage avait déjà poussé sa
 * notification quand son insertion de participant violait l'index unique,
 * annulant toute la transaction.
 *
 * Ce test porte sur l'ORDRE DU CODE et non sur le comportement : exercer ce
 * chemin demanderait la pile HTTP authentifiée, l'opérateur simulé et six
 * tables. Le défaut, lui, est un ordre d'instructions — c'est donc lui qu'on
 * garde. Même procédé que `RetraitEspecesFermeTest` pour le défaut d'un drapeau.
 */
class TracePayinAvantAppelTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(
            app_path('Http/Controllers/Api/Mobile/CotisationsController.php'),
        ) ?: '';
    }

    public function test_la_ligne_payin_est_ecrite_avant_l_appel_a_l_operateur(): void
    {
        $src = $this->source();

        $ecriture = strpos($src, "DB::table(project_table('payin'))->insert(");
        $appel    = strpos($src, '$this->paynala->createPayment(');

        $this->assertNotFalse($ecriture, 'L\'insertion du payin a disparu.');
        $this->assertNotFalse($appel, 'L\'appel de paiement a disparu.');
        $this->assertLessThan(
            $appel,
            $ecriture,
            'La trace doit précéder l\'appel : sinon un échec entre les deux laisse '
            . 'un client débité sans ligne en base, et la réconciliation est aveugle.',
        );
    }

    public function test_le_filet_couvre_aussi_les_coupures_reseau(): void
    {
        // `ConnectionException` n'hérite PAS de `RuntimeException` : un délai
        // dépassé passait à travers l'ancien `catch`. Or c'est justement le cas
        // où la demande peut avoir atteint l'opérateur malgré l'erreur.
        $src = $this->source();
        $apres = substr($src, strpos($src, '$this->paynala->createPayment('));

        $this->assertStringContainsString(
            'catch (\Throwable',
            substr($apres, 0, 600),
            'L\'appel de paiement doit être protégé par un filet qui couvre aussi '
            . 'les exceptions réseau.',
        );
    }
}
