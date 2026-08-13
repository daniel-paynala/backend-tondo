<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoAdmin;
use App\Services\Mail\MailgunSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion des comptes administrateurs du dashboard Tondo.
 *
 * Toutes les routes sont protégées par le guard Sanctum `admin`.
 * Les actions d'écriture (créer, modifier, supprimer) sont réservées
 * aux administrateurs ayant le rôle `super_admin`.
 *
 * Rôles disponibles : super_admin | admin | operateur | lecteur.
 */
class AdminsController extends Controller
{
    /**
     * GET /api/admin/admins
     *
     * Retourne la liste paginée des admins du projet courant.
     * Filtres optionnels :
     *  - `q`       : recherche plein-texte sur nom, prénom, email
     *  - `role`    : filtre par rôle exact
     *  - `per_page`: nombre d'éléments par page (max 100, défaut 25)
     *
     * @return JsonResponse Liste paginée de TondoAdmin
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        // Plafond à 100 pour éviter les réponses trop lourdes.
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoAdmin::query()
            ->where('project_id', $projectId)
            ->when($request->input('q'), function ($q, $search) {
                // Recherche insensible à la casse (ilike = PostgreSQL).
                $q->where(function ($sub) use ($search) {
                    $sub->where('nom', 'ilike', "%{$search}%")
                        ->orWhere('prenom', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            })
            ->when($request->input('role'), fn ($q, $r) => $q->where('role', $r))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/admin/admins/{id}
     *
     * Retourne le détail d'un admin identifié par son UUID.
     * Renvoie 404 si l'admin n'appartient pas au projet courant.
     *
     * @return JsonResponse TondoAdmin
     */
    public function show(Request $request, string $id): JsonResponse
    {
        // findOrFail scoped au project_id — empêche la consultation inter-projets.
        $admin = TondoAdmin::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($admin);
    }

    /**
     * POST /api/admin/admins
     *
     * Crée un nouveau compte administrateur. Réservé aux super_admin.
     * Body : { email, password (min 8), nom, prenom, role }
     *
     * Validations clés :
     *  - email unique dans tondo_admins
     *  - rôle parmi : super_admin | admin | operateur | lecteur
     *
     * @return JsonResponse TondoAdmin créé (201)
     */
    public function store(Request $request, MailgunSender $mailer): JsonResponse
    {
        // Seuls les super_admin peuvent créer d'autres admins.
        $this->authorizeSuper($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'unique:'.project_table('admins').',email'],
            // Optionnel : si absent, un mot de passe provisoire est généré et
            // envoyé par e-mail (parcours « invitation »).
            'password' => ['nullable', 'string', 'min:8'],
            'nom' => ['required', 'string', 'max:64'],
            'prenom' => ['required', 'string', 'max:64'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'operateur', 'lecteur'])],
        ]);

        $motDePasse = $data['password'] ?? Str::password(12);

        $admin = TondoAdmin::create([
            'project_id' => $request->user()->project_id,
            // Normalisation en minuscules pour éviter les doublons case-sensitifs.
            'email' => strtolower($data['email']),
            'password_hash' => Hash::make($motDePasse),
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'role' => $data['role'],
            'actif' => true,
        ]);

        // Invitation par e-mail (identifiants + lien de connexion). Non bloquant :
        // si l'envoi échoue, l'admin est quand même créé (email_envoye = false).
        $emailEnvoye = $mailer->envoyer(
            $admin->email,
            'Votre accès au dashboard admin Tonji',
            $this->htmlInvitation($admin->prenom, $admin->email, $motDePasse),
        );

        return response()->json([
            'admin'        => $admin,
            'email_envoye' => $emailEnvoye,
        ], 201);
    }

    /**
     * Corps HTML de l'e-mail d'invitation admin (branding Tonji, français).
     */
    private function htmlInvitation(string $prenom, string $email, string $motDePasse): string
    {
        $url = config('services.admin_dashboard_url');
        $prenom = e($prenom);
        $email = e($email);
        $mdp = e($motDePasse);

        return <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#14202E;">
          <div style="background:#0A6847;padding:24px;border-radius:16px 16px 0 0;">
            <span style="color:#fff;font-size:22px;font-weight:800;">Ton<span style="color:#E8A830;">ji</span></span>
            <div style="color:rgba(255,255,255,.75);font-size:12px;letter-spacing:1px;margin-top:4px;">DASHBOARD ADMINISTRATION</div>
          </div>
          <div style="border:1px solid #E8EDE9;border-top:none;border-radius:0 0 16px 16px;padding:24px;">
            <p>Bonjour {$prenom},</p>
            <p>Un accès administrateur au dashboard <strong>Tonji</strong> vient d'être créé pour vous.</p>
            <div style="background:#F6F7F4;border:1px solid #E8EDE9;border-radius:12px;padding:16px;margin:16px 0;">
              <p style="margin:0 0 8px;"><strong>E-mail :</strong> {$email}</p>
              <p style="margin:0;"><strong>Mot de passe provisoire :</strong> <code style="background:#fff;padding:2px 6px;border-radius:4px;">{$mdp}</code></p>
            </div>
            <p><a href="{$url}" style="display:inline-block;background:#0A6847;color:#fff;text-decoration:none;padding:12px 22px;border-radius:12px;font-weight:700;">Se connecter au dashboard</a></p>
            <p style="color:#4A5568;font-size:13px;">Par sécurité, changez votre mot de passe dès votre première connexion. Si vous n'attendiez pas cette invitation, ignorez cet e-mail.</p>
          </div>
          <p style="color:#8A94A0;font-size:12px;text-align:center;margin-top:16px;">Tonji — un service de Paynala</p>
        </div>
        HTML;
    }

    /**
     * PATCH /api/admin/admins/{id}
     *
     * Modifie un admin existant. Réservé aux super_admin.
     * Champs modifiables : nom, prenom, role, actif, password.
     * Tous les champs sont optionnels (PATCH sémantique).
     *
     * @return JsonResponse TondoAdmin mis à jour
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);

        $admin = TondoAdmin::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:64'],
            'prenom' => ['sometimes', 'string', 'max:64'],
            'role' => ['sometimes', Rule::in(['super_admin', 'admin', 'operateur', 'lecteur'])],
            'actif' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        // Le mot de passe est haché séparément avant le fill() pour ne pas
        // exposer le plaintext dans les attributs du modèle.
        if (isset($data['password'])) {
            $admin->password_hash = Hash::make($data['password']);
            unset($data['password']);
        }
        $admin->fill($data)->save();

        return response()->json($admin);
    }

    /**
     * DELETE /api/admin/admins/{id}
     *
     * Supprime un compte admin. Réservé aux super_admin.
     * Interdit de supprimer son propre compte (sécurité).
     *
     * @return JsonResponse { ok: true }
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);

        // Empêche l'auto-suppression pour éviter de perdre le dernier super_admin.
        if ($request->user()->id === $id) {
            abort(422, 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $admin = TondoAdmin::where('project_id', $request->user()->project_id)
            ->findOrFail($id);
        $admin->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Vérifie que l'utilisateur courant est bien un super_admin.
     * Déclenche un 403 sinon.
     */
    private function authorizeSuper(Request $request): void
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );
    }
}
