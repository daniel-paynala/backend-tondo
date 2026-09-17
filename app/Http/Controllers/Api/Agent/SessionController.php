<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\ValideEnFrancais;
use App\Http\Controllers\Controller;
use App\Models\TondoAgent;
use App\Services\RetraitEspecesService;
use App\Support\RetraitAgents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Session d'un agent sur son terminal : connexion, changement de PIN, profil.
 *
 * Toutes ces routes exigent d'abord la clé du partenaire (middleware
 * `partenaire`). L'identifiant et le PIN ne suffisent jamais seuls.
 */
class SessionController extends Controller
{
    use ValideEnFrancais;

    public function __construct(private RetraitEspecesService $retraits) {}

    /**
     * POST /api/agent/connexion
     * En-tête : X-Cle-Partenaire
     * Body    : { identifiant: "ECKTPE001", pin: "4821" }
     */
    public function connexion(Request $request): JsonResponse
    {
        $partenaire = $request->attributes->get('partenaire');

        $data = $this->validerSaisie($request, [
            'identifiant' => ['required', 'string', 'max:20'],
            'pin'         => ['required', 'string', 'max:10'],
        ]);

        // Même réponse pour un identifiant inconnu et un PIN faux : rien ne doit
        // aider à dresser la liste des identifiants existants.
        $refus = 'Identifiant ou PIN incorrect.';

        $agent = TondoAgent::with(['partenaire', 'support'])
            ->where('project_id', $partenaire->project_id)
            ->where('partenaire_id', $partenaire->id)
            ->where('identifiant', RetraitAgents::normaliserIdentifiant($data['identifiant']))
            ->first();

        if (! $agent) {
            return response()->json(['message' => $refus, 'code' => 'identifiants_incorrects'], 401);
        }

        $issue = $this->verifierPin($agent, $data['pin']);
        if ($issue === 'verrouille') {
            return $this->reponseVerrouille();
        }
        if ($issue !== 'ok') {
            return response()->json([
                'message'              => $refus,
                'code'                 => 'identifiants_incorrects',
                'tentatives_restantes' => $issue,
            ], 401);
        }

        // Contrôlé APRÈS le PIN : un agent suspendu n'apprend pas son état à
        // quiconque essaie son identifiant sans connaître le code.
        if ($motif = $agent->motifBlocage()) {
            return response()->json(['message' => $motif, 'code' => 'agent_bloque'], 403);
        }

        // Une seule session par agent : se connecter sur un terminal ferme la
        // session ouverte ailleurs.
        $agent->tokens()->delete();
        $expiration = now()->addHours((int) config('retrait.session_heures'));
        $jeton      = $agent->createToken('terminal', ['agent'], $expiration);

        TondoAgent::whereKey($agent->id)->update(['derniere_connexion_at' => now()]);

        return response()->json([
            'jeton'            => $jeton->plainTextToken,
            'expire_at'        => $expiration->toIso8601String(),
            // Tant que ce drapeau est vrai, toutes les autres routes répondent
            // 403 `pin_a_changer` : le terminal doit d'abord appeler /pin.
            'doit_changer_pin' => $agent->pin_doit_changer,
            'agent'            => $this->presenter($agent),
        ]);
    }

    /**
     * POST /api/agent/pin
     * Body : { pin_actuel: "4821", nouveau_pin: "7302" }
     *
     * Seule route ouverte à un agent dont le PIN initial n'est pas encore changé.
     */
    public function changerPin(Request $request): JsonResponse
    {
        /** @var TondoAgent $agent */
        $agent = $request->user('agent');

        $data = $this->validerSaisie($request, [
            'pin_actuel'  => ['required', 'string', 'max:10'],
            'nouveau_pin' => ['required', 'string', 'max:10'],
        ]);

        // Le PIN actuel est vérifié avec le même compteur d'échecs que la
        // connexion : un jeton volé ne doit pas servir à deviner le PIN.
        $issue = $this->verifierPin($agent, $data['pin_actuel']);
        if ($issue === 'verrouille') {
            return $this->reponseVerrouille();
        }
        if ($issue !== 'ok') {
            return response()->json([
                'message'              => 'PIN actuel incorrect.',
                'code'                 => 'pin_incorrect',
                'tentatives_restantes' => $issue,
            ], 401);
        }

        if ($motif = RetraitAgents::motifRefusPin($data['nouveau_pin'])) {
            return response()->json(['message' => $motif, 'code' => 'pin_refuse'], 422);
        }
        if ($data['nouveau_pin'] === $data['pin_actuel']) {
            return response()->json(['message' => 'Le nouveau PIN doit être différent de l\'actuel.', 'code' => 'pin_refuse'], 422);
        }

        TondoAgent::whereKey($agent->id)->update([
            'pin_hash'         => Hash::make($data['nouveau_pin']),
            'pin_doit_changer' => false,
            'pin_modifie_at'   => now(),
        ]);

        return response()->json(['message' => 'PIN modifié.', 'agent' => $this->presenter($agent->fresh(['partenaire', 'support']))]);
    }

    /** GET /api/agent/moi — profil, plafonds et cumul du jour. */
    public function moi(Request $request): JsonResponse
    {
        return response()->json(['agent' => $this->presenter($request->user('agent'))]);
    }

    /** POST /api/agent/deconnexion — invalide le jeton en cours. */
    public function deconnexion(Request $request): JsonResponse
    {
        $request->user('agent')->currentAccessToken()->delete();

        return response()->json(['message' => 'Session fermée.']);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * Vérifie un PIN sous verrou et tient le compteur d'échecs.
     *
     * Sous verrou : sans lui, dix essais lancés en parallèle seraient tous
     * comparés avant que le compteur n'atteigne la limite.
     *
     * @return 'ok'|'verrouille'|int  Un entier = essais restants après un échec.
     */
    private function verifierPin(TondoAgent $agent, string $pin): string|int
    {
        $maximum = RetraitAgents::MAX_TENTATIVES_PIN;

        return DB::transaction(function () use ($agent, $pin, $maximum) {
            $a = TondoAgent::whereKey($agent->id)->lockForUpdate()->first();

            if ($a->pin_verrouille_at !== null) {
                return 'verrouille';
            }

            if (Hash::check($pin, $a->pin_hash)) {
                if ($a->pin_tentatives_echouees > 0) {
                    TondoAgent::whereKey($a->id)->update(['pin_tentatives_echouees' => 0]);
                }
                return 'ok';
            }

            $echecs = $a->pin_tentatives_echouees + 1;
            $maj    = ['pin_tentatives_echouees' => $echecs];
            if ($echecs >= $maximum) {
                // Seule une réinitialisation par un admin lèvera ce verrou.
                $maj['pin_verrouille_at'] = now();
                Log::warning('[agent] PIN verrouillé', ['agent' => $a->identifiant]);
            }
            TondoAgent::whereKey($a->id)->update($maj);

            return $echecs >= $maximum ? 'verrouille' : $maximum - $echecs;
        });
    }

    private function reponseVerrouille(): JsonResponse
    {
        return response()->json([
            'message' => 'PIN verrouillé après trop d\'échecs. Contactez Tonji pour le débloquer.',
            'code'    => 'pin_verrouille',
        ], 423);
    }

    /** @return array<string, mixed> */
    private function presenter(TondoAgent $agent): array
    {
        $retire = $this->retraits->montantRetireAujourdhui($agent->id);

        return [
            'identifiant'             => $agent->identifiant,
            'nom'                     => $agent->nom,
            'partenaire'              => ['nom' => $agent->partenaire?->nom, 'sigle' => $agent->partenaire?->sigle],
            'support'                 => ['libelle' => $agent->support?->libelle, 'sigle' => $agent->support?->sigle],
            'plafond_operation_fcfa'  => $agent->plafond_operation_fcfa,
            'plafond_journalier_fcfa' => $agent->plafond_journalier_fcfa,
            'retire_aujourdhui_fcfa'  => $retire,
            'reste_journalier_fcfa'   => max(0, $agent->plafond_journalier_fcfa - $retire),
        ];
    }
}
