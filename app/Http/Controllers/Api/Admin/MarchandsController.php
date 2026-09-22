<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Models\TondoMarchand;
use App\Services\VerificationNumeroRetrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Marchands — destinations de transfert enregistrées par Tonji.
 *
 * Un marchand est un numéro Airtel Money vers lequel un client peut envoyer le
 * solde de sa cagnotte au lieu de le rapatrier sur son propre numéro. Comme
 * l'argent part réellement chez un tiers, l'enregistrement est un acte
 * d'administration : écriture réservée aux super admins et tracée dans le
 * journal, exactement comme les partenaires de retrait.
 *
 * Le numéro est vérifié auprès de l'opérateur avant d'être enregistré : on
 * garde le nom du titulaire, qui sert de contrôle visuel à la saisie puis de
 * preuve si un paiement est contesté.
 *
 * Une fiche qui porte des paiements ne se supprime pas : on la désactive, et
 * l'historique reste lisible.
 */
class MarchandsController extends Controller
{
    use GereRetraitAgents;

    /** GET /api/admin/marchands */
    public function index(Request $request): JsonResponse
    {
        $categories = project_table('categories_marchands');
        $marchandsTable = project_table('marchands');

        $marchands = TondoMarchand::where("{$marchandsTable}.project_id", $request->user()->project_id)
            // Le libellé accompagne chaque fiche : le dashboard l'affiche en
            // pastille, et une requête par ligne serait du gaspillage.
            ->leftJoin($categories, "{$categories}.id", '=', "{$marchandsTable}.categorie_id")
            ->select("{$marchandsTable}.*", "{$categories}.libelle as categorie_libelle")
            ->when($request->filled('actif'), fn ($q) => $q->where("{$marchandsTable}.actif", $request->boolean('actif')))
            ->when($request->filled('q'), function ($q) use ($request, $marchandsTable) {
                $terme = '%' . $request->string('q')->trim() . '%';
                $q->where(fn ($s) => $s->where("{$marchandsTable}.nom", 'ilike', $terme)
                    ->orWhere("{$marchandsTable}.numero_tel", 'ilike', $terme));
            })
            ->orderBy("{$marchandsTable}.nom")
            ->get();

        $stats = TondoMarchand::statistiques($marchands->pluck('id'));

        return response()->json([
            'marchands' => $marchands->map(fn (TondoMarchand $m) => $this->presenter($m, $stats)),
        ]);
    }

    /**
     * POST /api/admin/marchands
     *
     * Le KYC est appelé ici, pas seulement à la saisie : entre l'aperçu affiché
     * dans le formulaire et l'enregistrement, rien ne garantit que le numéro
     * soumis soit celui qui a été vérifié.
     */
    public function store(Request $request, VerificationNumeroRetrait $verification): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $request->merge(['numero_tel' => self::versE164($request->input('numero_tel'))]);
        $data = $request->validate($this->regles($projectId));

        $verdict = $verification->verifier($data['numero_tel'], $projectId);

        if ($verdict['operateur'] !== 'airtel') {
            return response()->json([
                'message' => 'Ce numéro n\'est pas un numéro Airtel Money : le transfert vers ce marchand échouerait.',
                'code'    => 'operateur_non_supporte',
            ], 422);
        }

        if ($verdict['kycOk'] === false) {
            return response()->json([
                'message' => 'Aucun compte Airtel Money actif sur ce numéro.',
                'code'    => 'compte_inactif',
            ], 422);
        }

        $marchand = new TondoMarchand();
        // id généré en PHP : Eloquent ne relit pas la valeur du DEFAULT.
        $marchand->id = (string) Str::uuid();
        $marchand->fill($data + [
            'project_id'           => $projectId,
            'titulaire'            => $verdict['titulaire'],
            'titulaire_verifie_at' => $verdict['titulaire'] ? now() : null,
        ]);
        $marchand->save();

        $this->journaliser($request, 'marchand_cree', "Marchand {$marchand->nom}", 'info', [
            'marchand_id' => $marchand->id,
            'numero'      => $marchand->numero_tel,
            'titulaire'   => $marchand->titulaire,
        ]);

        return response()->json(['marchand' => $this->presenter($marchand)], 201);
    }

    /** PATCH /api/admin/marchands/{id} */
    public function update(Request $request, string $id, VerificationNumeroRetrait $verification): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $marchand = TondoMarchand::where('project_id', $projectId)->find($id);
        if (! $marchand) {
            return response()->json(['message' => 'Marchand introuvable.'], 404);
        }

        if ($request->has('numero_tel')) {
            $request->merge(['numero_tel' => self::versE164($request->input('numero_tel'))]);
        }
        $data = $request->validate($this->regles($projectId, $marchand->id, partiel: true));

        // Changer le numéro change la destination de l'argent : le titulaire
        // affiché doit suivre, sinon la fiche afficherait le nom de l'ancien
        // compte tout en payant le nouveau.
        if (isset($data['numero_tel']) && $data['numero_tel'] !== $marchand->numero_tel) {
            $verdict = $verification->verifier($data['numero_tel'], $projectId);

            if ($verdict['operateur'] !== 'airtel' || $verdict['kycOk'] === false) {
                return response()->json([
                    'message' => 'Ce numéro n\'est pas un compte Airtel Money actif.',
                    'code'    => 'numero_refuse',
                ], 422);
            }

            $data['titulaire']            = $verdict['titulaire'];
            $data['titulaire_verifie_at'] = $verdict['titulaire'] ? now() : null;
        }

        $avant = $marchand->only(array_keys($data));
        $marchand->fill($data);
        $marchand->save();

        $this->journaliser(
            $request,
            'marchand_modifie',
            "Marchand {$marchand->nom}",
            // Désactiver retire la destination du parcours client : c'est visible.
            array_key_exists('actif', $data) && $data['actif'] === false ? 'warning' : 'info',
            ['marchand_id' => $marchand->id, 'avant' => $avant, 'apres' => $data],
        );

        return response()->json(['marchand' => $this->presenter($marchand)]);
    }

    /**
     * DELETE /api/admin/marchands/{id}
     *
     * Refusée dès le premier paiement : la clé étrangère est en RESTRICT, et
     * un message clair vaut mieux qu'une erreur de base de données.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $marchand = TondoMarchand::where('project_id', $request->user()->project_id)->find($id);
        if (! $marchand) {
            return response()->json(['message' => 'Marchand introuvable.'], 404);
        }

        $nb = DB::table(project_table('payout'))->where('marchand_id', $marchand->id)->count();
        if ($nb > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$nb} paiement(s) sont rattachés à ce marchand. Désactivez-le plutôt.",
            ], 409);
        }

        $marchand->delete();

        $this->journaliser($request, 'marchand_supprime', "Marchand {$marchand->nom}", 'warning', [
            'marchand_id' => $marchand->id,
            'numero'      => $marchand->numero_tel,
        ]);

        return response()->json(['supprime' => true]);
    }

    /**
     * POST /api/admin/marchands/verifier-numero
     *
     * Aperçu pour le formulaire : affiche le titulaire avant d'enregistrer,
     * pour qu'un chiffre inversé se voie tout de suite.
     */
    public function verifierNumero(Request $request, VerificationNumeroRetrait $verification): JsonResponse
    {
        $data = $request->validate([
            'numero_tel' => ['required', 'string', 'max:20'],
        ]);

        $verdict = $verification->verifier($data['numero_tel'], $request->user()->project_id);

        return response()->json([
            'numero_tel' => self::versE164($data['numero_tel']),
            'operateur'  => $verdict['operateur'],
            'kyc_ok'     => $verdict['kycOk'],
            'titulaire'  => $verdict['titulaire'],
        ]);
    }

    /**
     * GET /api/admin/marchands/{id}/paiements
     *
     * Relevé d'un marchand : ce que Tonji lui a envoyé, du plus récent au plus
     * ancien, avec la cagnotte d'origine.
     */
    public function paiements(Request $request, string $id): JsonResponse
    {
        $projectId = $request->user()->project_id;

        $marchand = TondoMarchand::where('project_id', $projectId)->find($id);
        if (! $marchand) {
            return response()->json(['message' => 'Marchand introuvable.'], 404);
        }

        $payout    = project_table('payout');
        $cagnottes = project_table('cagnottes');

        $paiements = DB::table($payout)
            ->leftJoin($cagnottes, "{$cagnottes}.id", '=', "{$payout}.cagnotte_id")
            ->where("{$payout}.marchand_id", $marchand->id)
            ->orderByDesc("{$payout}.date_creation")
            ->limit(200)
            ->get([
                "{$payout}.trans_id",
                "{$payout}.montant",
                "{$payout}.statut",
                "{$payout}.operateur_id",
                "{$payout}.date_creation",
                "{$cagnottes}.reference as cagnotte_reference",
                "{$cagnottes}.titre as cagnotte_titre",
            ]);

        return response()->json([
            'marchand'  => $this->presenter($marchand),
            'paiements' => $paiements,
        ]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * Forme unique en base : +241 suivi du numéro sans son zéro initial.
     * Le dashboard laisse saisir « 07 60 77 52 » comme « +24107607752 ».
     */
    private static function versE164(mixed $numero): string
    {
        $chiffres = preg_replace('/\D/', '', (string) $numero) ?? '';

        return str_starts_with($chiffres, '241')
            ? '+' . $chiffres
            : '+241' . ltrim($chiffres, '0');
    }

    /** @return array<string, mixed> */
    private function regles(string $projectId, ?string $ignorerId = null, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';
        $table  = project_table('marchands');

        return [
            'nom'           => [$requis, 'string', 'min:2', 'max:120'],
            // Miroir du CHECK en base : le refus doit venir de la validation,
            // avec un message lisible, pas d'une contrainte Postgres.
            'numero_tel'    => [$requis, 'string', 'regex:/^\+241[0-9]{8,9}$/',
                Rule::unique($table, 'numero_tel')->where('project_id', $projectId)->ignore($ignorerId)],
            'type_paynala'  => ['sometimes', Rule::in(['particulier', 'entreprise'])],
            // Référence à la liste administrée : un texte libre finissait en
            // « Santé » / « santé » / « Pharmacie » pour la même réalité.
            'categorie_id'  => ['sometimes', 'nullable', 'uuid',
                Rule::exists(project_table('categories_marchands'), 'id')->where('project_id', $projectId)],
            'ville'         => ['sometimes', 'nullable', 'string', 'max:60'],
            'contact_nom'   => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_tel'   => ['sometimes', 'nullable', 'string', 'max:16'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:2000'],
            'actif'         => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Forme envoyée au dashboard. `supprimable` évite que l'interface propose
     * une suppression que l'API refusera.
     *
     * @param  array<string, array{nb: int, total: int}> $stats
     * @return array<string, mixed>
     */
    private function presenter(TondoMarchand $m, array $stats = []): array
    {
        $stat = $stats[$m->id] ?? null;
        $nb   = $stat['nb'] ?? DB::table(project_table('payout'))->where('marchand_id', $m->id)->count();

        // La liste apporte déjà le libellé par jointure ; après une écriture,
        // il faut aller le chercher pour que la réponse soit complète.
        $categorie = $m->categorie_libelle
            ?? ($m->categorie_id
                ? DB::table(project_table('categories_marchands'))->where('id', $m->categorie_id)->value('libelle')
                : null);

        return [
            'id'                   => $m->id,
            'nom'                  => $m->nom,
            'numero_tel'           => $m->numero_tel,
            'titulaire'            => $m->titulaire,
            'titulaire_verifie_at' => optional($m->titulaire_verifie_at)->toIso8601String(),
            'type_paynala'         => $m->type_paynala,
            'categorie_id'         => $m->categorie_id,
            'categorie'            => $categorie,
            'ville'                => $m->ville,
            'contact_nom'          => $m->contact_nom,
            'contact_tel'          => $m->contact_tel,
            'contact_email'        => $m->contact_email,
            'notes'                => $m->notes,
            'actif'                => (bool) $m->actif,
            'nb_paiements'         => (int) $nb,
            'total_paye_fcfa'      => (int) ($stat['total'] ?? 0),
            'supprimable'          => $nb === 0,
            'created_at'           => optional($m->created_at)->toIso8601String(),
        ];
    }
}
