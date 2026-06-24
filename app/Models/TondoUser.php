<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Profil utilisateur Tondo (end-user mobile). Étend auth.users de Supabase
 * en prod ; en mode test (FK users_id_fkey droppée) Laravel insère directement
 * sans passer par Supabase Auth — flow OTP statique 123456.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $nom
 * @property string  $prenom
 * @property string  $numero
 * @property string  $type_client
 * @property bool    $kyc_valide
 * @property ?string $operateur   Opérateur détecté au sign-up (ex : "airtel")
 * @property ?string $pays        Code pays ISO 2 (ex : "GA")
 * @property ?string $indicatif   Indicatif sans "+" (ex : "241")
 * @property ?string $sexe
 * @property ?string $adresse
 * @property ?string $email
 */
class TondoUser extends Authenticatable
{
    use HasApiTokens, Notifiable, UuidPrimary;

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'kyc_valide' => 'boolean',
        'date_naissance' => 'date',
    ];

    /**
     * Retourne toutes les cagnottes/tontines créées par cet utilisateur.
     *
     * Un utilisateur peut être à la fois gérant de plusieurs cagnottes
     * et membre dans d'autres (via TondoMembre).
     */
    public function cagnottes(): HasMany
    {
        return $this->hasMany(TondoCagnotte::class, 'user_id');
    }

    /**
     * Retourne le projet auquel appartient cet utilisateur (multi-tenant).
     *
     * Pour Tondo, project.slug = 'tondo'. La FK `project_id` assure
     * l'isolation des données entre projets sur la même infra Supabase.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * TondoUser n'a pas de password — c'est l'OTP qui authentifie.
     * Méthode présente pour satisfaire le contrat Authenticatable.
     */
    public function getAuthPassword(): string
    {
        return '';
    }
}
