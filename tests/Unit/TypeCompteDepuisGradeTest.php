<?php

namespace Tests\Unit;

use App\Services\PaynalaPaymentService;
use PHPUnit\Framework\TestCase;

/**
 * Dérivation du type de compte Tonji depuis le grade Airtel.
 *
 * Grades fournis par Daniel le 2026-09-09. Tout grade hors des deux listes doit
 * donner `null` : le profil n'est pas reconnu et l'inscription est bloquée,
 * plutôt que rattachée par défaut à une catégorie. Un mauvais rattachement se
 * paierait plus tard — le type pilote les plafonds et l'accès aux cagnottes
 * publiques.
 */
class TypeCompteDepuisGradeTest extends TestCase
{
    public function test_les_grades_particulier(): void
    {
        $this->assertSame('particulier', PaynalaPaymentService::typeCompteDepuisGrade('SUBS'));
        $this->assertSame('particulier', PaynalaPaymentService::typeCompteDepuisGrade('TEMP'));
    }

    public function test_les_grades_association(): void
    {
        $this->assertSame('association', PaynalaPaymentService::typeCompteDepuisGrade('MERCHVIP'));
        $this->assertSame('association', PaynalaPaymentService::typeCompteDepuisGrade('HMERA'));
    }

    public function test_tout_autre_grade_n_est_pas_reconnu(): void
    {
        // Notamment MERCH : un marchand ordinaire n'est PAS une association.
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade('MERCH'));
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade('CORP'));
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade('INCONNU'));
    }

    public function test_grade_absent_ou_vide(): void
    {
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade(null));
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade(''));
    }

    public function test_la_casse_n_est_pas_toleree(): void
    {
        // Comparaison stricte volontaire : un grade en minuscules signalerait un
        // changement de contrat côté Airtel, qu'il vaut mieux voir bloquer.
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade('subs'));
        $this->assertNull(PaynalaPaymentService::typeCompteDepuisGrade('merchvip'));
    }
}
