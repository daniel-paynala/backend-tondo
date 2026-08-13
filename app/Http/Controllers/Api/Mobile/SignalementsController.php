<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoSignalement;
use App\Services\Mail\AdminNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Création d'un signalement par un utilisateur mobile (report d'une cagnotte).
 * À chaque signalement, les admins ayant activé la catégorie « signalements »
 * dans leurs préférences sont notifiés par e-mail (AdminNotifier).
 */
class SignalementsController extends Controller
{
    private const MOTIFS = ['fraude_suspectee', 'contenu_inapproprie', 'doublon', 'autre'];

    private const MOTIF_LABELS = [
        'fraude_suspectee'     => 'Fraude suspectée',
        'contenu_inapproprie'  => 'Contenu inapproprié',
        'doublon'              => 'Doublon',
        'autre'                => 'Autre',
    ];

    /**
     * POST /api/mobile/signalements
     * Body : { cagnotte_reference, motif, description }
     */
    public function store(Request $request, AdminNotifier $notifier): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'cagnotte_reference' => ['required', 'string'],
            'motif'              => ['required', Rule::in(self::MOTIFS)],
            'description'        => ['required', 'string', 'max:2000'],
        ]);

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $data['cagnotte_reference'])
            ->firstOrFail();

        $libelle = trim(($user->prenom ?? '').' '.($user->nom ?? ''));
        if ($libelle === '') {
            $libelle = $user->numero ?? 'Utilisateur';
        }

        $signalement = TondoSignalement::create([
            'project_id'          => $user->project_id,
            'cagnotte_id'         => $cagnotte->id,
            'signale_par_user_id' => $user->id,
            'signale_par_libelle' => $libelle,
            'motif'               => $data['motif'],
            'description'         => $data['description'],
            'statut'              => 'nouveau',
        ]);

        // Notifie les admins abonnés à la catégorie « signalements ».
        try {
            $motifLabel = self::MOTIF_LABELS[$data['motif']] ?? $data['motif'];
            $ref = e($cagnotte->reference);
            $titre = e($cagnotte->titre);
            $libelleSafe = e($libelle);
            $descSafe = e($data['description']);
            $corps = <<<HTML
            <p>Un utilisateur vient de signaler une cagnotte. Merci de l'examiner.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#F6F7F4;border:1px solid #E8EDE9;border-radius:12px;margin:8px 0;">
              <tr><td style="padding:16px;font-size:14px;line-height:1.7;">
                <strong>Cagnotte :</strong> {$titre} ({$ref})<br>
                <strong>Motif :</strong> {$motifLabel}<br>
                <strong>Signalé par :</strong> {$libelleSafe}<br>
                <strong>Description :</strong> {$descSafe}
              </td></tr>
            </table>
            HTML;

            $notifier->notifier(
                $user->project_id,
                'signalements',
                'Nouveau signalement — '.$motifLabel,
                'Nouveau signalement',
                $corps,
                'Voir les signalements',
                rtrim((string) config('services.admin_dashboard_url'), '/').'/signalements',
            );
        } catch (\Throwable $e) {
            Log::warning('[signalement] notification admin échouée', ['error' => $e->getMessage()]);
        }

        return response()->json($signalement, 201);
    }
}
