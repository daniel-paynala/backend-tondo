<?php

namespace App\Models;

use App\Models\Concerns\HasProjectTable;
use App\Models\Concerns\UuidPrimary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton push d'un appareil (registration token FCM).
 *
 * Depuis la migration OneSignal → FCM/APNs en direct, c'est nous qui stockons
 * le mapping user → device (OneSignal le faisait via `external_id`). L'app
 * (ré)enregistre son token à la connexion et le supprime à la déconnexion ;
 * le backend envoie les pushs à tous les tokens d'un user via l'API FCM v1.
 *
 * @property string $id
 * @property string $project_id  Clé de tenant multi-projet.
 * @property string $user_id     Propriétaire actuel de l'appareil (FK → users).
 * @property string $token       Registration token FCM.
 * @property string $plateforme  'android' | 'ios'.
 */
class TondoDeviceToken extends Model
{
    use UuidPrimary;
    use HasProjectTable;

    /** Table des jetons push (préfixe résolu : tondo_ / tonji_). */
    protected string $tableSuffix = 'device_tokens';

    /** Toutes les colonnes sont mass-assignables sauf la PK. */
    protected $guarded = ['id'];

    /**
     * L'utilisateur propriétaire (actuel) de cet appareil.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(TondoUser::class, 'user_id');
    }
}
