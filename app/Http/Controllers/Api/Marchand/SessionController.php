<?php

namespace App\Http\Controllers\Api\Marchand;

use App\Http\Controllers\Controller;
use App\Services\MarchandOtpService;
use App\Support\JetonMarchand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entrée du portail marchand : numéro, code reçu par e-mail, et c'est tout.
 *
 * **Les deux routes répondent la même chose quel que soit le numéro.** Dire
 * « ce numéro n'est pas marchand » ferait de cette entrée un annuaire des
 * commerces affiliés à Tonji, interrogeable par quiconque. C'est la même règle
 * que celle posée pour la vérification KYC : renvoyer un nom depuis un numéro
 * en fait un annuaire inversé.
 */
class SessionController extends Controller
{
    public function __construct(private readonly MarchandOtpService $otp) {}

    /** POST /api/marchand/otp — demande d'un code. */
    public function demanderCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numero' => ['required', 'string', 'max:20'],
        ]);

        $numero = self::versE164($data['numero']);
        $envois = $this->otp->envoyer($numero);

        // Journalisé, pas répercuté : l'exploitation doit pouvoir constater
        // qu'un numéro inconnu a été saisi, le client ne doit pas l'apprendre.
        if ($envois === 0) {
            Log::info('[portail_marchand] demande sans destinataire', [
                'numero' => substr($numero, 0, 8) . '***',
            ]);
        }

        return response()->json([
            'message' => 'Si ce numéro est enregistré comme marchand, un code vient de partir '
                . 'vers l\'adresse e-mail de la fiche.',
        ]);
    }

    /** POST /api/marchand/session — échange du code contre un jeton. */
    public function ouvrir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numero' => ['required', 'string', 'max:20'],
            'code'   => ['required', 'string', 'size:6'],
        ]);

        $numero = self::versE164($data['numero']);

        if (! $this->otp->verifier($numero, $data['code'])) {
            return response()->json([
                'message' => 'Code incorrect ou expiré.',
                'code'    => 'code_refuse',
            ], 422);
        }

        // Le code est bon : reste à savoir de quel projet relève ce numéro.
        // Un code valide sans fiche active serait un état impossible, mais on
        // ne tient pas une session sur une supposition.
        $fiches = DB::table(project_table('marchands'))
            ->where('numero_tel', $numero)
            ->where('actif', true)
            ->get(['id', 'project_id', 'nom', 'code_marchand', 'ville']);

        if ($fiches->isEmpty()) {
            return response()->json([
                'message' => 'Aucun commerce actif sur ce numéro.',
                'code'    => 'aucune_fiche',
            ], 422);
        }

        return response()->json([
            'jeton'        => JetonMarchand::creer($numero, (string) $fiches->first()->project_id),
            'expire_dans'  => JetonMarchand::DUREE,
            'numero'       => $numero,
            // Plusieurs fiches peuvent partager le numéro : le portail les
            // affiche toutes, c'est le numéro qui a encaissé.
            'etablissements' => $fiches->map(fn ($f) => [
                'id'    => $f->id,
                'nom'   => $f->nom,
                'code'  => $f->code_marchand,
                'ville' => $f->ville,
            ])->values(),
        ]);
    }

    /** Forme stockée en base : +241 suivi du numéro sans son zéro initial. */
    private static function versE164(string $numero): string
    {
        $chiffres = preg_replace('/\D/', '', $numero) ?? '';

        return str_starts_with($chiffres, '241')
            ? '+' . $chiffres
            : '+241' . ltrim($chiffres, '0');
    }
}
