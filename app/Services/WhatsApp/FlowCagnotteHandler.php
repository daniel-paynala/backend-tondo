<?php

namespace App\Services\WhatsApp;

use App\Models\TondoUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Traite la soumission du Flow « Créer une cagnotte » (formulaire natif WhatsApp).
 *
 * Reçoit les champs renvoyés par Meta (nfm_reply) et crée la cagnotte via
 * {@see CreerCagnotteService}, sans passer par le parcours texte de BotService
 * (qui n'est pas modifié). Couche additive : n'est appelé que si le Flow est
 * configuré et actif (voir WebhookController + services.whatsapp.flows).
 *
 * Périmètre du pilote : uniquement les créateurs déjà **inscrits** (compte
 * complet). Pour un numéro sans compte complet (KYC/OTP nécessaires), on
 * renvoie vers le parcours texte — comme le fait handleCreerNumero.
 */
class FlowCagnotteHandler
{
    public function __construct(
        private CreerCagnotteService $creerSvc,
        private SessionService $session,
    ) {}

    /**
     * Crée la cagnotte à partir des champs du formulaire, ou renvoie un message
     * d'erreur / de repli. Réinitialise la session dans tous les cas terminaux.
     *
     * @param  string               $from    Numéro E.164 de l'expéditeur WhatsApp.
     * @param  array<string,mixed>  $champs  Réponses du formulaire (titre, objectif, date_fin, numero_retrait).
     * @return string                        Message texte à renvoyer à l'utilisateur.
     */
    public function traiter(string $from, array $champs): string
    {
        // ── Titre (3–120 caractères, même règle que handleCreerCotisationNom) ──
        $titre = trim((string) ($champs['titre'] ?? ''));
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            $this->session->reset($from);
            return "⚠️ Nom de cagnotte invalide (3 à 120 caractères). Reprends en tapant *3*.";
        }

        // ── Numéro de retrait → E.164 ──────────────────────────────────────────
        $numeroRetrait = $this->normaliserNumero((string) ($champs['numero_retrait'] ?? ''));
        if (! $numeroRetrait) {
            $this->session->reset($from);
            return "⚠️ Numéro de retrait invalide. Reprends en tapant *3*.";
        }

        // ── Objectif (optionnel) : accepté seulement entre 100 et 2 500 000 ────
        $objectif = (int) preg_replace('/\D/', '', (string) ($champs['objectif'] ?? ''));
        $montantCible = ($objectif >= 100 && $objectif <= 2_500_000) ? $objectif : 0;

        // ── Date de fin (optionnelle) : le DatePicker renvoie un timestamp ms ──
        $dateFin = $this->normaliserDate((string) ($champs['date_fin'] ?? ''));

        // ── Résolution du créateur par le numéro de retrait (comme le texte) ───
        $projectId = $this->projectId();
        $user      = $this->utilisateurParNumero($numeroRetrait, $projectId);

        // Pilote : seulement les comptes complets (nom + prénom). Sinon, repli
        // texte pour passer par le KYC/OTP (handleCreerNumero s'en charge).
        if (! $user || trim((string) $user->nom) === '' || trim((string) $user->prenom) === '') {
            $this->session->reset($from);
            return "📋 Ce numéro n'a pas encore de compte Tonji complet.\n"
                . "Reprends la création en tapant *3* : on te guidera pas à pas (vérification incluse).";
        }

        // ── Création ───────────────────────────────────────────────────────────
        try {
            $cagnotte = $this->creerSvc->creer([
                'type'           => 'cagnotte_ouverte',
                'titre'          => $titre,
                'montant_cible'  => $montantCible,
                'date_fin'       => $dateFin,
                'numero_retrait' => $numeroRetrait,
                'user_id'        => $user->id,
                'project_id'     => $projectId,
            ], $user);
        } catch (\Throwable $e) {
            Log::error('[flow creer_cagnotte] échec création', ['from' => $from, 'error' => $e->getMessage()]);
            $this->session->reset($from);
            return "❌ Erreur lors de la création. Réessaie en tapant *3*, ou contacte support@tonji.ga.";
        }

        // Parcours terminé → on repart d'une session propre.
        $this->session->reset($from);

        // ── Message de succès (aligné sur BotService) + lien d'invitation ──────
        $prenom = ucfirst(mb_strtolower((string) $user->prenom));
        $ref    = $cagnotte->reference;
        $botNum = ltrim((string) config('tondo.whatsapp_numero', ''), '+');
        $lienWa = $botNum
            ? "\nhttps://wa.me/{$botNum}?text=" . rawurlencode("TONJI {$ref}")
            : " N°*{$ref}*";

        return <<<TXT
        🎉 *{$cagnotte->titre}* créée avec succès !

        Félicitations *{$prenom}* !
        Ta cagnotte est active.

        *Code de la cagnotte : N°{$ref}*
        Partage ce lien à tes membres :{$lienWa}
        TXT;
    }

    // ── Privé ──────────────────────────────────────────────────────────────────

    /** UUID du projet courant (même source que BotService::tondoProjectId). */
    private function projectId(): string
    {
        return (string) (DB::table('projects')->where('slug', config('project.slug'))->value('id') ?? '');
    }

    /**
     * Recherche un utilisateur par les 9 derniers chiffres du numéro (tolère
     * +241XXXXXXXX vs 0XXXXXXXX), identique à BotService::utilisateurParNumero.
     */
    private function utilisateurParNumero(string $numeroE164, string $projectId): ?TondoUser
    {
        $suffixe = substr(preg_replace('/\D/', '', $numeroE164), -9);

        return TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();
    }

    /**
     * Normalise une saisie téléphonique en E.164 gabonais (+241XXXXXXXX).
     * Renvoie null si aucun chiffre exploitable.
     */
    private function normaliserNumero(string $saisie): ?string
    {
        $digits = preg_replace('/\D/', '', $saisie);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with(trim($saisie), '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '241')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+241' . substr($digits, 1);
        }
        if (strlen($digits) === 8) {
            return '+241' . $digits;
        }
        return '+' . $digits;
    }

    /**
     * Convertit la valeur du DatePicker en 'Y-m-d', ou null.
     * WhatsApp renvoie un timestamp en millisecondes ; on tolère aussi 'Y-m-d'.
     * Une date passée (ou aujourd'hui) est ignorée (= pas de limite).
     */
    private function normaliserDate(string $brut): ?string
    {
        $brut = trim($brut);
        if ($brut === '') {
            return null;
        }

        $date = null;
        if (ctype_digit($brut)) {
            $date = date('Y-m-d', (int) ((int) $brut / 1000));
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $brut)) {
            $date = substr($brut, 0, 10);
        }

        // Doit être strictement dans le futur, sinon on considère « pas de limite ».
        if ($date && $date <= date('Y-m-d')) {
            return null;
        }
        return $date;
    }
}
