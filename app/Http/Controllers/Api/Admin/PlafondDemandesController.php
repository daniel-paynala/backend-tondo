<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\PushNotifier;
use App\Http\Controllers\Controller;
use App\Models\TondoOrganisation;
use App\Models\TondoPlafondDemande;
use App\Models\TondoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modération des demandes de DÉBLOCAGE de plafond (dashboard admin).
 *
 * L'admin (super_admin) examine le justificatif déposé par l'association et
 * fixe le **plafond accordé** (met à jour `tonji_organisations.plafond_fcfa`,
 * ou `users.plafond_personnalise` pour un particulier), ou refuse avec motif.
 * Le représentant est notifié du verdict.
 *
 * La LISTE des demandes est lue en direct depuis Supabase par le dashboard
 * (comme les autres files de modération) ; ici on n'expose que les décisions.
 */
class PlafondDemandesController extends Controller
{
    /** POST /api/admin/plafond-demandes/{id}/approuver — { plafond_accorde } requis */
    public function approuver(Request $request, string $id): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'plafond_accorde' => ['required', 'integer', 'min:1000', 'max:100000000000'],
        ]);

        $demande = TondoPlafondDemande::find($id);
        if (! $demande) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        $montant = (int) $data['plafond_accorde'];
        $user    = TondoUser::find($demande->user_id);

        // Applique le plafond accordé au compte : association → plafond de l'orga ;
        // particulier → override personnalisé sur le compte.
        if ($user && $user->type_compte === 'association') {
            TondoOrganisation::query()
                ->where('project_id', $demande->project_id)
                ->where('user_id', $user->id)
                ->update(['plafond_fcfa' => $montant, 'updated_at' => now()]);
        } elseif ($user) {
            $user->plafond_personnalise = $montant;
            $user->save();
        }

        $demande->statut = 'approuve';
        $demande->plafond_accorde = $montant;
        $demande->motif = null;
        $demande->save();

        $this->notifier($demande, 'approuve', $montant, null);

        return response()->json([
            'message'         => 'Déblocage accordé.',
            'plafond_accorde' => $montant,
        ]);
    }

    /** POST /api/admin/plafond-demandes/{id}/rejeter — { motif } requis */
    public function rejeter(Request $request, string $id): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'motif' => ['required', 'string', 'max:1000'],
        ]);

        $demande = TondoPlafondDemande::find($id);
        if (! $demande) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        $demande->statut = 'rejete';
        $demande->motif = $data['motif'];
        $demande->save();

        $this->notifier($demande, 'rejete', null, $data['motif']);

        return response()->json(['message' => 'Demande refusée.']);
    }

    /**
     * Notifie le demandeur du verdict (best-effort).
     */
    private function notifier(TondoPlafondDemande $d, string $statut, ?int $montant, ?string $motif): void
    {
        [$titre, $corps] = $statut === 'approuve'
            ? [
                'Plafond débloqué',
                'Votre plafond de collecte est désormais de '
                    . number_format((int) $montant, 0, ',', ' ') . ' FCFA.',
            ]
            : [
                'Déblocage refusé',
                'Votre demande de déblocage de plafond n\'a pas été validée.'
                    . ($motif ? ' Motif : ' . $motif : ''),
            ];

        try {
            app(PushNotifier::class)->notifyOne((string) $d->user_id, $titre, $corps, [
                'type'   => 'plafond_demande',
                'statut' => $statut,
            ]);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
