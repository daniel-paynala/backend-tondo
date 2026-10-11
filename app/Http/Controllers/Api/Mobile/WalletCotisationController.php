<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\CotisationInterne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cotiser à une collecte depuis son solde.
 *
 * L'argent ne sort pas de Tonji : voir {@see CotisationInterne} pour ce que
 * cela implique — ni payin, ni payout, et aucun frais puisque aucun opérateur
 * n'est appelé.
 */
class WalletCotisationController extends Controller
{
    public function __construct(private readonly CotisationInterne $service) {}

    /**
     * POST /api/mobile/wallet/cotiser
     * Body : { reference: "742416", montant: 5000, commentaire?: "…" }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference'   => ['required', 'string', 'regex:/^\d{6}$/'],
            'montant'     => ['required', 'integer', 'min:' . CotisationInterne::MINIMUM],
            'commentaire' => ['nullable', 'string', 'max:140'],
        ]);

        $user = $request->user();

        $source = TondoCagnotte::where('user_id', $user->id)
            ->where('type', 'wallet')
            ->first();

        if ($source === null) {
            return response()->json(['message' => 'Votre solde n\'est pas encore ouvert.'], 422);
        }

        $destination = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $data['reference'])
            ->first();

        if ($destination === null) {
            return response()->json(['message' => 'Aucune collecte ne porte ce numéro.'], 404);
        }

        // Un solde se recharge, il ne se « cotise » pas depuis un autre solde :
        // ce chemin-là est le transfert entre personnes, et il n'est pas encore
        // ouvert. Mieux vaut le refuser clairement que de le laisser passer par
        // une porte dérobée.
        if ($destination->type === 'wallet') {
            return response()->json([
                'message' => 'Le transfert vers un autre solde Tonji arrive bientôt.',
            ], 422);
        }

        if ($destination->statut === 'cloturee') {
            return response()->json(['message' => 'Cette collecte est clôturée.'], 422);
        }

        try {
            $resultat = $this->service->executer(
                $source,
                $destination,
                (int) $data['montant'],
                $data['commentaire'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'trans_id'     => $resultat['trans_id'],
            'solde'        => $resultat['solde_source'],
            'cagnotte'     => [
                'reference' => $destination->reference,
                'titre'     => $destination->titre,
            ],
        ]);
    }
}
