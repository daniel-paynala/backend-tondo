<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestes communs à l'administration du retrait en espèces : supports,
 * partenaires et agents.
 *
 * Habiliter un tiers à distribuer de l'argent liquide n'est pas de la gestion
 * courante. Toute écriture est donc réservée aux super admins et laisse une
 * trace nominative dans le journal d'audit.
 */
trait GereRetraitAgents
{
    protected function exigerSuperAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );
    }

    /**
     * Trace l'action dans le journal d'audit.
     *
     * @param  'info'|'warning'|'error' $niveau  Valeurs admises par la colonne.
     * @param  array<string, mixed>     $metadonnees
     */
    protected function journaliser(
        Request $request,
        string  $action,
        string  $cible,
        string  $niveau,
        array   $metadonnees,
    ): void {
        $admin = $request->user();

        DB::table(project_table('logs'))->insert([
            'id'              => (string) Str::uuid(),
            'project_id'      => $admin->project_id,
            'acteur_admin_id' => $admin->id,
            'acteur_libelle'  => trim(($admin->prenom ?? '') . ' ' . ($admin->nom ?? '')) ?: 'Admin',
            'acteur_role'     => $admin->role,
            'action'          => $action,
            'cible'           => $cible,
            'niveau'          => $niveau,
            'metadonnees'     => json_encode($metadonnees),
            'date'            => now(),
            'created_at'      => now(),
        ]);
    }
}
