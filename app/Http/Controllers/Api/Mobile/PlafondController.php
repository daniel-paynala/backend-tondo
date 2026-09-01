<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoOrganisation;
use App\Models\TondoPlafondDemande;
use App\Services\SupabaseStorageService;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Plafond de collecte du compte + demande de DÉBLOCAGE (self-service).
 *
 * Une association peut demander à collecter au-delà de son plafond (10 M par
 * défaut) en déposant un justificatif (autorisation Conseil des ministres,
 * loi n°35/62). L'examen et la fixation du plafond accordé se font côté
 * dashboard ({@see \App\Http\Controllers\Api\Admin\PlafondDemandesController}).
 */
class PlafondController extends Controller
{
    /**
     * GET /api/mobile/plafond/statut
     *
     * Plafond effectif du compte + état de la dernière demande de déblocage.
     */
    public function statut(Request $request): JsonResponse
    {
        $user   = $request->user();
        $config = app(TondoConfigService::class)->getOperatorConfig($user->project_id);

        if ($user->type_compte === 'association') {
            $standard = (int) ($config['plafond_cagnotte_association'] ?? 10000000);
            $org      = TondoOrganisation::query()
                ->where('project_id', $user->project_id)
                ->where('user_id', $user->id)
                ->first();
            $plafond = (int) ($org?->plafond_fcfa ?? $standard);
        } else {
            $standard = (int) ($config['plafond_cagnotte_particulier'] ?? 2500000);
            $plafond  = (int) ($user->plafond_personnalise ?? $standard);
        }

        $demande = TondoPlafondDemande::query()
            ->where('project_id', $user->project_id)
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        return response()->json([
            'plafond'          => $plafond,
            'plafond_standard' => $standard,
            'debloque'         => $plafond > $standard,
            'demande'          => $demande ? [
                'statut'          => $demande->statut,
                'montant_demande' => $demande->montant_demande,
                'plafond_accorde' => $demande->plafond_accorde,
                'motif'           => $demande->motif,
            ] : null,
        ]);
    }

    /**
     * POST /api/mobile/plafond/demande  (multipart/form-data)
     * Body : { montant_demande?, justificatif }
     *
     * Réservé aux comptes association. Dépose le justificatif (bucket privé) et
     * (re)crée une demande en attente.
     */
    public function demande(Request $request): JsonResponse
    {
        $data = $request->validate([
            'montant_demande' => ['nullable', 'integer', 'min:100'],
            'justificatif'    => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'], // 8 Mo
        ]);

        $user = $request->user();
        if ($user->type_compte !== 'association') {
            return response()->json(['message' => 'Réservé aux comptes association.'], 403);
        }

        $fichier   = $request->file('justificatif');
        $extension = strtolower($fichier->getClientOriginalExtension() ?: $fichier->extension());
        $chemin    = "plafond/{$user->id}/justificatif.{$extension}";

        // Téléverse le justificatif sur le bucket privé Supabase.
        app(SupabaseStorageService::class)->upload(
            $chemin,
            (string) file_get_contents($fichier->getRealPath()),
            $fichier->getClientMimeType(),
        );

        // Une seule demande "vivante" par compte : on réutilise la ligne en
        // attente si elle existe, sinon on en crée une nouvelle.
        $demande = TondoPlafondDemande::query()
            ->where('project_id', $user->project_id)
            ->where('user_id', $user->id)
            ->where('statut', 'en_attente')
            ->first();

        if (! $demande) {
            $demande = new TondoPlafondDemande();
            $demande->id = (string) Str::uuid();
            $demande->project_id = $user->project_id;
            $demande->user_id = $user->id;
        }

        $demande->montant_demande     = $data['montant_demande'] ?? null;
        $demande->justificatif_chemin = $chemin;
        $demande->justificatif_nom    = $fichier->getClientOriginalName();
        $demande->justificatif_mime   = $fichier->getClientMimeType();
        $demande->justificatif_taille = $fichier->getSize();
        $demande->statut              = 'en_attente';
        $demande->motif               = null;
        $demande->plafond_accorde     = null;
        $demande->save();

        return response()->json([
            'message' => 'Demande envoyée. Elle sera examinée par l\'équipe Tonji.',
        ], 201);
    }
}
