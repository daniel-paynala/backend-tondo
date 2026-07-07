<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Membre inscrit à une cagnotte ou une tontine périodique.
 *
 * Un membre peut être :
 *  – Un utilisateur Tondo enregistré (`user_id` renseigné, `est_compte_light` = false).
 *  – Un « compte light » ajouté manuellement par le gérant via saisie directe
 *    (nom/prénom + numéro uniquement, `est_compte_light` = true, `user_id` = null).
 *
 * Champ `ordre_passage` : position du membre dans la rotation de la tontine.
 * C'est lui qui détermine qui reçoit les fonds à chaque cycle.
 *
 * Champ `statut_paiement` : 'en_attente' | 'paye' | 'en_retard'.
 * Remis à 'en_attente' après chaque cycle de retrait réussi.
 *
 * @property string   $id
 * @property string   $cagnotte_id       FK → tondo_cagnottes.id
 * @property ?string  $user_id           FK → tondo_users.id (null si compte light).
 * @property string   $nom
 * @property string   $prenom
 * @property string   $numero            Numéro Mobile Money E.164.
 * @property ?string  $numero_masque     Version masquée affichée dans l'UI (ex : 07X XX XX 45).
 * @property int      $montant_paye      Total cumulé versé par ce membre (FCFA).
 * @property int      $ordre_passage     Ordre de réception dans la rotation tontine.
 * @property string   $statut_paiement   Statut du paiement pour le cycle en cours.
 * @property bool     $est_compte_light  True si ajouté manuellement sans compte Tondo.
 */
class TondoMembre extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    protected string $tableSuffix = 'participants';

    /**
     * Pas de colonnes `updated_at` sur cette table — seul `created_at` est géré
     * manuellement via le cast ci-dessous.
     */
    public $timestamps = false;

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'montant_paye'          => 'integer',
        'ordre_passage'         => 'integer',  // Position dans la rotation (1 = premier à recevoir).
        'date_dernier_paiement' => 'datetime',
        'created_at'            => 'datetime',
        'est_compte_light'      => 'boolean',
    ];
}
