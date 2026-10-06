<?php

namespace App\Services;

/**
 * Solde trop faible pour la sortie d'argent demandée.
 *
 * Exception dédiée pour la distinguer, dans le `catch` de la réservation de
 * {@see SortieArgent}, d'une panne de base de données : l'une se dit au client
 * mot pour mot, l'autre se journalise et devient « erreur technique ».
 */
class SoldeInsuffisant extends \RuntimeException
{
}
