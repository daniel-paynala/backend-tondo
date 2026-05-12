<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte administrateur du dashboard Tondo.
 *
 * Distinct de Supabase Auth (qui gère les end-users mobile via phone OTP).
 * Auth via email + password bcrypt + token Sanctum.
 *
 * @property string $id
 * @property string $email
 * @property string $nom
 * @property string $prenom
 * @property string $role
 * @property bool   $actif
 */
class TondoAdmin extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    protected $table = 'tondo_admins';

    /**
     * Laravel ne gère pas auto la migration du champ `password` → `password_hash`,
     * mais on garde le nom métier de la colonne.
     */
    protected $fillable = [
        'project_id',
        'email',
        'nom',
        'prenom',
        'role',
        'actif',
        'derniere_connexion',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'derniere_connexion' => 'datetime',
    ];

    /**
     * Override : Laravel cherche `password` par défaut mais notre colonne est
     * `password_hash` pour rester explicite côté DB.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }
}
