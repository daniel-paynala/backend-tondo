<?php

namespace Tests\Unit;

use App\Services\DecaissementRefuse;
use PHPUnit\Framework\TestCase;

/**
 * Quand remet-on l'argent dans la collecte, et quand ne le remet-on pas ?
 *
 * Règle tranchée par Daniel le 2026-10-06 : **tant que la réponse est ambiguë,
 * on ne remet pas l'argent**. Le cas se règle avec l'opérateur et remonte dans
 * les réconciliations à traiter.
 *
 * Les deux erreurs ne coûtent pas pareil, et c'est toute la raison de ce test :
 *
 *   - restaurer à tort → l'argent est parti ET la collecte l'affiche
 *     disponible : la sortie suivante le dépense une seconde fois. Perte
 *     irrécupérable, et rien ne la signale ;
 *   - ne pas restaurer à tort → le montant attend dans une ligne visible,
 *     réglée à la main en quelques minutes.
 */
class ClassementEchecDecaissementTest extends TestCase
{
    /**
     * Reproduit la décision prise dans `PaynalaPaymentService::disburse()`.
     *
     * @param int  $statut  Code HTTP de la réponse.
     * @param bool $succes  Valeur du champ `success` du corps.
     */
    private function refusAffirme(int $statut, bool $succes): bool
    {
        $reussi      = $statut >= 200 && $statut < 300;
        $erreurClient = $statut >= 400 && $statut < 500;

        return ($reussi && $succes === false) || $erreurClient;
    }

    public function test_un_refus_metier_en_200_est_affirme(): void
    {
        // Le cas le plus important : cette API répond 200 avec success:false
        // sur ses erreurs métier. Trancher sur le seul code HTTP l'aurait
        // classé « succès », puis « inconnu » — et aurait immobilisé l'argent
        // d'un refus parfaitement clair.
        $this->assertTrue($this->refusAffirme(200, false));
    }

    public function test_une_requete_rejetee_est_affirmee(): void
    {
        // 4xx : la demande n'est jamais devenue une transaction (msisdn
        // invalide, jeton refusé, validation). Rien n'est parti.
        $this->assertTrue($this->refusAffirme(400, false));
        $this->assertTrue($this->refusAffirme(401, false));
        $this->assertTrue($this->refusAffirme(422, false));
    }

    public function test_une_panne_de_passerelle_reste_inconnue(): void
    {
        // LE point de la décision : un 502 ne prouve pas qu'Airtel n'a rien
        // fait. L'argent ne revient pas.
        $this->assertFalse($this->refusAffirme(500, false));
        $this->assertFalse($this->refusAffirme(502, false));
        $this->assertFalse($this->refusAffirme(503, false));
    }

    public function test_une_reponse_illisible_reste_inconnue(): void
    {
        // 200 sans `success` exploitable : on ne sait pas, donc on ne touche
        // à rien.
        $this->assertFalse($this->refusAffirme(200, true));
    }

    /**
     * L'exception du refus reste attrapable par les `catch` existants.
     *
     * Trois appelants attrapent déjà `RuntimeException`. Si l'exception du
     * refus n'en héritait pas, elle leur échapperait et remonterait brute au
     * milieu d'un décaissement.
     */
    public function test_le_refus_reste_une_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new DecaissementRefuse('non'));
    }
}
