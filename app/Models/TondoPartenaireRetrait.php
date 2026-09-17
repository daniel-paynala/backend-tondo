<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use App\Support\RetraitAgents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partenaire de retrait : l'ÉTABLISSEMENT dont les agents remettent des
 * espèces (Ecobank, UBA, Sengab…).
 *
 * Deux rôles qui justifient d'en faire une entité à part :
 *  – c'est lui que Tonji rembourse des espèces avancées, jamais agent par
 *    agent ;
 *  – c'est son système qui présente la clé d'API. L'agent n'apporte que son
 *    PIN : un PIN volé ne sert donc à rien hors d'un terminal du partenaire.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $nom                    « Ecobank Gabon »
 * @property string  $sigle                  Trois lettres, « ECK ». Figé dès qu'un agent l'utilise.
 * @property bool    $actif                  Faux : tous ses agents sont bloqués.
 * @property ?string $contact_nom
 * @property ?string $contact_telephone
 * @property ?string $contact_email
 * @property ?string $reglement_coordonnees  Où et comment Tonji le rembourse.
 * @property ?string $cle_api_hash           SHA-256 de la clé — jamais la clé.
 * @property ?string $cle_api_apercu
 */
class TondoPartenaireRetrait extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des partenaires (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'partenaires_retrait';

    protected $guarded = ['id'];

    protected $casts = [
        'actif'            => 'boolean',
        'cle_api_creee_at' => 'datetime',
    ];

    /**
     * L'empreinte suffit à se faire passer pour le partenaire : on la compare
     * directement, il n'y a pas de sel à connaître en plus. La masquer évite
     * qu'elle parte dans une réponse JSON par simple sérialisation.
     */
    protected $hidden = ['cle_api_hash'];

    public function agents(): HasMany
    {
        return $this->hasMany(TondoAgent::class, 'partenaire_id');
    }

    /** Retrouve un partenaire à partir de la clé que présente son système. */
    public static function parCleApi(string $cle): ?self
    {
        return static::where('cle_api_hash', RetraitAgents::empreinteCle($cle))->first();
    }
}
