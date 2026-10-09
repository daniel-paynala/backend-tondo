<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le solde personnel.
 *
 * Ce n'est pas une table à part mais une **collecte de type `wallet`** : chez
 * Tonji, tout mouvement d'argent est ancré à une collecte (`payin.cagnotte_id`
 * et `payout.cagnotte_id` sont NOT NULL), et c'est précisément ce qui obligeait
 * jusqu'ici à créer une cagnotte fictive pour régler un commerce.
 *
 * Le wallet est donc traversé par le code existant — recharge, paiement,
 * transfert, historique — sans qu'aucune colonne contrainte ait eu à devenir
 * nullable. Ce qu'il faut écarter, ce sont les SERVICES de collecte :
 * reversement automatique, gratuité du reversement, partage, participants,
 * exploration publique, clôture.
 */
class WalletController extends Controller
{
    /**
     * GET /api/mobile/wallet
     *
     * Le solde de l'utilisateur connecté, créé à la volée s'il n'existe pas.
     * Un compte a toujours un solde, même à zéro : l'app n'a jamais à le
     * demander, et aucun écran n'a d'état « pas encore de wallet » à gérer.
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->trouverOuCreer($request->user());

        return response()->json([
            'wallet' => [
                'reference' => $wallet->reference,
                'solde'     => (int) $wallet->montant_collecte,
            ],
        ]);
    }

    /**
     * Le wallet de [$user], créé au premier accès.
     *
     * La course entre deux appels simultanés n'est pas arbitrée ici mais par
     * l'index unique partiel posé en base (SQL 041) : le second insert échoue,
     * on relit, et les deux appels rendent le même wallet. Compter sur un
     * `exists()` préalable ne protégerait de rien — deux requêtes peuvent le
     * passer toutes les deux.
     */
    private function trouverOuCreer(object $user): TondoCagnotte
    {
        $existant = TondoCagnotte::where('user_id', $user->id)
            ->where('type', 'wallet')
            ->first();

        if ($existant) {
            return $existant;
        }

        try {
            return TondoCagnotte::create([
                'reference'  => TondoCagnotte::nouvelleReference(),
                'project_id' => $user->project_id,
                'user_id'    => $user->id,
                'titre'      => 'Mon solde',
                'type'       => 'wallet',
                'statut'     => 'active',
                // Le numéro du titulaire : c'est vers lui que son solde
                // reviendra s'il le retire. Immuable comme sur une collecte
                // (RÈGLE 3), et ici il n'y a même rien à saisir.
                'numero_retrait' => $user->numero,
                'montant_collecte'  => 0,
                'cumul_cotisations' => 0,
            ]);
        } catch (QueryException $e) {
            // Index unique violé : un appel concurrent a gagné la course.
            $concurrent = TondoCagnotte::where('user_id', $user->id)
                ->where('type', 'wallet')
                ->first();

            if ($concurrent === null) {
                throw $e;   // autre chose : on ne masque pas l'erreur.
            }

            return $concurrent;
        }
    }
}
