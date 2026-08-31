<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoOrganisation;
use App\Contracts\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modération des **associations** (dashboard admin).
 *
 * Un dossier d'association est créé en statut `en_attente`. L'admin peut
 * l'`approuve` (l'association peut alors collecter), le `rejete` ou le
 * `suspendu` (motif obligatoire). Le représentant est notifié du verdict.
 *
 * NB : la LISTE des dossiers est lue en direct depuis Supabase par le dashboard
 * (comme les autres files de modération). Ici on n'expose que les **écritures**
 * (décisions) et le **streaming des pièces** (fichiers sur le disque privé
 * Laravel, non accessibles directement par le dashboard).
 */
class OrganisationsController extends Controller
{
    /** POST /api/admin/organisations/{id}/approuver */
    public function approuver(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'approuve');
    }

    /** POST /api/admin/organisations/{id}/rejeter — { motif } requis */
    public function rejeter(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'rejete');
    }

    /** POST /api/admin/organisations/{id}/suspendre — { motif } requis */
    public function suspendre(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'suspendu');
    }

    /**
     * Applique une décision de modération + notifie le représentant.
     * Le motif est obligatoire pour un rejet ou une suspension.
     */
    private function decision(Request $request, string $id, string $statut): JsonResponse
    {
        $org = TondoOrganisation::find($id);
        if (! $org) {
            return response()->json(['message' => 'Association introuvable.'], 404);
        }

        $motif = null;
        if (in_array($statut, ['rejete', 'suspendu'], true)) {
            $motif = $request->validate(['motif' => ['required', 'string', 'max:1000']])['motif'];
        }

        $org->statut = $statut;
        $org->motif_rejet = $motif;   // null si approbation
        $org->save();

        $this->notifierRepresentant($org, $statut, $motif);

        return response()->json([
            'message' => 'Statut du dossier mis à jour.',
            'statut'  => $statut,
        ]);
    }

    /**
     * Notifie le représentant du verdict (best-effort — n'interrompt jamais la
     * modération si la notification échoue).
     */
    private function notifierRepresentant(TondoOrganisation $org, string $statut, ?string $motif): void
    {
        [$titre, $corps] = match ($statut) {
            'approuve' => ['Association validée', "« {$org->nom} » est validée. Vous pouvez maintenant collecter sur Tonji."],
            'rejete'   => ['Dossier refusé', "Le dossier de « {$org->nom} » n'a pas été validé." . ($motif ? " Motif : {$motif}" : '')],
            'suspendu' => ['Association suspendue', "« {$org->nom} » a été suspendue." . ($motif ? " Motif : {$motif}" : '')],
            default    => ['Tonji', 'Mise à jour de votre dossier d\'association.'],
        };

        try {
            app(PushNotifier::class)->notifyOne((string) $org->user_id, $titre, $corps, [
                'type'            => 'moderation_association',
                'organisation_id' => $org->id,
                'statut'          => $statut,
            ]);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
