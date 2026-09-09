<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoOrganisation;
use App\Services\PaynalaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
     *
     * Le choix est CONFRONTÉ au KYC Airtel : le grade du numéro dit déjà s'il
     * s'agit d'un compte particulier ou d'un compte association. L'app affiche
     * quand même les deux options — c'est voulu, à visée pédagogique — mais un
     * choix contraire est refusé ici, avec le motif.
     */
    public function definirTypeCompte(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_compte' => ['required', 'in:particulier,association'],
        ]);

        $user   = $request->user();
        $choisi = $data['type_compte'];

        // Numéro local 0XXXXXXXX : c'est sous cette forme que le KYC a été mis
        // en cache à l'inscription, quelques instants plus tôt.
        $msisdn = str_starts_with((string) $user->numero, '+241')
            ? '0' . substr((string) $user->numero, 4)
            : ltrim((string) $user->numero, '+');

        $kyc = app(PaynalaPaymentService::class)->checkKycData($msisdn);

        // Service indisponible : on refuse plutôt que d'enregistrer un type
        // qu'on ne peut pas vérifier. L'inscription elle-même est déjà soumise
        // à la même règle, le cache est donc normalement chaud.
        if ($kyc === null) {
            throw ValidationException::withMessages([
                'type_compte' => 'La vérification Airtel Money est temporairement indisponible. '
                    . 'Réessayez dans quelques minutes.',
            ]);
        }

        $attendu = $kyc['type_compte'] ?? null;

        if ($attendu === null) {
            throw ValidationException::withMessages([
                'type_compte' => 'Profil non reconnu : ce numéro n\'est ni un compte particulier '
                    . 'ni un compte association chez Airtel.',
            ]);
        }

        if ($attendu !== $choisi) {
            throw ValidationException::withMessages([
                'type_compte' => $attendu === 'association'
                    ? 'Ce numéro est enregistré comme compte association chez Airtel. '
                        . 'Choisissez « Association » pour continuer.'
                    : 'Ce numéro est enregistré comme compte particulier chez Airtel. '
                        . 'Pour créer un compte association, utilisez le numéro Airtel Money '
                        . 'de votre association.',
            ]);
        }

        $user->type_compte = $choisi;
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
            // Approuvée d'emblée : le KYC Airtel fait foi. Un compte associatif
            // existe chez l'opérateur, qui a déjà instruit l'identité — Paynala
            // ne redemande plus de pièces (décision du 2026-09-09). Le seul
            // dossier encore étudié est la demande de dépassement des 10 M.
            $org->statut = 'approuve';
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
     * Sérialise une organisation pour l'app.
     *
     * Plus de liste de pièces : le dossier documentaire a été supprimé
     * (2026-09-09), le KYC Airtel faisant foi sur l'identité de l'association.
     * La table `tonji_organisation_documents` est conservée mais n'est plus
     * ni écrite ni lue — elle resservira si des pièces sont redemandées pour
     * les demandes de dépassement des 10 M.
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
        ];
    }
}
