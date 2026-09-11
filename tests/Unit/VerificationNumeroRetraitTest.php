<?php

namespace Tests\Unit;

use App\Services\VerificationNumeroRetrait;
use PHPUnit\Framework\TestCase;

/**
 * Le numéro de retrait devient IMMUABLE à la création (RÈGLE 3). Ce qui se joue
 * ici n'est donc pas un confort d'affichage : c'est la dernière occasion
 * d'empêcher qu'un numéro erroné soit gravé définitivement.
 *
 * Seule la partie décisionnelle est éprouvée — l'appel KYC et la détection
 * d'opérateur passent par le réseau et la base, et sont écartés par conception
 * via {@see VerificationNumeroRetrait::verdictWhatsApp()}.
 */
class VerificationNumeroRetraitTest extends TestCase
{
    /** @param array{operateur:string, kycOk:?bool, titulaire:?string} $faits */
    private function verdict(array $faits): array
    {
        return VerificationNumeroRetrait::verdictWhatsApp($faits);
    }

    public function test_compte_airtel_verifie_passe_avec_son_titulaire(): void
    {
        $v = $this->verdict([
            'operateur' => 'airtel', 'kycOk' => true, 'titulaire' => 'Daniel DOVIAKON',
        ]);

        $this->assertTrue($v['ok']);
        $this->assertSame('Daniel DOVIAKON', $v['titulaire']);
        $this->assertSame('', $v['message']);
    }

    public function test_numero_sans_compte_airtel_money_est_refuse(): void
    {
        $v = $this->verdict(['operateur' => 'airtel', 'kycOk' => false, 'titulaire' => null]);

        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('Airtel Money', $v['message']);
        // Le message doit dire POURQUOI cela compte : après création, plus rien
        // n'est modifiable. Sans cette phrase, l'utilisateur ressaisit le même
        // numéro en pensant à une erreur passagère.
        $this->assertStringContainsString('plus modifiable', $v['message']);
    }

    /**
     * Service KYC injoignable : on bloque.
     *
     * Laisser passer serait le mauvais arbitrage — un numéro gravé sans
     * vérification est irrattrapable, quelques minutes d'attente ne le sont pas.
     */
    public function test_service_indisponible_bloque_plutot_que_de_laisser_passer(): void
    {
        $v = $this->verdict(['operateur' => 'airtel', 'kycOk' => null, 'titulaire' => null]);

        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('indisponible', $v['message']);
    }

    /**
     * Moov ne propose aucun KYC : on laisse passer sans prétendre avoir vérifié,
     * exactement comme le fait l'application. Aucun titulaire n'est inventé.
     */
    public function test_moov_passe_sans_titulaire(): void
    {
        $v = $this->verdict(['operateur' => 'moov', 'kycOk' => null, 'titulaire' => null]);

        $this->assertTrue($v['ok']);
        $this->assertNull($v['titulaire']);
    }

    public function test_operateur_inconnu_est_refuse(): void
    {
        $v = $this->verdict(['operateur' => 'inconnu', 'kycOk' => null, 'titulaire' => null]);

        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('non reconnu', $v['message']);
    }

    // ── Composition du nom ───────────────────────────────────────────────────

    public function test_titulaire_assemble_prenom_et_nom(): void
    {
        $this->assertSame(
            'Daniel DOVIAKON',
            VerificationNumeroRetrait::composerTitulaire(['prenom' => 'Daniel', 'nom' => 'DOVIAKON']),
        );
    }

    /** Airtel laisse parfois l'un des deux champs vide : on ne veut pas d'espace parasite. */
    public function test_titulaire_tolere_un_champ_manquant(): void
    {
        $this->assertSame(
            'DOVIAKON',
            VerificationNumeroRetrait::composerTitulaire(['prenom' => '  ', 'nom' => 'DOVIAKON']),
        );
        $this->assertSame(
            'Daniel',
            VerificationNumeroRetrait::composerTitulaire(['prenom' => 'Daniel']),
        );
    }

    /**
     * Deux champs vides donnent null, jamais la chaîne vide : un libellé vide
     * sous le numéro laisserait croire à une vérification faite alors qu'elle
     * n'aurait rien appris.
     */
    public function test_titulaire_vide_vaut_null(): void
    {
        $this->assertNull(VerificationNumeroRetrait::composerTitulaire([]));
        $this->assertNull(VerificationNumeroRetrait::composerTitulaire(['prenom' => '', 'nom' => '   ']));
    }
}
