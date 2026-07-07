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
     * Cache mémoire de l'ID du projet Tondo. Évite une lookup DB à chaque
     * appel API mobile (un par requête sinon). Reset entre les requêtes
     * sous Octane n'est pas un problème : le worker garde la valeur tant
     * qu'il est vivant, ce qui amortit le coût sur des centaines de requêtes.
     */
    protected static ?string $tondoId = null;

    /**
     * Retourne l'UUID du projet Tondo depuis la registry, avec mise en cache statique.
     *
     * Lève une RuntimeException si le slug 'tondo' est absent de la table
     * (configuration manquante en base — vérifier les seeds/migrations).
     *
     * @return string UUID du projet Tondo.
     * @throws \RuntimeException Si le projet 'tondo' n'existe pas en DB.
     */
    public static function tondoId(): string
    {
        // Retourner directement la valeur cachée si elle est déjà en mémoire.
        if (self::$tondoId) {
            return self::$tondoId;
        }

        // Lookup minimal : on ne charge que l'id pour limiter le payload.
        // Slug piloté par l'env (dev = « tondo », prod = « tonji »).
        $slug = config('project.slug');
        $row = self::where('slug', $slug)->first(['id']);
        if (! $row) {
            throw new \RuntimeException("Projet '{$slug}' introuvable dans la registry.");
        }

        // Mémoriser et retourner en une seule affectation.
        return self::$tondoId = $row->id;
    }
}
