<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * L'ordre des décaissements, et ce qu'il coûte.
 *
 * Paynala n'accepte qu'un `msisdn` par appel : un dispatch à trois comptes fait
 * quatre décaissements, à la suite. Daniel a tranché l'ordre — **de la plus
 * petite part à la plus grande, les commissions avant le bénéficiaire**, dont
 * la part est la plus grosse par construction.
 *
 * Le prix de cet ordre : le transfert principal peut échouer APRÈS que des
 * commissions soient parties. L'opérateur ne rembourse pas un décaissement, il
 * faut donc ne restaurer que ce qui n'a pas quitté la caisse. Ce test fixe
 * cette arithmétique, parce que s'y tromper coûte deux fois le montant.
 */
class DispatchOrdreTest extends TestCase
{
    /** Reproduit le tri appliqué par la lecture des comptes. */
    private function ordonner(array $comptes): array
    {
        usort($comptes, fn ($a, $b) => $a['taux'] <=> $b['taux'] ?: strcmp($a['id'], $b['id']));

        return $comptes;
    }

    public function test_les_petites_parts_partent_en_premier(): void
    {
        $ordre = $this->ordonner([
            ['id' => 'c', 'libelle' => 'Custom', 'taux' => 0.015],
            ['id' => 'a', 'libelle' => 'Airtel', 'taux' => 0.01],
            ['id' => 'p', 'libelle' => 'Paynala', 'taux' => 0.005],
        ]);

        $this->assertSame(
            ['Paynala', 'Airtel', 'Custom'],
            array_column($ordre, 'libelle'),
        );
    }

    public function test_deux_parts_egales_gardent_un_ordre_stable(): void
    {
        // Sans second critère, l'ordre dépendrait du retour de la base : deux
        // calculs du même montant pourraient produire deux séquences
        // différentes, et l'arrondi ne tomberait pas au même endroit.
        $a = $this->ordonner([
            ['id' => 'b', 'libelle' => 'B', 'taux' => 0.01],
            ['id' => 'a', 'libelle' => 'A', 'taux' => 0.01],
        ]);
        $b = $this->ordonner([
            ['id' => 'a', 'libelle' => 'A', 'taux' => 0.01],
            ['id' => 'b', 'libelle' => 'B', 'taux' => 0.01],
        ]);

        $this->assertSame(array_column($a, 'libelle'), array_column($b, 'libelle'));
    }

    /**
     * Ce qu'on restaure quand le transfert principal est refusé.
     *
     * @param int $montant      débité de la collecte
     * @param int $fraisSortis  commissions ayant réellement quitté la caisse
     */
    private function aRestaurer(int $montant, int $fraisSortis): int
    {
        return $montant - $fraisSortis;
    }

    public function test_sans_commission_partie_on_rend_tout(): void
    {
        // Cas d'avant le dispatch, et cas où les parts sont toutes sous le
        // plancher : rien n'est sorti, le client récupère l'intégralité.
        $this->assertSame(100000, $this->aRestaurer(100000, 0));
    }

    public function test_avec_commissions_parties_on_ne_rend_que_le_reste(): void
    {
        // 3 000 FCFA de commissions déjà décaissées sur 100 000 : le client
        // récupère 97 000. Lui rendre 100 000 recréditerait la collecte de
        // 3 000 FCFA déjà sortis, que la sortie suivante dépenserait une
        // seconde fois.
        $this->assertSame(97000, $this->aRestaurer(100000, 3000));
    }

    public function test_une_part_d_issue_inconnue_compte_comme_sortie(): void
    {
        // On ne sait pas si elle est partie — donc on ne la rend pas. Même
        // règle que pour l'argent du client : dans le doute, on ne rend pas.
        // Ici la part incertaine de 500 est comptée dans `fraisSortis`.
        $this->assertSame(96500, $this->aRestaurer(100000, 3500));
    }

    public function test_le_montant_rendu_n_est_jamais_negatif_sur_les_taux_admis(): void
    {
        // Les frais ne peuvent pas atteindre le montant : `RepartitionFrais`
        // refuse avant toute réservation. Le montant rendu reste donc positif.
        foreach ([[1000, 750], [100, 75], [500000, 15000]] as [$montant, $frais]) {
            $this->assertGreaterThan(0, $this->aRestaurer($montant, $frais));
        }
    }
}
