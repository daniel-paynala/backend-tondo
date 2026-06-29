<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\OneSignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modération des cagnottes publiques (dashboard admin).
 *
 * Une cagnotte publique est créée en statut `en_attente` et reste invisible
 * de la page Explorer tant qu'un admin ne l'a pas `approuvee`. Il peut aussi
 * la `rejetee` (avec motif) ou, après coup, la `suspendue` (signalement).
 */
class CagnottesController extends Controller
{
    /**
     * GET /api/admin/cagnottes/moderation?statut=en_attente
     * File de modération (par défaut : les cagnottes en attente).
     */
    public function moderation(Request $request): JsonResponse
    {
        $statut = $request->string('statut')->toString() ?: 'en_attente';

        $items = TondoCagnotte::query()
            ->where('visibilite', 'public')
            ->where('statut_validation', $statut)
            ->orderBy('date_creation', 'desc')
            ->limit(200)
            ->get();

        $createurs = TondoUser::whereIn('id', $items->pluck('user_id'))->get()->keyBy('id');

        return response()->json([
            'data' => $items->map(function ($c) use ($createurs) {
                $u = $createurs[$c->user_id] ?? null;
                return [
                    'reference'         => $c->reference,
                    'titre'             => $c->titre,
                    'description'       => $c->description,
                    'montant_collecte'  => (int) $c->montant_collecte,
                    'montant_cible'     => $c->montant_cible,
                    'statut_validation' => $c->statut_validation,
                    'motif_rejet'       => $c->motif_rejet,
                    'date_creation'     => $c->date_creation?->toIso8601String(),
                    'createur'          => $u ? trim($u->prenom . ' ' . $u->nom) : null,
                    'createur_numero'   => $u?->numero,
                ];
            })->all(),
            'total' => $items->count(),
        ]);
    }

    /** POST /api/admin/cagnottes/{reference}/approuver */
    public function approuver(Request $request, string $reference): JsonResponse
    {
        return $this->decision($request, $reference, 'approuvee');
    }

    /** POST /api/admin/cagnottes/{reference}/rejeter — { motif } requis */
    public function rejeter(Request $request, string $reference): JsonResponse
    {
        return $this->decision($request, $reference, 'rejetee');
    }

    /** POST /api/admin/cagnottes/{reference}/suspendre — { motif } requis */
    public function suspendre(Request $request, string $reference): JsonResponse
    {
        return $this->decision($request, $reference, 'suspendue');
    }

    /**
     * Applique une décision de modération + notifie le créateur.
     * Le motif est obligatoire pour un rejet ou une suspension.
     */
    private function decision(Request $request, string $reference, string $statut): JsonResponse
    {
        $c = TondoCagnotte::where('reference', $reference)
            ->where('visibilite', 'public')
            ->first();

        if (! $c) {
            return response()->json(['message' => 'Cagnotte publique introuvable.'], 404);
        }

        $motif = null;
        if (in_array($statut, ['rejetee', 'suspendue'], true)) {
            $motif = $request->validate(['motif' => ['required', 'string', 'max:1000']])['motif'];
        }

        $c->statut_validation = $statut;
        $c->motif_rejet       = $motif;            // null si approbation
        $c->validee_at        = now();
        $c->validee_par       = $request->user()?->id;
        $c->save();

        $this->notifierCreateur($c, $statut, $motif);

        return response()->json([
            'message'           => 'Statut de modération mis à jour.',
            'statut_validation' => $statut,
        ]);
    }

    /**
     * Notifie le créateur du verdict (best-effort, n'interrompt pas la modération).
     */
    private function notifierCreateur(TondoCagnotte $c, string $statut, ?string $motif): void
    {
        [$titre, $corps] = match ($statut) {
            'approuvee' => ['Cagnotte approuvée 🎉', "Votre cagnotte « {$c->titre} » est maintenant publique sur Tonji."],
            'rejetee'   => ['Cagnotte refusée', "« {$c->titre} » n'a pas été approuvée." . ($motif ? " Motif : {$motif}" : '')],
            'suspendue' => ['Cagnotte suspendue', "« {$c->titre} » a été suspendue." . ($motif ? " Motif : {$motif}" : '')],
            default     => ['Tonji', 'Mise à jour de votre cagnotte.'],
        };

        try {
            app(OneSignalService::class)->notifyOne((string) $c->user_id, $titre, $corps, [
                'type'      => 'moderation_cagnotte',
                'reference' => $c->reference,
                'statut'    => $statut,
            ]);
        } catch (\Throwable) {
            // Notification best-effort : on ignore l'échec.
        }
    }
}
