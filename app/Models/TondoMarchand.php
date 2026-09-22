<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Marchand : une DESTINATION de transfert, pas un compte.
 *
 * Le client qui gère une cagnotte peut envoyer le solde ailleurs que sur son
 * propre numéro de retrait — vers une école, un traiteur, un magasin. Pour que
 * ce ne soit pas un numéro saisi au hasard, la destination doit avoir été
 * enregistrée ici, avec le nom commercial que le client verra et le nom du
 * titulaire renvoyé par le KYC Airtel.
 *
 * Le décaissement lui-même ne change pas : même appel Paynala, même cagnotte
 * débitée. Seule la ligne de payout porte en plus `type_beneficiaire` et
 * `marchand_id`, ce qui permet d'isoler ces sorties dans le dashboard.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $nom                   Nom commercial affiché au client.
 * @property string  $numero_tel            Numéro Airtel qui encaisse, en E.164.
 * @property ?string $titulaire             Nom renvoyé par le KYC Airtel.
 * @property ?string $type_paynala          entreprise => B2B, particulier => B2C.
 * @property bool    $actif                 Faux : le marchand sort du parcours client.
 */
class TondoMarchand extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des marchands (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'marchands';

    protected $guarded = ['id'];

    protected $casts = [
        'actif'                => 'boolean',
        'titulaire_verifie_at' => 'datetime',
    ];

    /**
     * Nombre de paiements et total encaissé, pour une liste de marchands.
     *
     * Compté en une seule requête : la liste du dashboard affiche ces deux
     * chiffres sur chaque fiche, et une sous-requête par ligne coûterait autant
     * d'allers-retours que de marchands.
     *
     * @param  iterable<string> $marchandIds
     * @return array<string, array{nb: int, total: int}> indexé par marchand_id
     */
    public static function statistiques(iterable $marchandIds): array
    {
        $ids = collect($marchandIds)->values()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table(project_table('payout'))
            ->whereIn('marchand_id', $ids)
            ->selectRaw('marchand_id, count(*) as nb, coalesce(sum(montant) filter (where statut = ?), 0) as total', ['succes'])
            ->groupBy('marchand_id')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->marchand_id => ['nb' => (int) $l->nb, 'total' => (int) $l->total]])
            ->all();
    }
}
