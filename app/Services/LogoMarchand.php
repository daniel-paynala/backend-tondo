<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Le logo d'une enseigne : où il se range, et sous quelle URL il se lit.
 *
 * Service dédié parce que **trois endroits en ont besoin** — la fiche du
 * dashboard, le carnet de l'app mobile et la route qui sert l'image — et que
 * recopier la construction de l'URL garantirait qu'un canal finisse par
 * afficher un logo périmé quand les deux autres montrent le nouveau.
 *
 * Le fichier vit dans le même bucket Supabase que les pièces des associations.
 * Il n'est pas servi depuis Supabase pour autant : voir {@see url()}.
 */
class LogoMarchand
{
    /** Formats acceptés. Pas de SVG : c'est du code, et il s'exécute. */
    public const MIMES = 'png,jpg,jpeg,webp';

    /** 512 Ko. Un logo qui dépasse n'est pas un logo, c'est une photo. */
    public const TAILLE_MAX_KO = 512;

    public function __construct(private readonly SupabaseStorageService $storage) {}

    /**
     * Range le fichier et marque la fiche. Remplace le logo précédent s'il y
     * en avait un.
     *
     * @return array{chemin: string, maj: string}
     */
    public function remplacer(string $marchandId, UploadedFile $fichier): array
    {
        // L'extension vient du MIME réel, pas du nom envoyé : un fichier
        // nommé « logo.png » peut très bien être autre chose.
        $extension = match ($fichier->getMimeType()) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $chemin = "marchands/{$marchandId}/logo.{$extension}";

        $this->storage->upload(
            $chemin,
            (string) file_get_contents($fichier->getRealPath()),
            (string) $fichier->getMimeType(),
        );

        $ancien = DB::table(project_table('marchands'))
            ->where('id', $marchandId)
            ->value('logo_chemin');

        $maj = now();

        DB::table(project_table('marchands'))
            ->where('id', $marchandId)
            ->update(['logo_chemin' => $chemin, 'logo_maj_at' => $maj]);

        // Changer de format laisse l'ancien objet derrière lui — un PNG
        // remplacé par un JPEG n'écrase pas le fichier, il en crée un autre.
        if ($ancien !== null && $ancien !== $chemin) {
            $this->storage->delete($ancien);
        }

        return ['chemin' => $chemin, 'maj' => $maj->toIso8601String()];
    }

    /** Retire le logo : l'objet du bucket et les deux colonnes. */
    public function retirer(string $marchandId): void
    {
        $chemin = DB::table(project_table('marchands'))
            ->where('id', $marchandId)
            ->value('logo_chemin');

        if ($chemin === null) {
            return;
        }

        $this->storage->delete($chemin);

        DB::table(project_table('marchands'))
            ->where('id', $marchandId)
            ->update(['logo_chemin' => null, 'logo_maj_at' => null]);
    }

    /**
     * URL publique du logo, ou null quand la fiche n'en a pas.
     *
     * **Servie par l'API, pas par Supabase.** Le bucket est privé, et une URL
     * signée expire : il faudrait en signer une par marchand à chaque
     * affichage de la liste, soit autant d'appels HTTP vers Supabase pour
     * dessiner un écran. Une route à nous donne une URL stable, que le client
     * met en cache une fois pour toutes.
     *
     * Le `?v=` porte l'horodatage du dernier téléversement : l'URL ne change
     * que lorsque l'image change, ce qui est exactement ce qu'on demande à un
     * cache.
     */
    public static function url(?string $chemin, mixed $majAt, string $marchandId): ?string
    {
        if ($chemin === null) {
            return null;
        }

        $version = $majAt instanceof \DateTimeInterface
            ? $majAt->getTimestamp()
            : (is_string($majAt) ? strtotime($majAt) : null);

        $base = url("/api/public/marchands/{$marchandId}/logo");

        return $version ? "{$base}?v={$version}" : $base;
    }
}
