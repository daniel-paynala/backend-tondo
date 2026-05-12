<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminsController extends Controller
{
    /** GET /api/admin/admins */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoAdmin::query()
            ->where('project_id', $projectId)
            ->when($request->input('q'), function ($q, $search) {
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

    /** GET /api/admin/admins/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $admin = TondoAdmin::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($admin);
    }

    /** POST /api/admin/admins — créer un nouveau admin (super_admin only). */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'unique:tondo_admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'nom' => ['required', 'string', 'max:64'],
            'prenom' => ['required', 'string', 'max:64'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'operateur', 'lecteur'])],
        ]);

        $admin = TondoAdmin::create([
            'project_id' => $request->user()->project_id,
            'email' => strtolower($data['email']),
            'password_hash' => Hash::make($data['password']),
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'role' => $data['role'],
            'actif' => true,
        ]);

        return response()->json($admin, 201);
    }

    /** PATCH /api/admin/admins/{id} — modifier nom/role/actif (super_admin only). */
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

        if (isset($data['password'])) {
            $admin->password_hash = Hash::make($data['password']);
            unset($data['password']);
        }
        $admin->fill($data)->save();

        return response()->json($admin);
    }

    /** DELETE /api/admin/admins/{id} — supprimer (super_admin only). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);

        if ($request->user()->id === $id) {
            abort(422, 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $admin = TondoAdmin::where('project_id', $request->user()->project_id)
            ->findOrFail($id);
        $admin->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );
    }
}
