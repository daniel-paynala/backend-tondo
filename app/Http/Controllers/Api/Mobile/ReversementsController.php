<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\CarnetMarchands;
use App\Services\SortieArgent;
use App\Services\SortiesAutorisees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reversements partiels (payout gérant → bénéficiaire).
 *
 * Disponible uniquement pour les cagnottes ouvertes, uniquement pour le
 * créateur.
 *
 * Ce contrôleur ne porte plus que la validation du formulaire et la résolution
 * de la destination. Tous les contrôles financiers — verrou des sorties, solde
 * sous row-lock, enregistrement `initie` avant l'appel Paynala, compensation du
 * solde sur refus, alerte aux administrateurs, notification de l'enseigne —
 * vivent dans {@see \App\Services\SortieArgent}, partagé avec le bot WhatsApp.
 * Le web passe par cette même route : l'app et lui ne se distinguent que par la
 * valeur écrite dans `payout.canal`.
 */
class ReversementsController extends Controller
{
    public function __construct(
        private readonly SortieArgent $sortie,
        private readonly SortiesAutorisees $sorties,
        private readonly CarnetMarchands $carnet,
    ) {}

    /**
     * GET /api/mobile/cagnottes/{reference}/sorties
     *
     * Ce que cette collecte autorise, à cet instant.
     *
     * Route dédiée et volontairement minuscule : l'app la rappelle à l'arrivée
     * sur l'écran, au clic sur un bouton, et avant de valider. Faire relire la
     * collecte entière à chacun de ces moments coûterait trois fois plus pour
     * deux booléens.
     */
    public function sorties(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->first(['id', 'user_id']);

        if (! $cagnotte || $cagnotte->user_id !== $user->id) {
            // Même réponse qu'une collecte verrouillée : distinguer les deux
            // dirait à qui tâtonne des références lesquelles existent.
            return response()->json(['transfert' => false, 'marchand' => false]);
        }

        return response()->json(
            $this->sorties->pour($cagnotte->id, $user->project_id),
        );
    }

    /**
     * POST /api/mobile/reversements
     * Body : {
     *   cagnotte_reference   : string  (4-5 chiffres)
     *   numero_beneficiaire  : string|null  (9 chiffres local, ex : 074577473)
     *   membre_id        : string|null  (UUID tondo_participants.id)
     *   marchand_id          : string|null  (UUID tondo_marchands.id)
     *   montant              : int           (FCFA, min 100, max 500 000)
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cagnotte_reference'  => ['required', 'string', 'regex:/^\d{6}$/'],
            'numero_beneficiaire' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'participant_id'      => ['nullable', 'string', 'uuid'],
            // Destination marchande : le client envoie le solde chez un
            // commerçant enregistré plutôt que sur un numéro qu'il saisit.
            'marchand_id'         => ['nullable', 'string', 'uuid'],
            'montant'             => ['required', 'integer', 'min:100'],
        ]);

        if (empty($data['numero_beneficiaire']) && empty($data['participant_id']) && empty($data['marchand_id'])) {
            throw ValidationException::withMessages([
                'numero_beneficiaire' => 'Indiquez un numéro bénéficiaire, un membre ou un marchand.',
            ]);
        }

        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $data['cagnotte_reference'])
            ->first();

        if (! $cagnotte) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte introuvable.',
            ]);
        }

        if ($cagnotte->user_id !== $user->id) {
            return response()->json([
                'message' => 'Seul le créateur peut effectuer un transfert.',
            ], 403);
        }

        if ($cagnotte->type !== 'cagnotte_ouverte') {
            return response()->json([
                'message' => 'Le transfert est disponible uniquement pour les cagnottes ouvertes.',
            ], 422);
        }

        if (! in_array($cagnotte->statut, ['active', 'en_cours'])) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte clôturée — transfert impossible.',
            ]);
        }

        // ── Résolution de la destination ─────────────────────────────────────
        //
        // Seule étape qui reste ici : elle dépend du formulaire (membre choisi
        // dans les chips, code marchand, numéro saisi). Tout ce qui touche à
        // l'argent — verrou, réservation, décaissement, compensation, alerte,
        // notification de l'enseigne — vit dans SortieArgent, partagé avec le
        // bot WhatsApp.
        $beneficiaireUserId = null;
        // Fiche marchand retenue, le cas échéant : elle porte le numéro qui
        // encaisse et le type de compte à transmettre à Paynala.
        $marchand               = null;
        $numeroBeneficiaireE164 = null;

        if (! empty($data['marchand_id'])) {
            // Le carnet vérifie lui-même que la fiche est encore payable : une
            // enseigne désactivée entre l'affichage et la validation ne doit
            // pas encaisser.
            $marchand = $this->carnet->fiche($user->project_id, $data['marchand_id']);

            if (! $marchand) {
                throw ValidationException::withMessages([
                    'marchand_id' => 'Ce marchand n\'est plus disponible.',
                ]);
            }
        } elseif (! empty($data['participant_id'])) {
            $participant = DB::table(project_table('participants'))
                ->join('users', project_table('participants').'.user_id', '=', 'users.id')
                ->where(project_table('participants').'.id', $data['participant_id'])
                ->where(project_table('participants').'.cagnotte_id', $cagnotte->id)
                ->select('users.id as user_id_benef', 'users.numero as numero_user')
                ->first();

            if (! $participant || empty($participant->numero_user)) {
                throw ValidationException::withMessages([
                    'participant_id' => 'Membre introuvable dans cette cagnotte.',
                ]);
            }

            $numeroBeneficiaireE164 = $participant->numero_user;
            $beneficiaireUserId     = $participant->user_id_benef;
        } else {
            $numeroBeneficiaireE164 = '+241' . ltrim($data['numero_beneficiaire'], '0');
            // Cherche si ce numéro correspond à un compte Tonji.
            $beneficiaireUserId = DB::table('users')
                ->where('numero', $numeroBeneficiaireE164)
                ->value('id');
        }

        // ── Exécution ────────────────────────────────────────────────────────
        // `canal` distingue l'app du web : les deux parlent à cette route, et
        // la trace doit dire lequel des deux a lancé le transfert.
        $resultat = $this->sortie->executer(
            cagnotte:           $cagnotte,
            montant:            (int) $data['montant'],
            canal:              $this->canal($request),
            numeroE164:         $numeroBeneficiaireE164,
            beneficiaireUserId: $beneficiaireUserId,
            marchand:           $marchand,
        );

        if (! $resultat['ok']) {
            return $this->reponseEchec($resultat);
        }

        $cagnotte->refresh();

        return response()->json([
            'trans_id'            => $resultat['trans_id'],
            'statut'              => 'succes',
            'montant'             => $resultat['montant'],
            'numero_beneficiaire' => $resultat['numero'],
            'montant_collecte'    => (int) $cagnotte->montant_collecte,
        ], 201);
    }

    /**
     * Traduit un échec de {@see SortieArgent} en réponse HTTP.
     *
     * Les codes HTTP d'origine sont conservés à l'identique — l'app et le web
     * publiés les lisent déjà, et une sortie d'argent n'est pas l'endroit où
     * changer un contrat en passant.
     *
     * @param  array{code: string, message: ?string, montant: int} $resultat
     */
    private function reponseEchec(array $resultat): JsonResponse
    {
        return match ($resultat['code']) {
            // 423 Locked : l'app distingue un verrou d'une panne et affiche
            // « momentanément suspendu » plutôt qu'« une erreur est survenue ».
            'verrou' => response()->json([
                'message' => $resultat['message'],
                'code'    => $resultat['code_verrou'] ?? 'transfert_bloque',
            ], 423),

            'operations_bloquees' => response()->json([
                'message' => $resultat['message'],
            ], 503),

            // Le solde est une erreur de formulaire : elle s'affiche sous le
            // champ montant, pas dans un bandeau d'erreur technique.
            'solde_insuffisant' => throw ValidationException::withMessages([
                'montant' => $resultat['message'],
            ]),

            'reservation' => response()->json([
                'message' => $resultat['message'],
            ], 500),

            // Refus de l'opérateur ou issue inconnue : dans les deux cas
            // l'argent n'est pas chez le bénéficiaire et les administrateurs
            // ont été alertés. Le message dit lequel des deux c'est.
            default => response()->json([
                'message' => $resultat['message'],
            ], 502),
        };
    }

    /**
     * Canal d'où part le transfert, tracé dans `payout.request.canal`.
     *
     * L'app Flutter annonce son `User-Agent` ; le navigateur annonce le sien.
     * On ne cherche pas à être subtil : tout ce qui n'est pas reconnu comme
     * l'app compte comme le web, et une erreur d'étiquette ne coûte qu'une
     * ligne de statistique — jamais un transfert.
     */
    private function canal(Request $request): string
    {
        $agent = (string) $request->userAgent();

        return str_contains($agent, 'Dart') || str_contains($agent, 'Tonji')
            ? 'app'
            : 'web';
    }
}
