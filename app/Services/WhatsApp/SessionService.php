<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;

/**
 * Gestion de la session conversationnelle WhatsApp par numéro de téléphone.
 *
 * Chaque session est stockée dans le cache Laravel sous une clé unique
 * dérivée du numéro E.164 (sans le "+"), avec un TTL de 30 minutes
 * réinitialisé à chaque mise à jour (inactivité glissante).
 *
 * Structure JSON stockée en cache :
 *   {
 *     "etape":  "menu" | "cotiser.ref" | "cotiser.montant" | "cotiser.numero"
 *               | "cotiser.attente" | "cotiser.nom_prenom"
 *               | "rejoindre.ref" | "rejoindre.numero" | "rejoindre.nom_prenom"
 *               | "creer.*" | "gerer.*",
 *     "data":   { ... }   // contexte propre à l'étape en cours
 *   }
 *
 * Usage typique :
 *   $session->set($numero, 'cotiser.ref');              // passe à l'étape
 *   $etape = $session->etape($numero);                  // lit l'étape courante
 *   $data  = $session->data($numero);                   // lit le contexte
 *   $session->updateData($numero, ['montant' => 500]);  // enrichit le contexte
 *   $session->reset($numero);                           // détruit la session
 */
class SessionService
{
    /** Durée d'inactivité en minutes avant expiration automatique de la session. */
    private const TTL_MINUTES = 30;

    /**
     * Construit la clé de cache pour un numéro donné.
     *
     * Le "+" initial est supprimé pour éviter les problèmes de caractères
     * spéciaux dans certains backends de cache (Redis, Memcached).
     *
     * @param  string $numero  Numéro E.164 (ex : +24177123456)
     * @return string          Clé de cache (ex : wa_session:24177123456)
     */
    private function cle(string $numero): string
    {
        return 'wa_session:' . ltrim($numero, '+');
    }

    /**
     * Retourne la session complète (étape + données) pour un numéro.
     * Retourne un tableau vide si aucune session n'existe.
     *
     * @param  string $numero  Numéro E.164 du contact WhatsApp
     * @return array{etape?: string, data?: array<string, mixed>}
     */
    public function get(string $numero): array
    {
        return Cache::get($this->cle($numero), []);
    }

    /**
     * Retourne l'étape courante de la session, ou null si aucune session.
     *
     * @param  string $numero  Numéro E.164
     * @return string|null     Ex : 'cotiser.ref', 'menu', null
     */
    public function etape(string $numero): ?string
    {
        return $this->get($numero)['etape'] ?? null;
    }

    /**
     * Retourne le tableau de données contextuelles de la session.
     * Vide si aucune session ou si la clé 'data' est absente.
     *
     * @param  string $numero  Numéro E.164
     * @return array<string, mixed>
     */
    public function data(string $numero): array
    {
        return $this->get($numero)['data'] ?? [];
    }

    /**
     * Écrase la session avec une nouvelle étape et des données optionnelles.
     * Le TTL est réinitialisé à 30 minutes.
     *
     * @param  string               $numero  Numéro E.164
     * @param  string               $etape   Nouvelle étape (ex : 'cotiser.montant')
     * @param  array<string, mixed> $data    Données contextuelles à stocker
     */
    public function set(string $numero, string $etape, array $data = []): void
    {
        Cache::put(
            $this->cle($numero),
            ['etape' => $etape, 'data' => $data],
            now()->addMinutes(self::TTL_MINUTES),
        );
    }

    /**
     * Fusionne des données supplémentaires dans le contexte de la session
     * sans changer l'étape courante. Utile pour enrichir progressivement
     * le contexte au fil des échanges (ex : ajout du montant après la référence).
     *
     * @param  string               $numero  Numéro E.164
     * @param  array<string, mixed> $merge   Données à fusionner (array_merge)
     */
    public function updateData(string $numero, array $merge): void
    {
        $session = $this->get($numero);
        // Fusion des nouvelles clés avec les données existantes (les nouvelles écrasent)
        $session['data'] = array_merge($session['data'] ?? [], $merge);
        // Remettre en cache avec le même TTL glissant
        Cache::put($this->cle($numero), $session, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Supprime la session du cache (équivalent à "raccrocher").
     * Appelé après une erreur, un retour menu, ou une fin de parcours.
     *
     * @param  string $numero  Numéro E.164
     */
    public function reset(string $numero): void
    {
        Cache::forget($this->cle($numero));
    }
}
