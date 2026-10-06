<?php

namespace App\Services;

/**
 * L'opérateur a répondu NON : l'argent n'est pas parti.
 *
 * À ne lever que lorsqu'on peut l'affirmer. C'est la **seule** exception qui
 * autorise à remettre le montant dans la collecte ; toute autre issue est
 * traitée comme inconnue, et le solde reste amputé en attendant une
 * régularisation (décision du 2026-10-06).
 *
 * La distinction n'est pas cosmétique : se tromper dans ce sens-là fait croire
 * à une collecte qu'elle détient de l'argent déjà sorti, et la sortie suivante
 * le dépense une seconde fois. Rien ne le signale — ni erreur, ni journal.
 *
 * Étend `RuntimeException` pour que les `catch (\RuntimeException)` déjà en
 * place continuent de l'attraper : un appelant qui ne fait pas la distinction
 * garde l'ancien comportement prudent, il ne casse pas.
 */
class DecaissementRefuse extends \RuntimeException
{
}
