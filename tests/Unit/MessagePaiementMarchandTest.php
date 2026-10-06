<?php

namespace Tests\Unit;

use App\Support\MessagePaiementMarchand;
use PHPUnit\Framework\TestCase;

/**
 * Le message du marchand est une preuve qu'il garde : il mérite d'être
 * vérifié ligne à ligne.
 */
class MessagePaiementMarchandTest extends TestCase
{
    /** @return array<string, mixed> */
    private function donnees(array $surcharge = []): array
    {
        return array_merge([
            'montant'    => 150000,
            'reference'  => 'TM-BPU2JID53',
            'cagnotte'   => 'Anniversaire Maman',
            'payeur'     => 'DOVI AKON Daniel',
            'date_heure' => '05/10/2026 à 09:13',
        ], $surcharge);
    }

    public function test_le_message_suit_le_format_convenu(): void
    {
        $attendu = implode("\n", [
            'TONJI - Paiement de 150 000 FCFA reçu.',
            'Ref TM-BPU2JID53',
            'Cagnotte : Anniversaire Maman',
            'Payé par : DOVI AKON Daniel',
            '05/10/2026 à 09:13',
        ]);

        $this->assertSame($attendu, MessagePaiementMarchand::pourMarchand($this->donnees()));
    }

    public function test_la_ligne_portail_n_apparait_que_s_il_est_configure(): void
    {
        // Tant que l'adresse n'est pas arrêtée, pas de ligne : un lien mort
        // dans un SMS facturé est pire que pas de lien du tout.
        foreach ([null, '', '   '] as $vide) {
            $this->assertStringNotContainsString(
                'Detail:',
                MessagePaiementMarchand::pourMarchand($this->donnees(['url_portail' => $vide])),
            );
        }

        $avec = MessagePaiementMarchand::pourMarchand(
            $this->donnees(['url_portail' => 'https://tonji.ga/m']),
        );
        $this->assertStringEndsWith("\nDetail: https://tonji.ga/m", $avec);
    }

    public function test_le_montant_est_espace_par_milliers(): void
    {
        $this->assertSame('150 000', MessagePaiementMarchand::montant(150000));
        $this->assertSame('100', MessagePaiementMarchand::montant(100));
        $this->assertSame('1 000 000', MessagePaiementMarchand::montant(1000000));
    }

    public function test_l_heure_est_celle_de_libreville_pas_celle_du_serveur(): void
    {
        // L'application tourne en UTC. Imprimer l'heure brute donnerait au
        // marchand une heure de retard sur ce qu'affiche son téléphone.
        $this->assertSame(
            '05/10/2026 à 09:13',
            MessagePaiementMarchand::dateHeure('2026-10-05 08:13:00+00'),
        );
        // Passage de minuit : la date change aussi, pas seulement l'heure.
        $this->assertSame(
            '06/10/2026 à 00:30',
            MessagePaiementMarchand::dateHeure('2026-10-05 23:30:00+00'),
        );
    }

    public function test_la_notification_du_payeur_nomme_l_enseigne(): void
    {
        [$titre, $corps] = MessagePaiementMarchand::pourPayeur([
            'montant'  => 150000,
            'marchand' => 'TRAITEUR LE BARACHOIS',
        ]);

        $this->assertSame('Paiement effectué', $titre);
        $this->assertSame('150 000 FCFA payés à TRAITEUR LE BARACHOIS.', $corps);
    }
}
