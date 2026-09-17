<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent de retrait : la PERSONNE qui remet des espèces au titulaire d'une
 * cagnotte, pour le compte d'un partenaire et sur un support donné.
 *
 * Un billet remis à tort ne se conteste pas, là où un virement erroné se
 * rattrape. D'où un compte plus strict que celui d'un utilisateur :
 *
 *  – `identifiant` (« ECKTPE020 ») est figé à la création. Il sert à la
 *    connexion ET d'identifiant public, repris dans le SMS d'autorisation et
 *    affiché au comptoir ;
 *  – le PIN est haché en bcrypt, verrouillé après cinq échecs, et doit être
 *    changé à la première connexion ;
 *  – le partenaire et le support ne se modifient pas : l'identifiant les
 *    encode. Un agent qui en change est un nouvel agent.
 *
 * @property string  $id
 * @property string  $project_id
 * @property string  $partenaire_id
 * @property string  $support_id
 * @property int     $numero                   Rang dans le couple partenaire × support.
 * @property string  $identifiant              « ECKTPE020 ».
 * @property string  $nom
 * @property ?string $telephone
 * @property ?string $ville
 * @property ?string $quartier
 * @property string  $statut                   'actif'|'suspendu'
 * @property ?string $motif_suspension
 * @property string  $pin_hash                 bcrypt — jamais le PIN.
 * @property bool    $pin_doit_changer
 * @property int     $pin_tentatives_echouees
 * @property ?\Illuminate\Support\Carbon $pin_verrouille_at
 * @property int     $plafond_operation_fcfa
 * @property int     $plafond_journalier_fcfa
 */
class TondoAgent extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des agents (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'agents';

    protected $guarded = ['id'];

    protected $casts = [
        'numero'                  => 'integer',
        'pin_doit_changer'        => 'boolean',
        'pin_tentatives_echouees' => 'integer',
        'pin_verrouille_at'       => 'datetime',
        'pin_modifie_at'          => 'datetime',
        'plafond_operation_fcfa'  => 'integer',
        'plafond_journalier_fcfa' => 'integer',
        'derniere_connexion_at'   => 'datetime',
        'derniere_activite_at'    => 'datetime',
    ];

    /** Même bcrypt, un PIN à 4 chiffres ne doit jamais quitter le serveur. */
    protected $hidden = ['pin_hash'];

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(TondoPartenaireRetrait::class, 'partenaire_id');
    }

    public function support(): BelongsTo
    {
        return $this->belongsTo(TondoSupportRetrait::class, 'support_id');
    }

    /**
     * Nombre de retraits passés par chacun des agents donnés.
     *
     * Une seule requête pour toute une liste — la page des agents en affiche
     * plusieurs centaines. Les agents sans retrait n'apparaissent pas dans le
     * résultat : lire avec `?? 0`.
     *
     * @param  iterable<string> $agentIds
     * @return array<string, int>  agent_id => nombre de retraits
     */
    public static function nombresRetraits(iterable $agentIds): array
    {
        $ids = collect($agentIds)->values()->all();
        if ($ids === []) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table(project_table('retraits_especes'))
            ->whereIn('agent_id', $ids)
            ->selectRaw('agent_id, count(*) as n')
            ->groupBy('agent_id')
            ->pluck('n', 'agent_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function estVerrouille(): bool
    {
        return $this->pin_verrouille_at !== null;
    }

    /**
     * Raison pour laquelle l'agent ne peut pas opérer maintenant, ou null.
     *
     * Quatre conditions indépendantes, vérifiées à chaque connexion plutôt que
     * propagées par écriture : désactiver un partenaire bloque ses agents
     * immédiatement, sans réécrire leurs statuts — et les réactiver les
     * débloque sans risquer de réactiver un agent suspendu pour une autre
     * raison.
     */
    public function motifBlocage(): ?string
    {
        if ($this->statut !== 'actif') {
            return 'Agent suspendu.';
        }
        if ($this->estVerrouille()) {
            return 'PIN verrouillé après trop d\'échecs. Contactez Tonji.';
        }
        if ($this->partenaire && ! $this->partenaire->actif) {
            return 'Partenaire désactivé.';
        }
        if ($this->support && ! $this->support->actif) {
            return 'Support de retrait désactivé.';
        }

        return null;
    }
}
