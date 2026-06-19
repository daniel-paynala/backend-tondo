<?php

namespace App\Models\Concerns;

/**
 * Trait pour les modèles dont la clé primaire est un UUID généré côté Postgres
 * (`gen_random_uuid()` ou `uuid_generate_v4()`).
 *
 * Responsabilités :
 *  – Désactive l'auto-incrément Eloquent (qui suppose une PK entière séquentielle).
 *  – Déclare `keyType = 'string'` pour que les comparaisons et le casting soient corrects.
 *
 * La génération de la valeur UUID est assurée par Postgres (DEFAULT gen_random_uuid()),
 * pas par PHP — il ne faut donc PAS utiliser HasUuids de Laravel (qui génère côté PHP
 * et peut entrer en conflit avec le DEFAULT de la colonne).
 *
 * Utilisation : ajouter `use UuidPrimary;` dans tout Model qui a une PK UUID.
 */
trait UuidPrimary
{
    /**
     * Initialiseur de trait appelé automatiquement par Eloquent au constructeur du Model.
     *
     * Eloquent appelle les méthodes `initialize{TraitName}()` lors de la construction
     * du modèle, ce qui permet au trait d'initialiser des propriétés d'instance.
     */
    public function initializeUuidPrimary(): void
    {
        // Indique à Eloquent que la PK est une chaîne (UUID), pas un entier.
        $this->keyType = 'string';

        // Désactive l'auto-incrément pour que Laravel ne tente pas de lire lastInsertId().
        $this->incrementing = false;
    }
}
