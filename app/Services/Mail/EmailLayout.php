<?php

namespace App\Services\Mail;

/**
 * Gabarit HTML professionnel et partagé pour tous les e-mails système Tonji.
 * Compatible clients mail (tables + styles inline). Marque Tonji : vert forêt
 * (#0A6847) + or (#E8A830), pied « un service de Paynala ».
 */
class EmailLayout
{
    /**
     * Enveloppe un contenu dans le gabarit Tonji.
     *
     * @param  string       $titre     Titre principal (h1) affiché dans la carte.
     * @param  string       $corpsHtml Contenu HTML du corps (paragraphes, etc.).
     * @param  string|null  $ctaLabel  Libellé du bouton d'action (optionnel).
     * @param  string|null  $ctaUrl    URL du bouton d'action (optionnel).
     * @param  string|null  $preheader Texte d'aperçu (masqué, affiché par les clients).
     */
    public static function render(
        string $titre,
        string $corpsHtml,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        ?string $preheader = null,
    ): string {
        $annee = date('Y');
        $titreSafe = e($titre);

        $preheaderHtml = $preheader
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.e($preheader).'</div>'
            : '';

        $cta = '';
        if ($ctaLabel && $ctaUrl) {
            $cta = <<<HTML
            <tr><td style="padding:8px 0 4px;">
              <a href="{$ctaUrl}" style="display:inline-block;background:#0A6847;color:#ffffff;text-decoration:none;padding:13px 26px;border-radius:12px;font-weight:700;font-size:15px;">{$ctaLabel}</a>
            </td></tr>
            HTML;
        }

        return <<<HTML
        <!doctype html>
        <html lang="fr">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <meta name="color-scheme" content="light">
          <title>{$titreSafe}</title>
        </head>
        <body style="margin:0;padding:0;background:#ECEDE9;">
          {$preheaderHtml}
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECEDE9;padding:28px 12px;">
            <tr><td align="center">
              <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

                <!-- En-tête : vrai logo Tonji (icône app) sur fond vert marque -->
                <tr><td style="background:#0A6847;padding:20px 28px;border-radius:16px 16px 0 0;">
                  <img src="https://tonji.ga/logo-tonji.png" alt="Tonji" width="46" height="46" style="display:block;width:46px;height:46px;border-radius:10px;">
                </td></tr>

                <!-- Corps -->
                <tr><td style="background:#ffffff;padding:32px 28px;border-left:1px solid #E8EDE9;border-right:1px solid #E8EDE9;">
                  <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#14202E;font-weight:800;">{$titreSafe}</h1>
                  <div style="font-size:15px;line-height:1.6;color:#14202E;">
                    {$corpsHtml}
                  </div>
                  <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px;">
                    {$cta}
                  </table>
                </td></tr>

                <!-- Pied -->
                <tr><td style="background:#ffffff;padding:20px 28px 28px;border:1px solid #E8EDE9;border-top:none;border-radius:0 0 16px 16px;">
                  <hr style="border:none;border-top:1px solid #E8EDE9;margin:0 0 14px;">
                  <p style="margin:0;font-size:12px;color:#8A94A0;">
                    Cet e-mail vous est envoyé automatiquement par le système Tonji. Vous pouvez gérer vos préférences de notification depuis votre profil dans le dashboard.
                  </p>
                </td></tr>

                <tr><td style="padding:16px 8px 0;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#8A94A0;">© {$annee} Tonji — un service de Paynala</p>
                </td></tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
