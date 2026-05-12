<?php

namespace App\Models\Concerns;

/**
 * Trait pour les modèles dont la PK est un UUID Postgres (`gen_random_uuid()`).
 * Désactive l'auto-incrément et déclare le keyType en string.
 */
trait UuidPrimary
{
    public function initializeUuidPrimary(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }
}
