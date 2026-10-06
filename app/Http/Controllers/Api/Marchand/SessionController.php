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

        // ── Numéro inconnu : on le dit ───────────────────────────────────────
        //
        // La réponse était volontairement neutre — la même que le numéro soit
        // marchand ou non — pour que cette page ne devienne pas un annuaire :
        // on aurait pu essayer des numéros jusqu'à savoir qui encaisse chez
        // Tonji. Daniel a tranché le 2026-10-06 : laisser passer à l'écran du
        // code un numéro qui n'ira nulle part est plus coûteux que ce risque.
        // Le commerçant restait planté devant un champ à six chiffres sans
        // comprendre qu'aucun code n'arriverait jamais.
        //
        // Ce qui protège encore de l'énumération : la limite de débit sur cette
        // route, et le fait qu'un numéro marchand est un numéro commercial,
        // affiché en vitrine. Ce n'est pas un secret.
        if ($envois === 0) {
            // Trois raisons possibles de n'avoir rien envoyé, et elles ne se
            // disent pas pareil. Confondre « pas marchand » avec « fiche sans
            // adresse » enverrait un commerçant enregistré chercher une erreur
            // de numéro qu'il n'a pas commise.
            if (! $this->otp->estMarchand($numero)) {
                Log::info('[portail_marchand] numéro inconnu', [
                    'numero' => substr($numero, 0, 8) . '***',
                ]);

                return response()->json([
                    'message' => 'Ce numéro n\'est pas enregistré comme marchand chez Tonji. '
                        . 'Vérifiez le numéro, ou contactez-nous pour inscrire votre commerce.',
                    'code'    => 'numero_inconnu',
                ], 404);
            }

            // La fiche existe : c'est son adresse de contact qui manque, ou
            // l'envoi qui a échoué. Le commerçant ne peut rien y faire seul, et
            // l'exploitation doit le voir — d'où un WARNING, pas un INFO.
            Log::warning('[portail_marchand] marchand connu mais code non envoyé', [
                'numero' => substr($numero, 0, 8) . '***',
            ]);

            return response()->json([
                'message' => 'Votre fiche existe, mais aucun code n\'a pu être envoyé : '
                    . 'son adresse e-mail est manquante ou invalide. Contactez Tonji.',
                'code'    => 'envoi_impossible',
            ], 503);
        }

        return response()->json([
            'message' => 'Un code vient de partir vers l\'adresse e-mail de votre fiche.',
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
