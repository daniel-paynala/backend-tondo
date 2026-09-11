<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use App\Support\TypesAgent;
use Illuminate\Database\Eloquent\Model;

/**
 * Point partenaire habilité à remettre des **espèces** au bénéficiaire d'une
 * cagnotte.
 *
 * L'agent avance sa caisse et donne des billets ; il est remboursé par un flux
 * de règlement distinct. Ce n'est donc pas un décaissement Mobile Money de
 * plus : un billet remis à tort ne se conteste pas, là où un virement erroné
 * se rattrape. Tout ce qui suit découle de cette asymétrie.
 *
 * `code` est l'identifiant PUBLIC du point — court, affiché au comptoir, lu à
 * voix haute, et repris dans le SMS d'autorisation envoyé au numéro de
 * retrait. Il permet au bénéficiaire de confronter ce qu'il lit sur son
 * téléphone à ce qu'il voit au mur.
 *
 * `cle_api_hash` porte SHA-256 de la clé, jamais la clé. Voir
 * {@see TypesAgent::genererCleApi()} pour la raison du choix de SHA-256 plutôt
 * que d'un hachage salé.
 *
 * @property string      $id
 * @property string      $project_id            Clé de tenant multi-projet.
 * @property string      $code                  Identifiant public, « A-1042 ».
 * @property string      $nom                   Nom commercial affiché.
 * @property string      $type                  'tpe'|'guichet'
 * @property string      $statut                'actif'|'suspendu'
 * @property ?string     $motif_suspension      Raison, pour la traçabilité.
 * @property ?string     $ville
 * @property ?string     $quartier
 * @property ?string     $telephone             Contact exploitant, jamais affiché au bénéficiaire.
 * @property ?string     $cle_api_hash          SHA-256 de la clé d'API.
 * @property ?string     $cle_api_apercu        Quatre derniers caractères, pour reconnaissance.
 * @property ?string     $cle_api_creee_at
 * @property int         $plafond_retrait_fcfa  Plafond par opération, propre à cet agent.
 * @property ?string     $derniere_activite_at
 */
class TondoAgent extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des agents (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'agents';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    protected $casts = [
        'plafond_retrait_fcfa' => 'integer',
        'cle_api_creee_at'     => 'datetime',
        'derniere_activite_at' => 'datetime',
    ];

    /**
     * La clé et son empreinte ne doivent JAMAIS sortir du serveur.
     *
     * `cle_api_hash` suffit à se faire passer pour l'agent auprès de l'API :
     * c'est l'empreinte qu'on compare, il n'y a pas de sel à connaître en plus.
     * La masquer ici évite qu'elle parte dans une réponse JSON par la simple
     * sérialisation d'un modèle.
     */
    protected $hidden = ['cle_api_hash'];

    /**
     * Vrai si l'agent peut opérer maintenant.
     *
     * Un agent sans clé n'est pas « inactif » au sens du statut : il est
     * simplement inutilisable tant qu'aucune clé ne lui a été émise. Les deux
     * conditions sont distinctes et doivent le rester — suspendre est une
     * décision, ne pas avoir de clé est un état d'avancement.
     */
    public function estOperationnel(): bool
    {
        return $this->statut === 'actif' && $this->cle_api_hash !== null;
    }

    /** Retrouve un agent à partir de la clé qu'il présente. */
    public static function parCleApi(string $cle): ?self
    {
        return static::where('cle_api_hash', TypesAgent::empreinte($cle))->first();
    }
}
