<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Support de retrait : le CANAL par lequel un agent remet des espèces
 * (terminal de paiement, guichet, boutique, USSD…).
 *
 * Gérés depuis les paramètres du backoffice, et non codés en dur : de
 * nouveaux canaux apparaîtront au fil des partenariats.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $libelle         « Terminal de paiement »
 * @property string  $sigle           Trois lettres, « TPE ». Figé dès qu'un agent l'utilise.
 * @property ?string $description
 * @property bool    $necessite_lieu  Vrai si un agent sur ce support a une adresse physique.
 * @property bool    $actif           Faux : tous les agents de ce support sont bloqués.
 */
class TondoSupportRetrait extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des supports (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'supports_retrait';

    protected $guarded = ['id'];

    protected $casts = [
        'necessite_lieu' => 'boolean',
        'actif'          => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(TondoAgent::class, 'support_id');
    }
}
