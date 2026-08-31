<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoOrganisation;
use App\Models\TondoOrganisationDocument;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Socle « Associations » côté mobile.
 *
 * Couvre :
 *  – le **choix du type de compte** (aiguillage post-inscription) ;
 *  – le **dossier de l'association** (nom, description, statut) ;
 *  – le **dépôt / la relecture des pièces** requises (disque privé) ;
 *  – la **soumission** du dossier à la modération.
 *
 * Tout est scoped au compte courant (`$request->user()`) et à son `project_id`
 * (cloisonnement multi-tenant). Les pièces sont **sensibles** (CNI, statuts) :
 * elles vivent sur le disque privé Laravel et ne sont servies que par un
 * endpoint authentifié restreint à l'organisation du compte.
 */
class AssociationController extends Controller
{
    /**
     * Les 5 types de pièces requises (loi n°35/62).
     * DOIT rester aligné avec le CHECK SQL de `tonji_organisation_documents`.
     */
    private const TYPES_PIECES = [
        'recepisse',
        'statuts',
        'pv_designation',
        'piece_identite',
        'autorisation_collecte',
    ];

    /**
     * POST /api/mobile/compte/type-compte
     * Body : { type_compte: 'particulier' | 'association' }
     *
     * Persiste le choix de l'aiguillage. Renvoie le user sérialisé (avec
     * `type_compte` + `organisation_statut`) pour rafraîchir le gate côté app.
     */
    public function definirTypeCompte(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_compte' => ['required', 'in:particulier,association'],
        ]);

        $user = $request->user();
        $user->type_compte = $data['type_compte'];
        $user->save();

        return response()->json(['user' => $user->toApiArray()]);
    }

    /**
     * GET /api/mobile/association
     *
     * Dossier de l'association du compte courant (ou null), avec la liste des
     * pièces déposées et leur statut.
     */
    public function show(Request $request): JsonResponse
    {
        $org = $this->organisationDuUser($request->user());

        return response()->json([
            'organisation' => $org ? $this->serializeOrganisation($org) : null,
        ]);
    }

    /**
     * POST /api/mobile/association
     * Body : { nom: string, description?: string }
     *
     * Crée (ou met à jour) le dossier. Force `type_compte = 'association'`.
     * Le statut reste 'en_attente'.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'         => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // Le compte devient une association si ce n'est pas déjà le cas.
        if ($user->type_compte !== 'association') {
            $user->type_compte = 'association';
            $user->save();
        }

        $org = $this->organisationDuUser($user);
        $creation = $org === null;

        if ($creation) {
            // id généré en PHP pour récupérer la valeur immédiatement
            // (le DEFAULT gen_random_uuid() ne renvoie rien à Eloquent).
            $org = new TondoOrganisation();
            $org->id = (string) Str::uuid();
            $org->project_id = $user->project_id;
            $org->user_id = $user->id;
            $org->statut = 'en_attente';
        }

        // Création comme mise à jour : on ne touche qu'au nom + description.
        $org->nom = $data['nom'];
        $org->description = $data['description'] ?? null;
        $org->save();

        return response()->json(
            ['organisation' => $this->serializeOrganisation($org)],
            $creation ? 201 : 200
        );
    }

    /**
     * POST /api/mobile/association/documents  (multipart/form-data)
     * Body : { type_piece, fichier }
     *
     * Dépose (ou remplace) une pièce. Le fichier va sur le disque privé.
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_piece' => ['required', 'in:' . implode(',', self::TYPES_PIECES)],
            'fichier'    => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'], // 8 Mo
        ]);

        $user = $request->user();
        $org = $this->organisationDuUser($user);
        if (! $org) {
            return response()->json([
                'message' => 'Renseignez d\'abord le nom de votre association.',
            ], 422);
        }

        $fichier   = $request->file('fichier');
        $extension = strtolower($fichier->getClientOriginalExtension() ?: $fichier->extension());

        // Chemin déterministe dans le bucket privé (1 fichier par type et par orga).
        $cheminNouveau = "associations/{$org->id}/{$data['type_piece']}.{$extension}";

        // Pièce existante de ce type ? On remplace l'objet + la ligne.
        $doc = TondoOrganisationDocument::query()
            ->where('organisation_id', $org->id)
            ->where('type_piece', $data['type_piece'])
            ->first();

        $storage = app(SupabaseStorageService::class);

        // Téléverse (upsert) sur Supabase Storage — bucket privé, aucun fichier
        // sur le disque du serveur applicatif.
        $storage->upload(
            $cheminNouveau,
            (string) file_get_contents($fichier->getRealPath()),
            $fichier->getClientMimeType()
        );

        // Supprime l'ancien objet si le chemin diffère (extension changée).
        if ($doc && $doc->chemin && $doc->chemin !== $cheminNouveau) {
            $storage->delete($doc->chemin);
        }

        if (! $doc) {
            $doc = new TondoOrganisationDocument();
            $doc->id = (string) Str::uuid();
            $doc->project_id = $org->project_id;
            $doc->organisation_id = $org->id;
            $doc->type_piece = $data['type_piece'];
        }

        $doc->chemin        = $cheminNouveau;
        $doc->nom_fichier   = $fichier->getClientOriginalName();
        $doc->mime          = $fichier->getClientMimeType();
        $doc->taille_octets = $fichier->getSize();
        $doc->statut        = 'depose'; // (re)dépôt → repasse en attente de validation
        $doc->motif_rejet   = null;
        $doc->save();

        return response()->json(['document' => $this->serializeDocument($doc)], 201);
    }

    /**
     * GET /api/mobile/association/documents/{typePiece}
     *
     * Stream le fichier d'une pièce — uniquement pour l'organisation du compte
     * courant (pièces sensibles).
     */
    public function showDocument(Request $request, string $typePiece): RedirectResponse|JsonResponse
    {
        $org = $this->organisationDuUser($request->user());
        if (! $org) {
            return response()->json(['message' => 'Aucune association.'], 404);
        }

        $doc = TondoOrganisationDocument::query()
            ->where('organisation_id', $org->id)
            ->where('type_piece', $typePiece)
            ->first();

        if (! $doc) {
            return response()->json(['message' => 'Pièce introuvable.'], 404);
        }

        // Redirige vers une URL signée temporaire du bucket privé Supabase.
        $url = app(SupabaseStorageService::class)->signedUrl($doc->chemin);
        return redirect()->away($url);
    }

    /**
     * POST /api/mobile/association/soumettre
     *
     * Vérifie que le dossier est complet (nom + 5 pièces) et le (re)soumet à la
     * modération (statut 'en_attente'). 422 + liste des pièces manquantes sinon.
     */
    public function soumettre(Request $request): JsonResponse
    {
        $org = $this->organisationDuUser($request->user());
        if (! $org) {
            return response()->json([
                'message' => 'Renseignez d\'abord le nom de votre association.',
            ], 422);
        }

        // Pièces déjà déposées → ce qui manque parmi les 5 requises.
        $deposees = TondoOrganisationDocument::query()
            ->where('organisation_id', $org->id)
            ->pluck('type_piece')
            ->all();
        $manquantes = array_values(array_diff(self::TYPES_PIECES, $deposees));

        if (! empty($manquantes)) {
            return response()->json([
                'message'    => 'Dossier incomplet : il manque des pièces.',
                'manquantes' => $manquantes,
            ], 422);
        }

        // Complet → (re)mise en file de modération.
        $org->statut = 'en_attente';
        $org->motif_rejet = null;
        $org->save();

        return response()->json([
            'message'      => 'Dossier soumis. Il sera vérifié par l\'équipe Tonji.',
            'organisation' => $this->serializeOrganisation($org),
        ]);
    }

    // ── Helpers privés ──────────────────────────────────────────────────────

    /**
     * L'organisation du compte courant (ou null), scoped projet + user.
     */
    private function organisationDuUser($user): ?TondoOrganisation
    {
        return TondoOrganisation::query()
            ->where('project_id', $user->project_id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Sérialise une organisation + ses pièces pour l'app.
     */
    private function serializeOrganisation(TondoOrganisation $org): array
    {
        return [
            'id'             => $org->id,
            'nom'            => $org->nom,
            'description'    => $org->description,
            'statut'         => $org->statut,
            'motif_rejet'    => $org->motif_rejet,
            'plafond_fcfa'   => $org->plafond_fcfa,
            'numero_retrait' => $org->numero_retrait,
            'documents'      => $org->documents()
                ->get()
                ->map(fn ($d) => $this->serializeDocument($d))
                ->values(),
        ];
    }

    /**
     * Sérialise une pièce (sans exposer le chemin de stockage privé).
     */
    private function serializeDocument(TondoOrganisationDocument $doc): array
    {
        return [
            'type_piece'    => $doc->type_piece,
            'nom_fichier'   => $doc->nom_fichier,
            'mime'          => $doc->mime,
            'taille_octets' => $doc->taille_octets,
            'statut'        => $doc->statut,
            'motif_rejet'   => $doc->motif_rejet,
        ];
    }
}
