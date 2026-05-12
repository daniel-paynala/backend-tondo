<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry des projets multi-tenants hébergés sur l'instance Supabase
 * Paynala. Pour Tondo, slug = 'tondo'.
 *
 * @property string $id
 * @property string $slug
 * @property string $nom
 */
class Project extends Model
{
    use UuidPrimary;

    protected $table = 'projects';

    protected $guarded = ['id'];

    /**
     * Cache mémoire de l'ID du projet Tondo. Évite une lookup à chaque
     * appel API mobile (un par requête sinon). Reset entre les requêtes
     * sous Octane n'est pas un problème : le worker garde la valeur tant
     * qu'il est vivant, donc on amortit sur des centaines de requêtes.
     */
    protected static ?string $tondoId = null;

    public static function tondoId(): string
    {
        if (self::$tondoId) {
            return self::$tondoId;
        }

        $row = self::where('slug', 'tondo')->first(['id']);
        if (! $row) {
            throw new \RuntimeException("Projet 'tondo' introuvable dans la registry.");
        }

        return self::$tondoId = $row->id;
    }
}
