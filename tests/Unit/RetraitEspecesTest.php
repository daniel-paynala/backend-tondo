<?php

namespace Tests\Unit;

use App\Support\RetraitEspeces;
use PHPUnit\Framework\TestCase;

/**
 * Formats et textes du retrait en espèces. Le SMS de demande est ce que le
 * titulaire lit avant d'autoriser une sortie d'argent liquide : chaque mot
 * compte, et sa longueur aussi.
 */
class RetraitEspecesTest extends TestCase
{
    public function test_la_reference_respecte_le_format_attendu_en_base(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $ref = RetraitEspeces::genererReference();
            $this->assertTrue(RetraitEspeces::referenceValide($ref), "Référence hors format : {$ref}");
        }
    }

    public function test_une_reference_mal_formee_est_refusee(): void
    {
        foreach (['TONJICASH7K3M9Q2P', 'TONJICASH7K3M9Q2PXY', 'TONJIPAYOUT7K3M9Q2PX', 'tonjicash7k3m9q2px', ''] as $mauvaise) {
            $this->assertFalse(RetraitEspeces::referenceValide($mauvaise), "Aurait dû être refusée : {$mauvaise}");
        }
    }

    public function test_le_code_fait_six_chiffres_zeros_de_tete_compris(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->assertMatchesRegularExpression('/^[0-9]{6}$/', RetraitEspeces::genererCode());
        }
    }

    public function test_les_codes_varient(): void
    {
        $codes = array_map(fn () => RetraitEspeces::genererCode(), range(1, 50));
        $this->assertGreaterThan(45, count(array_unique($codes)));
    }

    public function test_le_sms_de_demande_dit_combien_ou_et_le_code(): void
    {
        $sms = RetraitEspeces::texteSmsDemande(150000, '123456', 'Agent Mbolo', 'ECKTPE001', '482915', 10);

        $this->assertStringContainsString('150 000 FCFA', $sms);
        $this->assertStringContainsString('123456', $sms);
        $this->assertStringContainsString('Agent Mbolo (ECKTPE001)', $sms);
        $this->assertStringContainsString('482915', $sms);
        $this->assertStringContainsString('par telephone', $sms);
    }

    /**
     * Un SMS au-delà de 160 caractères GSM est facturé double et arrive parfois
     * en deux morceaux désordonnés — le code pourrait précéder le montant.
     */
    public function test_le_sms_de_demande_tient_en_un_seul_message(): void
    {
        // Le pire cas réaliste : plafond maximal par opération, nom d'agent très
        // long, identifiant passé à 4 chiffres, validité à 2 chiffres.
        $sms = RetraitEspeces::texteSmsDemande(
            5000000, '123456', str_repeat('Agent Nzeng-Ayong Centre ', 5), 'ECKTPE1234', '482915', 15,
        );
        $conf = RetraitEspeces::texteSmsConfirmation(
            5000000, str_repeat('Agent Nzeng-Ayong Centre ', 5), 'ECKTPE1234', 'TONJICASHK7M2Q9XA3', '+241 11 22 33 44',
        );

        $this->assertLessThanOrEqual(160, strlen($sms), 'SMS de demande trop long (' . strlen($sms) . ") : {$sms}");
        $this->assertLessThanOrEqual(160, strlen($conf), 'SMS de confirmation trop long (' . strlen($conf) . ") : {$conf}");
    }

    /** Un seul accent ferait passer le message en UCS-2, limité à 70 caractères. */
    public function test_les_sms_restent_dans_l_alphabet_gsm(): void
    {
        $sms = RetraitEspeces::texteSmsDemande(1000, '123456', 'Agent Nombakélé Pépé', 'ECKTPE001', '000123', 10)
            . RetraitEspeces::texteSmsConfirmation(1000, 'Agent Nombakélé', 'ECKTPE001', 'TONJICASHK7M2Q9XA3', null);

        $this->assertSame(1, preg_match('/^[\x20-\x7E]*$/', $sms), "Caractère hors GSM dans : {$sms}");
    }

    public function test_la_confirmation_cite_la_reference(): void
    {
        $sms = RetraitEspeces::texteSmsConfirmation(150000, 'Agent Mbolo', 'ECKTPE001', 'TONJICASHK7M2Q9XA3', '+241 11 22 33 44');

        $this->assertStringContainsString('150 000 FCFA effectue', $sms);
        $this->assertStringContainsString('TONJICASHK7M2Q9XA3', $sms);
        $this->assertStringContainsString('+241 11 22 33 44', $sms);
    }

    /** Sans numéro d'assistance configuré, on n'invite pas à appeler un numéro vide. */
    public function test_la_confirmation_omet_l_appel_sans_numero_d_assistance(): void
    {
        $sms = RetraitEspeces::texteSmsConfirmation(150000, 'Agent Mbolo', 'ECKTPE001', 'TONJICASHK7M2Q9XA3', null);

        $this->assertStringNotContainsString('Appelez', $sms);
    }
}
