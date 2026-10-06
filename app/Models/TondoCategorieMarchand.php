<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie de marchand : santé, école, restauration…
 *
 * Saisie en texte libre, la catégorie devenait vite « Santé », « santé » et
 * « Pharmacie » pour la même réalité, et aucun regroupement n'était fiable.
 * C'est donc une liste administrée dans Paramètres, comme les supports de
 * retrait, et la fiche marchand n'en garde qu'une référence.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $libelle
 * @property ?string $description
 * @property bool    $actif        Faux : retirée des formulaires, sans toucher aux fiches.
 */
class TondoCategorieMarchand extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des catégories (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'categories_marchands';

    protected $guarded = ['id'];

    protected $casts = [
        'actif' => 'boolean',
    ];

    /** Marchands rangés dans cette catégorie. */
    public function marchands(): HasMany
    {
        return $this->hasMany(TondoMarchand::class, 'categorie_id');
    }
}
