<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de la configuration tarifaire par opérateur / pays.
 *
 * GET    /api/admin/config                      → liste des opérateurs configurés
 * POST   /api/admin/config                      → créer un opérateur
 * PATCH  /api/admin/config/{operateur}/{pays}   → modifier un opérateur
 * DELETE /api/admin/config/{operateur}/{pays}   → supprimer un opérateur
 */
class ConfigController extends Controller
{
    public function __construct(private TondoConfigService $svc) {}

    /**
     * Opérateurs avec un badge `integration_paiement` :
     *  - `'effective'`   : intégration live avec l'API de paiement (airtel aujourd'hui)
     *  - `'en_attente'`  : pas encore intégré — paiements en mode mock
     */
    /**
     * Opérateurs pour lesquels le paiement est réellement intégré (live).
     * Les autres sont en mode mock — les cotisations sont simulées immédiatement.
     */
    private const OPERATEURS_INTEGRES = ['airtel'];

    /**
     * GET /api/admin/config
     *
     * Liste tous les opérateurs configurés pour le projet courant.
     * Enrichit chaque entrée avec un badge `integration_paiement` :
     *  - `'effective'`  : intégration live (API Paynala active)
     *  - `'en_attente'` : opérateur connu mais paiements mockés
     *
     * @return JsonResponse { operateurs: array }
     */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->svc->listOperatorConfigs($request->user()->project_id);

        // Ajout du statut d'intégration pour que le dashboard affiche un badge visuel.
        $enriched = $rows->map(function (array $op): array {
            $op['integration_paiement'] = in_array($op['operateur'], self::OPERATEURS_INTEGRES)
                ? 'effective'
                : 'en_attente';
            return $op;
        });

        return response()->json(['operateurs' => $enriched->values()]);
    }

    /**
     * POST /api/admin/config
     *
     * Crée la configuration d'un nouvel opérateur/pays.
     * Body : voir validated() — opérateur + pays obligatoires en création.
     *
     * @return JsonResponse Liste complète mise à jour (201)
     */
    public function store(Request $request): JsonResponse
    {
        // withKeys = true : exige 'operateur' et 'pays' dans le body.
        $data = $this->validated($request, withKeys: true);
        $this->svc->updateOperatorConfig(
            $request->user()->project_id,
            $data['operateur'],
            $data['pays'],
            $data,
        );

        // Retourne la liste complète après création (cohérence UI).
        return $this->index($request)->setStatusCode(201);
    }

    /**
     * PATCH /api/admin/config/{operateur}/{pays}
     *
     * Met à jour la configuration tarifaire d'un opérateur existant.
     * Body : voir validated() — opérateur et pays dans l'URL, pas dans le body.
     *
     * @return JsonResponse Liste complète mise à jour
     */
    public function update(Request $request, string $operateur, string $pays): JsonResponse
    {
        $data = $this->validated($request);
        $this->svc->updateOperatorConfig(
            $request->user()->project_id,
            $operateur,
            $pays,
            $data,
        );

        return $this->index($request);
    }

    /**
     * POST /api/admin/config/{operateur}/{pays}/toggle
     *
     * Active ou désactive un opérateur (bascule `actif`).
     * Utile pour désactiver temporairement un opérateur sans le supprimer.
     *
     * @return JsonResponse Liste complète mise à jour
     */
    public function toggle(Request $request, string $operateur, string $pays): JsonResponse
    {
        $this->svc->toggleOperatorConfig(
            $request->user()->project_id,
            $operateur,
            $pays,
        );

        return $this->index($request);
    }

    /**
     * DELETE /api/admin/config/{operateur}/{pays}
     *
     * Supprime la configuration d'un opérateur/pays.
     * Attention : suppression définitive — les cagnottes existantes
     * ne sont pas impactées, mais les nouveaux paiements sur cet opérateur
     * seront rejetés par le détecteur d'opérateur.
     *
     * @return JsonResponse Liste complète mise à jour
     */
    public function destroy(Request $request, string $operateur, string $pays): JsonResponse
    {
        $this->svc->deleteOperatorConfig(
            $request->user()->project_id,
            $operateur,
            $pays,
        );

        return $this->index($request);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Valide les paramètres tarifaires communs à store() et update().
     *
     * Validations clés :
     *  - commission_paynala : taux décimal entre 0 et 25 % (ex : 0.02 = 2 %)
     *  - plafond_par_envoi  : montant max par virement (FCFA)
     *  - tranches           : grille tarifaire Airtel (type + valeur + bornes)
     *  - prefixes           : préfixes numériques locaux (ex : ['07', '077'])
     *
     * @param bool $withKeys Si true, exige 'operateur' et 'pays' dans le body
     */
    private function validated(Request $request, bool $withKeys = false): array
    {
        $rules = [
            'commission_paynala'     => ['required', 'numeric', 'min:0',       'max:0.25'],
            'plafond_par_envoi'      => ['required', 'integer', 'min:50000',   'max:5000000'],
            'plafond_journalier'     => ['required', 'integer', 'min:100000',  'max:50000000'],
            'tranches'               => ['required', 'array'],
            // Chaque tranche peut être un pourcentage ou un forfait fixe.
            'tranches.*.type'        => ['required', Rule::in(['pourcentage', 'forfait'])],
            'tranches.*.valeur'      => ['required', 'numeric', 'min:0'],
            'tranches.*.montant_min' => ['nullable', 'integer', 'min:0'],
            'tranches.*.montant_max' => ['nullable', 'integer', 'min:1'],
            // Indicatif téléphonique du pays (ex : '+241' pour le Gabon).
            'indicatif'              => ['nullable', 'string', 'max:10', 'regex:/^\+?\d{1,4}$/'],
            // Préfixes locaux permettant de détecter l'opérateur depuis un numéro.
            'prefixes'               => ['nullable', 'array'],
            'prefixes.*'             => ['string', 'regex:/^\d{2,6}$/'],
            'logo'                   => ['nullable', 'string'],
        ];

        if ($withKeys) {
            // Identifiant opérateur en snake_case minuscule (ex : 'airtel', 'moov_gabon').
            $rules['operateur'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'];
            // Code pays ISO 3166-1 alpha-2 (ex : 'GA', 'CM').
            $rules['pays']      = ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'];
        }

        return $request->validate($rules);
    }
}
