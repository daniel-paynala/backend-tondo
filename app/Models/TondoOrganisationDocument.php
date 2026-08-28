<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une **pièce justificative** déposée pour le dossier d'une association
 * ({@see TondoOrganisation}).
 *
 * Il y a au plus une pièce par `type_piece` et par organisation (un re-dépôt
 * remplace l'ancienne — contrainte d'unicité en base).
 *
 * Champ `type_piece` (les 5 pièces requises — loi n°35/62) :
 *  – 'recepisse'             : récépissé de déclaration/enregistrement.
 *  – 'statuts'               : statuts signés.
 *  – 'pv_designation'        : PV désignant le représentant légal.
 *  – 'piece_identite'        : CNI/passeport du représentant.
 *  – 'autorisation_collecte' : autorisation administrative de collecte (art.16).
 *
 * Champ `chemin` : chemin **relatif** du fichier sur le disque privé Laravel
 * (`storage/app/private`) — jamais exposé publiquement, servi par un endpoint
 * authentifié.
 *
 * Champ `statut` : 'depose' (défaut) → 'valide' | 'rejete' (modération admin).
 *
 * @property string   $id
 * @property string   $project_id
 * @property string   $organisation_id
 * @property string   $type_piece
 * @property string   $chemin           Chemin relatif sur le disque privé.
 * @property ?string  $nom_fichier      Nom d'origine du fichier.
 * @property ?string  $mime             Type MIME.
 * @property ?int     $taille_octets    Taille du fichier.
 * @property string   $statut           'depose'|'valide'|'rejete'
 * @property ?string  $motif_rejet
 */
class TondoOrganisationDocument extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des pièces (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'organisation_documents';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    /** Conversions de types. */
    protected $casts = [
        'taille_octets' => 'integer',
    ];

    /**
     * L'organisation à laquelle cette pièce appartient.
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(TondoOrganisation::class, 'organisation_id');
    }
}
