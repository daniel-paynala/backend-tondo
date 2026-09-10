<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Evenements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réception de la télémétrie produit envoyée par l'app.
 *
 * L'app bufferise et envoie par LOTS : un appel réseau par tap serait
 * intenable sur le réseau mobile gabonais et viderait la batterie.
 *
 * ⚠️ Aucun contenu saisi n'est accepté. Le vocabulaire des événements et les
 * clés de contexte sont des listes blanches ({@see Evenements}) : tout le reste
 * est écarté avant écriture. C'est cette liste, et non la bonne volonté du
 * client, qui garantit qu'aucun numéro, montant exact ou commentaire n'entre
 * dans la table.
 */
class EvenementsController extends Controller
{
    /** Au-delà, le lot est refusé : un client qui envoie plus est en dérive. */
    private const LOT_MAX = 50;

    /**
     * POST /api/mobile/evenements
     * Body : { evenements: [ { id, session_id, nom, occurred_at, contexte? }, … ] }
     *
     * Répond toujours 200 avec le nombre de lignes réellement écrites. La
     * télémétrie ne doit JAMAIS faire échouer un parcours utilisateur : un lot
     * partiellement invalide est écrit pour ce qu'il a de bon, le reste est
     * ignoré en silence.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'evenements'                => ['required', 'array', 'max:' . self::LOT_MAX],
            'evenements.*.id'           => ['required', 'uuid'],
            'evenements.*.session_id'   => ['required', 'string', 'max:64'],
            'evenements.*.nom'          => ['required', 'string', 'max:64'],
            'evenements.*.occurred_at'  => ['required', 'date'],
            'evenements.*.contexte'     => ['nullable', 'array'],
            'plateforme'                => ['nullable', 'string', 'max:16'],
            'version_app'               => ['nullable', 'string', 'max:32'],
            'canal'                     => ['nullable', 'in:app,web'],
        ]);

        $user      = $request->user();
        $maintenant = now();

        $lignes = [];
        foreach ($data['evenements'] as $e) {
            // Nom hors vocabulaire : ignoré. Une faute de frappe ne doit pas
            // créer une série fantôme qui trouerait les entonnoirs du dash.
            if (! Evenements::estConnu($e['nom'])) {
                continue;
            }

            $lignes[] = [
                'id'          => $e['id'],
                'project_id'  => $user->project_id,
                'user_id'     => $user->id,
                'session_id'  => $e['session_id'],
                'nom'         => $e['nom'],
                'canal'       => $data['canal'] ?? 'app',
                'plateforme'  => $data['plateforme']  ?? null,
                'version_app' => $data['version_app'] ?? null,
                'contexte'    => json_encode(
                    Evenements::filtrerContexte($e['nom'], $e['contexte'] ?? []),
                ),
                'occurred_at' => $e['occurred_at'],
                'created_at'  => $maintenant,
            ];
        }

        if ($lignes === []) {
            return response()->json(['ecrits' => 0]);
        }

        // insertOrIgnore : l'`id` vient du client et sert de clé d'idempotence.
        // Un lot renvoyé après un timeout réseau ne double pas les compteurs —
        // on a déjà payé ce prix sur les cotisations.
        $ecrits = DB::table(project_table('evenements'))->insertOrIgnore($lignes);

        return response()->json(['ecrits' => $ecrits]);
    }
}
