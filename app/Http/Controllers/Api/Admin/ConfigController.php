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

    public function index(Request $request): JsonResponse
    {
        $rows = $this->svc->listOperatorConfigs($request->user()->project_id);

        return response()->json(['operateurs' => $rows->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, withKeys: true);
        $this->svc->updateOperatorConfig(
            $request->user()->project_id,
            $data['operateur'],
            $data['pays'],
            $data,
        );

        return $this->index($request)->setStatusCode(201);
    }

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

    private function validated(Request $request, bool $withKeys = false): array
    {
        $rules = [
            'commission_paynala'     => ['required', 'numeric', 'min:0',       'max:0.25'],
            'plafond_par_envoi'      => ['required', 'integer', 'min:50000',   'max:5000000'],
            'plafond_journalier'     => ['required', 'integer', 'min:100000',  'max:50000000'],
            'tranches'               => ['required', 'array'],
            'tranches.*.type'        => ['required', Rule::in(['pourcentage', 'forfait'])],
            'tranches.*.valeur'      => ['required', 'numeric', 'min:0'],
            'tranches.*.montant_min' => ['nullable', 'integer', 'min:0'],
            'tranches.*.montant_max' => ['nullable', 'integer', 'min:1'],
            'indicatif'              => ['nullable', 'string', 'max:10', 'regex:/^\+?\d{1,4}$/'],
            'prefixes'               => ['nullable', 'array'],
            'prefixes.*'             => ['string', 'regex:/^\d{2,6}$/'],
        ];

        if ($withKeys) {
            $rules['operateur'] = ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'];
            $rules['pays']      = ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'];
        }

        return $request->validate($rules);
    }
}
