<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
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
    use HasApiTokens, HasUuids, Notifiable, HasProjectTable;

    protected string $tableSuffix = 'admins';

    /**
     * Laravel ne gère pas auto la migration du champ `password` → `password_hash`,
     * mais on garde le nom métier de la colonne pour rester explicite côté DB.
     *
     * `password_hash` contient un hash bcrypt généré par Laravel Hash::make().
     */
    protected $fillable = [
        'project_id',
        'email',
        'nom',
        'prenom',
        'role',            // Ex : 'super_admin', 'admin', 'support'.
        'actif',
        'derniere_connexion',
        'notif_prefs',     // Préférences d'e-mails système (jsonb).
    ];

    /**
     * Préférences de notification par e-mail par défaut (opt-out : tout activé).
     * Clés = catégories d'événements système envoyés par e-mail à l'admin.
     */
    public const DEFAULT_NOTIF_PREFS = [
        'signalements' => true,   // Nouveaux signalements (cagnottes / utilisateurs)
        'problemes'    => true,   // Problèmes techniques / erreurs système
        'autre'        => true,   // Autres événements système
    ];

    /**
     * Retourne les préférences fusionnées avec les défauts (une clé absente en
     * base est considérée comme activée).
     */
    public function notifPrefs(): array
    {
        return array_merge(self::DEFAULT_NOTIF_PREFS, $this->notif_prefs ?? []);
    }

    /** Colonnes masquées dans la sérialisation JSON (ex : réponse API). */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'actif'             => 'boolean',
        'derniere_connexion' => 'datetime',
        'notif_prefs'       => 'array',
    ];

    /**
     * Override : Laravel cherche `password` par défaut mais notre colonne est
     * `password_hash` pour rester explicite côté DB.
     *
     * @return string Hash bcrypt du mot de passe.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Indique à Sanctum/Laravel le nom réel de la colonne mot de passe.
     * Nécessaire depuis Laravel 10 pour les guards qui utilisent getAuthPasswordName().
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }
}
