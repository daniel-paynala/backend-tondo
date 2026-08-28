<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Représente une **association** dans Tonji (compte de type `association`).
 *
 * Créée quand un utilisateur déclare, à l'aiguillage post-inscription, que son
 * compte est destiné à une association, puis renseigne le nom + la description
 * et dépose les pièces requises (table {@see TondoOrganisationDocument}).
 *
 * Champ `statut` (cycle de vie du dossier, modéré côté admin) :
 *  – 'en_attente' : dossier soumis, en cours de vérification (défaut).
 *  – 'approuve'   : validé → l'association peut collecter.
 *  – 'rejete'     : refusé (voir `motif_rejet`).
 *  – 'suspendu'   : suspendu après coup.
 *
 * Champ `plafond_fcfa` : plafond de collecte CUMULÉE par cagnotte. Défaut
 * 10 000 000 FCFA (seuil préfectoral, loi n°35/62). Ajustable par l'admin sur
 * justificatif (autorisation du Conseil des ministres) pour dépasser 10 M.
 *
 * @property string   $id
 * @property string   $project_id      Clé de tenant multi-projet.
 * @property string   $user_id         Compte représentant (FK → users).
 * @property string   $nom             Nom de l'association.
 * @property ?string  $description     Présentation libre (« parlez-nous de vous »).
 * @property string   $statut          'en_attente'|'approuve'|'rejete'|'suspendu'
 * @property ?string  $motif_rejet     Raison affichée au user si rejeté/suspendu.
 * @property int      $plafond_fcfa    Plafond cumulé par cagnotte (défaut 10 M).
 * @property ?string  $numero_retrait  Mobile Money de reversement (optionnel).
 */
class TondoOrganisation extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des organisations (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'organisations';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    /** Conversions de types. */
    protected $casts = [
        'plafond_fcfa' => 'integer',
    ];

    /**
     * Les pièces déposées pour cette association (récépissé, statuts, etc.).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TondoOrganisationDocument::class, 'organisation_id');
    }

    /**
     * Le compte représentant qui gère le dossier de l'association.
     */
    public function representant(): BelongsTo
    {
        return $this->belongsTo(TondoUser::class, 'user_id');
    }
}
