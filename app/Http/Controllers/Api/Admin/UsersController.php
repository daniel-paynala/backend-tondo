<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\ReversementService;
use App\Support\SuppressionCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supervision des utilisateurs mobiles (TondoUser) par les administrateurs.
 *
 * Inclut à la fois les comptes `full` (inscrits normalement) et les comptes
 * `light` (créés automatiquement lors de l'ajout à une tontine).
 * Lecture seule — les modifications de profil se font côté mobile.
 */
class UsersController extends Controller
{
    /**
     * GET /api/admin/users
     *
     * Retourne la liste paginée des utilisateurs du projet avec deux compteurs
     * calculés par sous-requête SQL :
     *  - `cagnottes_count` : nombre de cagnottes créées (rôle gérant)
     *  - `total_cotise`    : somme de toutes les cotisations versées (FCFA)
     *
     * Filtres optionnels :
     *  - `q`              : recherche sur nom, prénom, numéro
     *  - `type_client`    : particulier | entreprise | marchand
     *  - `kyc_valide_only`: boolean — retourne uniquement les KYC validés
     *  - `per_page`       : max 100, défaut 25
     *
     * @return JsonResponse Liste paginée de TondoUser avec compteurs
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Noms de tables préfixés selon l'env (tondo_ / tonji_).
        $tCagnottes = project_table('cagnottes');
        $tPaiements = project_table('paiements');

        $query = TondoUser::query()
            ->where('project_id', $projectId)
            // Sous-requêtes corrélées pour éviter les JOINs coûteux sur la liste.
            ->selectRaw("
                users.*,
                (select count(*) from {$tCagnottes} where {$tCagnottes}.user_id = users.id) as cagnottes_count,
                (select coalesce(sum(montant), 0) from {$tPaiements} where {$tPaiements}.user_id = users.id) as total_cotise
            ")
            ->when($request->input('q'), function ($q, $search) {
                // Recherche insensible à la casse sur les identifiants humains.
                $q->where(function ($sub) use ($search) {
                    $sub->where('nom', 'ilike', "%{$search}%")
                        ->orWhere('prenom', 'ilike', "%{$search}%")
                        ->orWhere('numero', 'ilike', "%{$search}%");
                });
            })
            ->when($request->input('type_client'), fn ($q, $t) => $q->where('type_client', $t))
            // Filtre KYC : utile pour cibler les utilisateurs vérifiés uniquement.
            ->when($request->boolean('kyc_valide_only'), fn ($q) => $q->where('kyc_valide', true))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/admin/users/{id}
     *
     * Retourne le détail complet d'un utilisateur identifié par son UUID.
     * Renvoie 404 si l'utilisateur n'appartient pas au projet courant.
     *
     * @return JsonResponse TondoUser complet
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $projectId = $request->user()->project_id;
        // Scope project_id — isolation multi-tenant obligatoire.
        $user = TondoUser::where('project_id', $projectId)->findOrFail($id);
        return response()->json($user);
    }

    /**
     * PATCH /api/admin/users/{id}/plafond — { plafond: int|null }
     *
     * Fixe (ou réinitialise avec null) le plafond de collecte PERSONNALISÉ d'un
     * compte (override du plafond global du type de compte). Réservé super_admin.
     */
    public function setPlafond(Request $request, string $id): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'plafond' => ['nullable', 'integer', 'min:1000', 'max:100000000000'],
        ]);

        $projectId = $request->user()->project_id;
        $user = TondoUser::where('project_id', $projectId)->find($id);
        if (! $user) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        $user->plafond_personnalise = $data['plafond'] ?? null;
        $user->save();

        return response()->json([
            'message' => 'Plafond personnalisé mis à jour.',
            'plafond' => $user->plafond_personnalise,
        ]);
    }

    /**
     * DELETE /api/admin/users/{id}
     *
     * Supprime le compte d'un utilisateur mobile. **Réservé super_admin.**
     *
     * Deux régimes, selon l'empreinte financière du compte :
     *
     *  – **Purge** — aucune cagnotte créée, aucun paiement, aucun payin/payout,
     *    aucune participation ayant donné lieu à un versement : la ligne `users`
     *    est réellement SUPPRIMÉE, avec ses participations et ses device tokens.
     *    Rien à conserver, et la base ne se remplit pas de comptes fantômes.
     *
     *  – **Anonymisation** — dès qu'il existe une trace comptable. Les paiements
     *    et payouts référencent `user_id` et l'historique doit être conservé pour
     *    la comptabilité et la lutte contre la fraude : la ligne reste, ses champs
     *    identifiants sont neutralisés. C'est ce qu'annonce la page d'aide publique
     *    (« l'historique des transactions est conservé, dissocié de votre identité »).
     *
     * Rapatriement préalable des fonds : si l'utilisateur gère des cagnottes qui
     * détiennent encore de l'argent, le solde est d'abord reversé sur le numéro
     * de retrait de chaque cagnotte. **Si un seul reversement échoue, RIEN n'est
     * anonymisé** et la requête répond 409 : on ne supprime jamais un compte dont
     * l'argent n'est pas sorti.
     */
    public function destroy(Request $request, string $id, ReversementService $reversements): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $admin     = $request->user();
        $projectId = $admin->project_id;

        $user = TondoUser::where('project_id', $projectId)->find($id);
        if (! $user) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        // ── 1. Aucun décaissement en souffrance ──────────────────────────────
        // Un décaissement non résolu signifie que le solde d'une cagnotte a été
        // décrémenté sans que l'argent soit sorti. On refuse alors d'aller plus
        // loin : relancer un rapatriement empilerait un second décaissement sur
        // un premier non résolu, et une seconde tentative de suppression
        // trouverait un solde à 0, croirait n'avoir rien à rapatrier et
        // supprimerait le compte en laissant les fonds en l'air.
        //
        // « Non résolu » exclut volontairement les échecs compensés : depuis que
        // ReversementService restaure le solde sur refus explicite, un payout
        // 'echec' portant `solde_restaure` est une situation CLOSE — l'argent est
        // revenu dans la cagnotte. Bloquer dessus interdirait à jamais la
        // suppression d'un compte ayant connu un seul refus, même ancien. Les
        // 'echec' sans ce marqueur, eux, datent d'avant la compensation et
        // restent de vrais fonds en l'air.
        $enSouffrance = DB::table(project_table('payout'))
            ->whereIn('cagnotte_id', function ($q) use ($projectId, $user) {
                $q->select('id')
                    ->from(project_table('cagnottes'))
                    ->where('project_id', $projectId)
                    ->where('user_id', $user->id);
            })
            ->where(function ($q) {
                $q->whereIn('statut', ['initie', 'en_cours'])
                    ->orWhereRaw("statut = 'echec' AND COALESCE((response->>'solde_restaure')::boolean, false) = false");
            })
            ->count();

        if ($enSouffrance > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$enSouffrance} reversement(s) non abouti(s) sur ses cagnottes. Régularisez-les avant de supprimer le compte.",
            ], 409);
        }

        // ── 2. Rapatriement des soldes détenus par ses cagnottes ─────────────
        $aRapatrier = TondoCagnotte::where('project_id', $projectId)
            ->where('user_id', $user->id)
            ->where('montant_collecte', '>', 0)
            ->get();

        $reverses = [];
        $echecs   = [];

        foreach ($aRapatrier as $cagnotte) {
            $res = $reversements->reverserSolde(
                cagnotte:      $cagnotte,
                source:        'suppression_compte',
                prefixeIdem:   'TONDO-SUPPR-',
                prefixeTrans:  'TONDOSUPPR',
                cloturer:      true,
            );

            if ($res['ok']) {
                $reverses[] = [
                    'cagnotte' => $cagnotte->reference,
                    'titre'    => $cagnotte->titre,
                    'montant'  => $res['montant'],
                    'trans_id' => $res['trans_id'],
                ];
            } else {
                $echecs[] = [
                    'cagnotte' => $cagnotte->reference,
                    'titre'    => $cagnotte->titre,
                    'montant'  => $res['montant'],
                    'erreur'   => $res['erreur'],
                ];
            }
        }

        // Un seul échec suffit à tout arrêter : le compte reste intact et
        // identifiable, pour que l'incident puisse être instruit.
        if ($echecs !== []) {
            return response()->json([
                'message'   => 'Suppression interrompue : des fonds n\'ont pas pu être rapatriés.',
                'reverses'  => $reverses,
                'echecs'    => $echecs,
            ], 409);
        }

        // ── 3. Empreinte financière : purge réelle ou anonymisation ? ────────
        // Un compte qui n'a jamais rien créé ni payé ne laisse aucune trace
        // comptable à préserver : le supprimer vraiment évite d'accumuler des
        // lignes fantômes « Compte supprimé » dans la base.
        $empreinte = [
            'cagnottes'    => TondoCagnotte::where('project_id', $projectId)->where('user_id', $user->id)->exists(),
            'paiements'    => DB::table(project_table('paiements'))->where('user_id', $user->id)->exists(),
            'payin'        => DB::table(project_table('payin'))->where('user_id', $user->id)->exists(),
            'payout'       => DB::table(project_table('payout'))->where('user_id', $user->id)->exists(),
            // Une participation sans le moindre versement ne vaut pas trace
            // comptable : elle est supprimable avec le compte.
            'participation_payee' => DB::table(project_table('participants'))
                ->where('project_id', $projectId)
                ->where('user_id', $user->id)
                ->where('montant_paye', '>', 0)
                ->exists(),
            // GARDE CRITIQUE : `paiements.participant_id` est NOT NULL et
            // ON DELETE CASCADE. Supprimer une participation référencée par un
            // paiement DÉTRUIRAIT silencieusement la ligne de paiement. Le test
            // sur `montant_paye` ci-dessus n'est qu'un indice indirect ; celui-ci
            // est exact et interdit la purge dans ce cas.
            'paiement_sur_participation' => DB::table(project_table('paiements'))
                ->whereIn('participant_id', function ($q) use ($projectId, $user) {
                    $q->select('id')
                        ->from(project_table('participants'))
                        ->where('project_id', $projectId)
                        ->where('user_id', $user->id);
                })
                ->exists(),
        ];

        // La décision elle-même vit dans SuppressionCompte, testable sans base.
        $purgeReelle  = SuppressionCompte::doitPurger($empreinte);
        $ancienNumero = $user->numero;

        if ($purgeReelle) {
            DB::transaction(function () use ($user, $projectId) {
                // Aucune ligne de paiement ne pointe vers ces participations :
                // elles portent le nom et le numéro en clair, on les efface.
                DB::table(project_table('participants'))
                    ->where('project_id', $projectId)
                    ->where('user_id', $user->id)
                    ->delete();

                DB::table(project_table('device_tokens'))
                    ->where('user_id', $user->id)
                    ->delete();

                DB::table('users')->where('id', $user->id)->delete();
            });

            $this->journaliser($request, $projectId, $user->id, $ancienNumero, 'purge', null, []);

            return response()->json([
                'message'       => 'Compte supprimé définitivement (aucun historique financier).',
                'mode'          => 'purge',
                'reverses'      => [],
                'total_reverse' => 0,
            ]);
        }

        // ── 4. Anonymisation (le compte a un historique à préserver) ─────────
        // Le numéro est remplacé par un jeton unique : il libère le vrai numéro
        // pour une réinscription et rend toute connexion OTP impossible, tout en
        // respectant l'index unique (project_id, numero).
        $numeroAnonyme = 'SUPPRIME-' . strtoupper(Str::random(12));

        DB::transaction(function () use ($user, $projectId, $numeroAnonyme) {
            // Clôture ce qu'il gérait encore (soldes déjà à zéro à ce stade).
            DB::table(project_table('cagnottes'))
                ->where('project_id', $projectId)
                ->where('user_id', $user->id)
                ->whereIn('statut', ['active', 'en_cours'])
                ->update(['statut' => 'cloturee', 'updated_at' => now()]);

            // Ses lignes de participation gardent le lien comptable mais perdent
            // l'identité (elles portent nom, prénom et numéro en clair).
            DB::table(project_table('participants'))
                ->where('project_id', $projectId)
                ->where('user_id', $user->id)
                ->update([
                    'nom'                   => 'supprimé',
                    'prenom'                => 'Compte',
                    'numero_masque'         => '—',
                    'numero_retrait_masque' => null,
                ]);

            // Plus aucune notification ne doit partir vers ses appareils.
            DB::table(project_table('device_tokens'))
                ->where('user_id', $user->id)
                ->delete();

            // Le profil lui-même. `nom`, `prenom` et `date_naissance` sont NOT NULL :
            // on les remplace par des valeurs neutres plutôt que de les vider.
            DB::table('users')->where('id', $user->id)->update([
                'prenom'         => 'Compte',
                'nom'            => 'supprimé',
                'numero'         => $numeroAnonyme,
                'date_naissance' => '1900-01-01',
                'sexe'           => null,
                'adresse'        => null,
                'email'          => null,
                'kyc_valide'     => false,
                'updated_at'     => now(),
            ]);
        });

        // ── 5. Journal d'audit ───────────────────────────────────────────────
        $this->journaliser($request, $projectId, $user->id, $ancienNumero, 'anonymisation', $numeroAnonyme, $reverses);

        return response()->json([
            'message'       => 'Compte anonymisé (historique financier conservé).',
            'mode'          => 'anonymisation',
            'reverses'      => $reverses,
            'total_reverse' => array_sum(array_column($reverses, 'montant')),
        ]);
    }

    /**
     * Trace la suppression dans le journal d'audit.
     *
     * @param  string      $mode           'purge' (ligne supprimée) | 'anonymisation'.
     * @param  ?string     $numeroAnonyme  Jeton de remplacement, null en mode purge.
     * @param  array<int, array<string, mixed>> $reverses Cagnottes rapatriées.
     */
    private function journaliser(
        Request $request,
        string  $projectId,
        string  $userId,
        string  $ancienNumero,
        string  $mode,
        ?string $numeroAnonyme,
        array   $reverses,
    ): void {
        $admin = $request->user();

        DB::table(project_table('logs'))->insert([
            'id'              => (string) Str::uuid(),
            'project_id'      => $projectId,
            'acteur_admin_id' => $admin->id,
            'acteur_libelle'  => trim(($admin->prenom ?? '') . ' ' . ($admin->nom ?? '')) ?: 'Admin',
            'acteur_role'     => $admin->role,
            'action'          => 'suppression_compte',
            'cible'           => 'Utilisateur ' . $ancienNumero,
            'niveau'          => 'warning',
            'metadonnees'     => json_encode([
                'user_id'             => $userId,
                'mode'                => $mode,
                'numero_anonyme'      => $numeroAnonyme,
                'cagnottes_reversees' => $reverses,
                'total_reverse'       => array_sum(array_column($reverses, 'montant')),
            ]),
            'date'            => now(),
            'created_at'      => now(),
        ]);
    }
}
