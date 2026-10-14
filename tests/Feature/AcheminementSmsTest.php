<?php

namespace Tests\Feature;

use App\Services\WirepickSmsService;
use App\Support\Sante;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * La sonde voit-elle une panne d'acheminement SMS ?
 *
 * Du 7 au 14 octobre 2026, pas un seul OTP n'a été délivré. Wirepick acceptait
 * tous les messages, rendait un identifiant, et les facturait ZÉRO FRANC.
 * Personne ne l'a su pendant une semaine : le repli par e-mail couvrait les
 * comptes qui ont une adresse, et les autres ne pouvaient simplement pas
 * s'inscrire.
 *
 * Le prix est le seul signal fiable. Le statut ne sert à rien : un message
 * effectivement REÇU reste affiché « DLG — Delivered to Upstream gateway »,
 * exactement comme un message perdu. Vérifié sur un envoi réel le 14/10.
 */
class AcheminementSmsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(WirepickSmsService::CLE_NON_FACTURES);
    }

    private function controleSms(): array
    {
        foreach (app(Sante::class)->rapport()['controles'] as $c) {
            if (($c['cle'] ?? null) === 'sms') {
                return $c;
            }
        }

        $this->fail('Le contrôle « sms » a disparu du rapport de santé.');
    }

    public function test_sans_envoi_non_facture_tout_va_bien(): void
    {
        $this->assertSame(Sante::OK, $this->controleSms()['etat']);
    }

    public function test_un_envoi_non_facture_alerte(): void
    {
        Cache::put(WirepickSmsService::CLE_NON_FACTURES, 1, now()->addHour());

        $this->assertSame(Sante::ALERTE, $this->controleSms()['etat']);
    }

    public function test_une_serie_est_critique(): void
    {
        // Sans SMS, un nouvel utilisateur sans adresse e-mail n'entre pas du
        // tout : ce n'est pas un point à regarder, c'est une porte fermée.
        Cache::put(WirepickSmsService::CLE_NON_FACTURES, 3, now()->addHour());

        $this->assertSame(Sante::CRITIQUE, $this->controleSms()['etat']);
    }

    public function test_le_detail_dit_quoi_faire(): void
    {
        Cache::put(WirepickSmsService::CLE_NON_FACTURES, 5, now()->addHour());
        $c = $this->controleSms();

        $this->assertStringContainsString('5', $c['detail']);
        $this->assertStringContainsString('arrivés', $c['detail']);
        $this->assertSame(5, $c['mesures']['non_factures']);
    }

    public function test_la_panne_rend_tout_le_rapport_critique(): void
    {
        // Un tableau vert avec une ligne rouge doit s'afficher rouge, sinon
        // l'alerte ne part pas.
        Cache::put(WirepickSmsService::CLE_NON_FACTURES, 4, now()->addHour());

        $this->assertSame(Sante::CRITIQUE, app(Sante::class)->rapport()['etat']);
    }
}
