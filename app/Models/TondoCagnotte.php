<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Représente une cagnotte ou une tontine périodique dans Tondo.
 *
 * Champ `type` :
 *  – 'tontine_periodique' : rotation entre membres avec payout cyclique.
 *  – 'cagnotte_ouverte'   : collecte libre, reversement sur le numéro de retrait.
 *
 * Champ `statut` : 'brouillon' → 'en_cours' → 'cloturee'.
 *
 * Champ `reference` : identifiant numérique court (4-5 chiffres) généré à la
 * création et **immutable** une fois la cagnotte active (règle anti-fraude).
 *
 * Champ `numero_retrait` : numéro Mobile Money E.164 du bénéficiaire des fonds.
 * Lui aussi immutable après activation.
 *
 * @property string   $id
 * @property string   $project_id       Clé de tenant multi-projet.
 * @property string   $user_id          Gérant de la cagnotte (FK → tondo_users).
 * @property string   $type             'tontine_periodique'|'cagnotte_ouverte'
 * @property string   $statut           'brouillon'|'en_cours'|'cloturee'
 * @property string   $reference        Code numérique court (ex : "4821").
 * @property string   $titre
 * @property ?string  $numero_retrait   Numéro E.164 du bénéficiaire.
 * @property int      $montant_collecte Solde courant en FCFA.
 * @property ?int     $montant_cible    Seuil de déclenchement du reversement.
 * @property ?int     $montant_par_cycle Montant fixe par cycle (tontine).
 * @property bool     $reversement_auto  Reversement automatique activé ?
 * @property ?int     $reversement_auto_frequence_mois Période en mois (mode libre).
 */
class TondoCagnotte extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table principale des cagnottes/tontines. */
    protected string $tableSuffix = 'cagnottes';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    /** Conversions automatiques des types pour les colonnes numériques et dates. */
    protected $casts = [
        'montant_collecte'                => 'integer',
        'montant_beneficiaire'            => 'integer',
        'montant_avec_frais'              => 'integer',
        'total_a_envoyer'                 => 'integer',
        'montant_cible'                   => 'integer',
        'montant_par_cycle'               => 'integer',
        'nombre_participants'             => 'integer',
        'nombre_splits'                   => 'integer',
        'nombre_envois'                   => 'integer',
        'intervalle'                      => 'integer',  // Intervalle de cycle en jours/mois selon periodicite.
        'jour_mois'                       => 'integer',  // Jour du mois pour les tontines mensuelles.
        'date_creation'                   => 'datetime',
        'date_fin'                        => 'datetime',
        'date_demarrage'                  => 'datetime',
        'reversement_auto'                => 'boolean',
        'reversement_auto_frequence_mois' => 'integer',
    ];

    /**
     * Retourne l'utilisateur gérant de cette cagnotte.
     *
     * La FK est `user_id` (pas `tondo_cagnotte_id`) — l'alias `gerant` est
     * plus explicite que le nom par défaut `user`.
     */
    public function gerant(): BelongsTo
    {
        return $this->belongsTo(TondoUser::class, 'user_id');
    }

    /**
     * Retourne la liste des membres inscrits à cette cagnotte/tontine.
     *
     * Pour les tontines périodiques, les membres ont un `ordre_passage`
     * qui détermine l'ordre de réception des fonds.
     */
    public function membres(): HasMany
    {
        return $this->hasMany(TondoMembre::class, 'cagnotte_id');
    }
}
