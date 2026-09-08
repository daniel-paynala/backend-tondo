<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Config dynamique exposée au mobile.
 *
 * Permet au client Flutter de récupérer les paramètres tarifaires pilotés
 * serveur par opérateur / pays — un changement de taux ne nécessite aucune
 * mise à jour de l'app sur les stores.
 *
 * La commission Paynala est exclue de la réponse (backend-only).
 */
class ConfigController extends Controller
{
    public function __construct(
        private TondoConfigService $svc,
        private \App\Services\CguService $cgu,
    ) {}

    /**
     * GET /api/mobile/config/frais?operateur=airtel&pays=GA
     *
     * Retourne la grille tarifaire de transfert pour l'opérateur/pays demandé.
     * Commission Paynala exclue — appliquée côté serveur uniquement.
     */
    public function frais(Request $request): JsonResponse
    {
        $operateur = $request->query('operateur', 'airtel');
        $pays      = $request->query('pays', 'GA');
        $projectId = $request->user()->project_id;

        $cfg = $this->svc->getOperatorConfig($projectId, $operateur, $pays);

        unset($cfg['commission_paynala']);

        return response()->json($cfg);
    }

    /**
     * GET /api/mobile/config/cgu?operateur=airtel&pays=GA
     *
     * Conditions d'utilisation rendues À PARTIR DE LA CONFIG OPÉRATEUR.
     *
     * Les chiffres (commission, plafonds, répercussion des frais de retrait)
     * etaient jusqu'ici ecrits en dur dans cinq ecrans et avaient diverge de la
     * config reellement appliquee. Ils sont desormais produits ici : une seule
     * source de verite, et le texte suit l'operateur.
     *
     * La commission apparait dans ce texte contractuel alors que `frais()` la
     * retire volontairement — la REGLE 4-bis interdit d'exposer le detail du
     * calcul dans l'UI de paiement, pas de dire le modele economique dans les
     * conditions que l'utilisateur accepte.
     */
    public function cgu(Request $request): JsonResponse
    {
        return response()->json($this->cgu->pour(
            // Route PUBLIQUE : les conditions se lisent avant d'avoir un compte,
            // à l'inscription. On prend le projet de l'utilisateur s'il est
            // connecté, sinon le projet Tondo par défaut.
            projectId: $request->user()?->project_id ?? \App\Models\Project::tondoId(),
            operateur: $request->query('operateur', 'airtel'),
            pays:      $request->query('pays', 'GA'),
        ));
    }

    /**
     * POST /api/mobile/config/cgu/accepter — { version, operateur?, pays? }
     *
     * Enregistre l'acceptation des CGU par l'utilisateur courant.
     *
     * La version envoyée est comparée à celle que le serveur produit MAINTENANT :
     * accepter une version périmée est refusé. Sans ce contrôle, un client resté
     * ouvert pendant un changement de plafond enregistrerait une acceptation qui
     * ne correspond à aucun texte en vigueur.
     */
    public function accepterCgu(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version'   => ['required', 'string', 'max:32'],
            'operateur' => ['nullable', 'string', 'max:32'],
            'pays'      => ['nullable', 'string', 'max:8'],
        ]);

        $user    = $request->user();
        $courant = $this->cgu->pour(
            projectId: $user->project_id,
            operateur: $data['operateur'] ?? 'airtel',
            pays:      $data['pays'] ?? 'GA',
        );

        if ($data['version'] !== $courant['version']) {
            return response()->json([
                'message'         => 'Ces conditions ont changé. Relisez-les avant d\'accepter.',
                'version_courante' => $courant['version'],
            ], 409);
        }

        $user->cgu_version     = $courant['version'];
        $user->cgu_acceptee_at = now();
        $user->save();

        return response()->json([
            'message'         => 'Conditions acceptées.',
            'cgu_version'     => $user->cgu_version,
            'cgu_acceptee_at' => $user->cgu_acceptee_at,
        ]);
    }
}
