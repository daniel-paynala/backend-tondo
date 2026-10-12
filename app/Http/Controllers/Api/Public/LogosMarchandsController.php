<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sert le logo d'une enseigne.
 *
 * **Publique et sans authentification**, comme le nom et le numéro du
 * commerce qu'elle accompagne : le carnet des marchands est consultable par
 * tout client qui s'apprête à payer, et une image de marque n'est pas une
 * donnée à protéger. Rien d'autre ne sort d'ici — ni le titulaire KYC, ni les
 * coordonnées du contact.
 *
 * Elle existe parce que le bucket Supabase est privé et que ses URL signées
 * expirent : il faudrait en signer une par marchand à chaque ouverture du
 * carnet, soit un appel HTTP par vignette. Ici l'URL est stable, le cache fait
 * son travail, et l'horodatage passé en `?v=` la fait changer le jour où le
 * logo change.
 */
class LogosMarchandsController extends Controller
{
    /** Une journée chez le client, une heure chez nous. */
    private const CACHE_CLIENT = 86400;
    private const CACHE_SERVEUR = 3600;

    /**
     * GET /api/public/marchands/{id}/logo
     */
    public function show(Request $request, string $id, SupabaseStorageService $storage): Response
    {
        $fiche = DB::table(project_table('marchands'))
            ->where('id', $id)
            ->first(['logo_chemin', 'logo_maj_at', 'actif']);

        // Fiche inconnue, désactivée, ou sans logo : même réponse. Distinguer
        // les trois renseignerait sur l'existence de fiches à qui tâtonne des
        // identifiants.
        if ($fiche === null || ! $fiche->actif || $fiche->logo_chemin === null) {
            return response('', 404);
        }

        // Mémorisé côté serveur : sans cela, chaque ouverture du carnet par
        // chaque client irait rechercher le même fichier chez Supabase.
        $image = Cache::remember(
            "logo_marchand:{$id}:{$fiche->logo_maj_at}",
            self::CACHE_SERVEUR,
            fn () => $storage->lire($fiche->logo_chemin),
        );

        if ($image === null) {
            // Le chemin est en base mais l'objet a disparu. On ne mémorise pas
            // cet échec : le logo peut revenir au prochain téléversement.
            Cache::forget("logo_marchand:{$id}:{$fiche->logo_maj_at}");
            return response('', 404);
        }

        $etag = '"' . md5($image['contenu']) . '"';

        // Le client qui a déjà l'image n'a pas à la retélécharger.
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304);
        }

        return response($image['contenu'], 200, [
            'Content-Type'   => $image['mime'],
            'Content-Length' => (string) strlen($image['contenu']),
            'Cache-Control'  => 'public, max-age=' . self::CACHE_CLIENT,
            'ETag'           => $etag,
        ]);
    }
}
