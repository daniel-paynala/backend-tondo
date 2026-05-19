<?php

namespace App\Models;

use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TondoProjectConfig extends Model
{
    use UuidPrimary;

    protected $table = 'tondo_project_config';

    protected $guarded = ['id'];

    protected $casts = [
        'commission_paynala'       => 'float',
        'plafond_par_envoi'        => 'integer',
        'plafond_journalier'       => 'integer',
        'retrait_seuil_tranche'    => 'integer',
        'retrait_taux_pourcentage' => 'float',
        'retrait_forfait'          => 'integer',
    ];

    /** Convertit la ligne DB en tableau compatible AirtelFeesCalculator. */
    public function toConfigArray(): array
    {
        return [
            'operateur'          => $this->operateur,
            'pays'               => $this->pays,
            'commission_paynala' => $this->commission_paynala,
            'plafond_par_envoi'  => $this->plafond_par_envoi,
            'plafond_journalier' => $this->plafond_journalier,
            'retrait' => [
                'seuil_tranche'    => $this->retrait_seuil_tranche,
                'taux_pourcentage' => $this->retrait_taux_pourcentage,
                'forfait'          => $this->retrait_forfait,
            ],
        ];
    }

    /** Upsert depuis un tableau de frais. */
    public static function upsert(
        string $projectId,
        string $operateur,
        string $pays,
        array  $data,
    ): self {
        $row = self::firstOrNew([
            'project_id' => $projectId,
            'operateur'  => $operateur,
            'pays'       => $pays,
        ]);
        if (! $row->id) {
            $row->id         = (string) Str::uuid();
            $row->project_id = $projectId;
            $row->operateur  = $operateur;
            $row->pays       = $pays;
        }

        $row->commission_paynala       = $data['commission_paynala'];
        $row->plafond_par_envoi        = $data['plafond_par_envoi'];
        $row->plafond_journalier       = $data['plafond_journalier'];
        $row->retrait_seuil_tranche    = $data['retrait']['seuil_tranche'];
        $row->retrait_taux_pourcentage = $data['retrait']['taux_pourcentage'];
        $row->retrait_forfait          = $data['retrait']['forfait'];
        $row->save();

        return $row;
    }
}
