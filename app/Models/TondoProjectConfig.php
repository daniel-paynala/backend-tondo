<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Configuration par opérateur/pays d'un projet Tondo (multi-tenant).
 *
 * Chaque ligne correspond à une combinaison (project_id, operateur, pays) et
 * définit les paramètres financiers utilisés par AirtelFeesCalculator :
 *  – commission Paynala (taux flottant, ex : 0.02 pour 2%).
 *  – tranches de frais opérateur (tableau JSON).
 *  – plafonds techniques par envoi et par jour.
 *  – préfixes valides pour la détection automatique de l'opérateur.
 *
 * Cette table est chargée au démarrage et mise en cache en mémoire pour
 * éviter une lecture par requête (voir AppServiceProvider ou le service
 * qui instancie AirtelFeesCalculator).
 *
 * @property string  $id
 * @property string  $project_id          Isolation multi-tenant.
 * @property string  $operateur           Ex : 'airtel', 'moov'.
 * @property string  $pays                Code ISO 2 (ex : 'GA' pour Gabon).
 * @property ?string $indicatif           Indicatif téléphonique sans '+' (ex : '241').
 * @property float   $commission_paynala  Taux de commission (ex : 0.02 = 2%).
 * @property int     $plafond_par_envoi   Montant max par transaction (FCFA).
 * @property int     $plafond_journalier  Montant max cumulé par jour (FCFA).
 * @property array   $tranches            Tableau JSON des tranches de frais opérateur.
 * @property ?array  $prefixes            Préfixes locaux valides (ex : ['077','078']).
 * @property bool    $actif               Opérateur activé pour ce projet ?
 * @property ?string $logo               Data URI de l'icône opérateur (webp/base64).
 */
class TondoProjectConfig extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table de configuration par opérateur/pays (une ligne = un opérateur actif). */
    protected string $tableSuffix = 'project_config';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'commission_paynala' => 'float',
        'plafond_par_envoi'  => 'integer',
        'plafond_journalier' => 'integer',
        'tranches'           => 'array',   // Tranches de frais opérateur (JSON array).
        'prefixes'           => 'array',   // Préfixes locaux valides (JSON array).
        'actif'              => 'boolean',
    ];

    /**
     * Convertit la ligne DB en tableau compatible AirtelFeesCalculator.
     *
     * Le calculateur de frais attend un tableau associatif normalisé ;
     * cette méthode garantit les valeurs par défaut pour les champs optionnels.
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'operateur'          => $this->operateur,
            'pays'               => $this->pays,
            'indicatif'          => $this->indicatif,
            'prefixes'           => $this->prefixes ?? [],    // Tableau vide si non défini.
            'actif'              => (bool) ($this->actif ?? true),
            'commission_paynala' => $this->commission_paynala,
            'plafond_par_envoi'  => $this->plafond_par_envoi,
            'plafond_journalier' => $this->plafond_journalier,
            'tranches'           => $this->tranches ?? [],    // Tableau vide si non défini.
            'logo'               => $this->logo,
        ];
    }

    /**
     * Insère ou met à jour la configuration d'un opérateur pour un projet.
     *
     * La clé de déduplication est (project_id, operateur, pays). Si la ligne
     * n'existe pas encore, un UUID est assigné manuellement (UuidPrimary ne
     * génère pas de valeur automatique au niveau Postgres).
     *
     * @param  string               $projectId  UUID du projet.
     * @param  string               $operateur  Identifiant de l'opérateur (ex : 'airtel').
     * @param  string               $pays       Code ISO 2 (ex : 'GA').
     * @param  array<string, mixed> $data       Paramètres financiers à enregistrer.
     * @return self                             La ligne insérée ou mise à jour.
     */
    public static function upsert(
        string $projectId,
        string $operateur,
        string $pays,
        array  $data,
    ): self {
        // Récupère la ligne existante ou crée une instance vide sans la sauvegarder.
        $row = self::firstOrNew([
            'project_id' => $projectId,
            'operateur'  => $operateur,
            'pays'       => $pays,
        ]);

        // Si c'est une nouvelle ligne (pas encore en DB), initialiser la PK et les clés.
        if (! $row->id) {
            $row->id         = (string) Str::uuid();
            $row->project_id = $projectId;
            $row->operateur  = $operateur;
            $row->pays       = $pays;
        }

        $row->commission_paynala = $data['commission_paynala'];
        $row->plafond_par_envoi  = $data['plafond_par_envoi'];
        $row->plafond_journalier = $data['plafond_journalier'];
        $row->tranches           = $data['tranches'] ?? [];
        $row->indicatif          = $data['indicatif'] ?? null;
        $row->prefixes           = $data['prefixes'] ?? null;

        // logo : data URI (data:image/webp;base64,…) ou null — on ne touche pas
        // au logo si la clé n'est pas présente dans $data (mise à jour partielle).
        if (array_key_exists('logo', $data)) {
            $row->logo = $data['logo'] ?: null;
        }

        $row->save();

        return $row;
    }
}
