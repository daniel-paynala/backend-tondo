<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Refus métier d'une opération de retrait, avec le statut HTTP et un code
 * stable que le terminal peut interpréter sans lire le message.
 *
 * Le message est destiné à l'agent au comptoir ; le code, au logiciel du
 * partenaire. Les deux ne doivent pas être confondus : un libellé se reformule,
 * un code d'erreur est un contrat.
 */
class RetraitImpossible extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statut = 422,
        public readonly string $codeErreur = 'refus',
    ) {
        parent::__construct($message);
    }
}
