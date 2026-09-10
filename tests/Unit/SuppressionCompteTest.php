<?php

namespace Tests\Unit;

use App\Support\SuppressionCompte;
use PHPUnit\Framework\TestCase;

/**
 * Règles de suppression d'un compte.
 *
 * Deux décisions y sont verrouillées, l'une et l'autre à conséquence
 * irréversible : purger une ligne qui portait de la comptabilité, ou supprimer
 * un compte dont l'argent n'est pas sorti.
 */
class SuppressionCompteTest extends TestCase
{
    /** @return array<string, bool> */
    private function empreinte(array $surcharges = []): array
    {
        return array_merge(array_fill_keys(SuppressionCompte::TRACES, false), $surcharges);
    }

    // ── Purge vs anonymisation ──────────────────────────────────────────────

    public function test_un_compte_sans_aucune_trace_est_purge(): void
    {
        $this->assertTrue(SuppressionCompte::doitPurger($this->empreinte()));
    }

    /**
     * Chaque trace, seule, doit suffire à imposer l'anonymisation. Le test les
     * parcourt toutes : ajouter une trace sans l'ajouter ici serait invisible.
     */
    public function test_chaque_trace_seule_impose_l_anonymisation(): void
    {
        foreach (SuppressionCompte::TRACES as $trace) {
            $this->assertFalse(
                SuppressionCompte::doitPurger($this->empreinte([$trace => true])),
                "La trace « {$trace} » devrait interdire la purge.",
            );
        }
    }

    public function test_le_cotisant_chez_les_autres_n_est_pas_purge(): void
    {
        // N'a créé aucune cagnotte mais a cotisé : il existe des lignes de
        // paiement à son nom, l'historique comptable doit survivre.
        $this->assertFalse(SuppressionCompte::doitPurger(
            $this->empreinte(['paiements' => true]),
        ));
    }

    public function test_une_participation_sans_versement_n_empeche_pas_la_purge(): void
    {
        // Ajouté à une cagnotte par un organisateur, n'a jamais payé : la ligne
        // de participation ne porte que son nom et son numéro, rien de comptable.
        $this->assertTrue(SuppressionCompte::doitPurger($this->empreinte()));
    }

    public function test_une_cle_inconnue_a_vrai_bloque_aussi(): void
    {
        // Robustesse : si une trace est ajoutée au contrôleur sans passer par
        // TRACES, elle doit quand même interdire la purge plutôt que d'être
        // ignorée silencieusement.
        $this->assertFalse(SuppressionCompte::doitPurger(['trace_future' => true]));
    }

    // ── Décaissements non résolus ───────────────────────────────────────────

    public function test_un_payout_en_vol_bloque_la_suppression(): void
    {
        $this->assertTrue(SuppressionCompte::payoutNonResolu('initie', null));
        $this->assertTrue(SuppressionCompte::payoutNonResolu('en_cours', null));
    }

    public function test_un_echec_non_compense_bloque(): void
    {
        // Antérieur à la compensation : le solde a été décrémenté et jamais
        // restauré, l'argent est réellement en l'air.
        $this->assertTrue(SuppressionCompte::payoutNonResolu('echec', null));
        $this->assertTrue(SuppressionCompte::payoutNonResolu('echec', false));
    }

    public function test_un_echec_compense_ne_bloque_pas(): void
    {
        // Refus explicite de Paynala : l'argent n'est pas parti et le solde est
        // revenu dans la cagnotte. Situation close.
        $this->assertFalse(SuppressionCompte::payoutNonResolu('echec', true));
    }

    public function test_un_payout_reussi_ne_bloque_pas(): void
    {
        $this->assertFalse(SuppressionCompte::payoutNonResolu('succes', null));
        $this->assertFalse(SuppressionCompte::payoutNonResolu('annule', null));
    }
}
