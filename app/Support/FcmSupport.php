<?php

namespace App\Support;

/**
 * Helpers PURS pour l'envoi FCM (sans DB ni réseau) — extraits de FcmService
 * pour être testables : stringification des `data`, détection d'un token mort,
 * encodage base64url du JWT.
 */
class FcmSupport
{
    /** Convertit toutes les valeurs de `data` en chaînes (contrainte FCM). */
    public static function stringifier(array $data): array
    {
        $out = [];
        foreach ($data as $cle => $valeur) {
            $out[(string) $cle] = is_scalar($valeur) ? (string) $valeur : (string) json_encode($valeur);
        }

        return $out;
    }

    /**
     * Un token est considéré mort si FCM répond 404 (NOT_FOUND / UNREGISTERED).
     * Conservateur : on ne purge PAS sur 400 (peut venir d'une erreur de payload).
     */
    public static function tokenInvalide(int $status, ?array $json): bool
    {
        if ($status === 404) {
            return true;
        }

        $code = is_array($json) ? ($json['error']['status'] ?? null) : null;

        return in_array($code, ['NOT_FOUND', 'UNREGISTERED'], true);
    }

    /** Encodage base64url (sans padding) pour le JWT. */
    public static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
