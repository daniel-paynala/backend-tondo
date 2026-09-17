<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Opération d'argent réel refusée sur l'environnement de test.
 *
 * Levée AVANT tout appel réseau et, aux points d'entrée, avant toute
 * réservation de fonds : quand elle survient, il est certain qu'aucun argent
 * n'a bougé et qu'aucun solde n'a été touché.
 */
class OperationReelleBloquee extends RuntimeException
{
}
