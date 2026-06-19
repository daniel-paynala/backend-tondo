<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Enregistrement d'un paiement (payin) validé par un participant.
 *
 * Chaque ligne correspond à une cotisation réussie : le montant a été débité
 * du compte Mobile Money de l'utilisateur et crédité sur la cagnotte.
 *
 * Champ `trans_id` : identifiant de transaction Airtel Money (ou autre agrégateur).
 * Sert de référence pour les rapprochements comptables et le débogage.
 *
 * Champ `statut` : 'succes' | 'echec' | 'en_attente'.
 *
 * Cette table est distincte de `tondo_payin` (audit brut de l'appel API) :
 * `tondo_paiements` est la vue métier, `tondo_payin` est la piste d'audit technique.
 *
 * @property string  $id
 * @property string  $project_id    Isolation multi-tenant.
 * @property string  $cagnotte_id   FK → tondo_cagnottes.id
 * @property string  $user_id       FK → tondo_users.id (payeur).
 * @property string  $trans_id      Identifiant de transaction Mobile Money.
 * @property int     $montant       Montant net reçu par la cagnotte (FCFA, frais déduits).
 * @property string  $statut        'succes'|'echec'|'en_attente'
 * @property \Illuminate\Support\Carbon $date Date effective du paiement.
 */
class TondoPaiement extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_paiements';

    /** Pas de gestion automatique updated_at — la date métier est le champ `date`. */
    public $timestamps = false;

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'montant'    => 'integer',
        'date'       => 'datetime',  // Date effective du paiement (peut différer de created_at).
        'created_at' => 'datetime',
    ];
}
