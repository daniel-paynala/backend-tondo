<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;

/**
 * Gestion de la session conversationnelle WhatsApp par numéro de téléphone.
 * Stockée en cache (TTL 30 min d'inactivité).
 *
 * Structure :
 *   {
 *     "etape":  "menu" | "cotiser.ref" | "cotiser.montant" | ...
 *     "data":   { ... }   // contexte propre à l'étape
 *   }
 */
class SessionService
{
    private const TTL_MINUTES = 30;

    private function cle(string $numero): string
    {
        return 'wa_session:' . ltrim($numero, '+');
    }

    public function get(string $numero): array
    {
        return Cache::get($this->cle($numero), []);
    }

    public function etape(string $numero): ?string
    {
        return $this->get($numero)['etape'] ?? null;
    }

    public function data(string $numero): array
    {
        return $this->get($numero)['data'] ?? [];
    }

    public function set(string $numero, string $etape, array $data = []): void
    {
        Cache::put(
            $this->cle($numero),
            ['etape' => $etape, 'data' => $data],
            now()->addMinutes(self::TTL_MINUTES),
        );
    }

    public function updateData(string $numero, array $merge): void
    {
        $session = $this->get($numero);
        $session['data'] = array_merge($session['data'] ?? [], $merge);
        Cache::put($this->cle($numero), $session, now()->addMinutes(self::TTL_MINUTES));
    }

    public function reset(string $numero): void
    {
        Cache::forget($this->cle($numero));
    }
}
