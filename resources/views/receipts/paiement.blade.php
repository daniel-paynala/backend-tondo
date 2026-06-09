<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 13px;
    color: #1A1F1E;
    background: #F4ECE0;
    padding: 0;
  }

  /* ── Fond page ── */
  .page {
    width: 100%;
    min-height: 100%;
    background: #F4ECE0;
    padding: 36px 32px;
  }

  /* ── En-tête ── */
  .header {
    background: #0F4C5C;
    border-radius: 12px;
    padding: 24px 28px;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
  }
  .header-bg-circle {
    position: absolute;
    border-radius: 50%;
    opacity: 0.08;
    background: #F4ECE0;
  }
  .header-bg-circle.c1 { width: 140px; height: 140px; top: -40px; right: -30px; }
  .header-bg-circle.c2 { width: 80px;  height: 80px;  bottom: -20px; left: 20px; }

  .brand-row {
    display: flex;
    align-items: center;
    margin-bottom: 6px;
  }
  .brand-dot {
    width: 28px; height: 28px;
    background: #C97B4A;
    border-radius: 7px;
    margin-right: 10px;
    display: inline-block;
    text-align: center;
    line-height: 28px;
    color: #1A1F1E;
    font-weight: 700;
    font-size: 16px;
  }
  .brand-name {
    color: #F4ECE0;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.5px;
  }
  .header-subtitle {
    color: rgba(244,236,224,0.65);
    font-size: 11px;
    letter-spacing: 0.5px;
    margin-top: 4px;
  }

  /* ── Badge statut ── */
  .status-badge {
    display: inline-block;
    background: #6B8E4E;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    letter-spacing: 0.3px;
    margin-top: 14px;
  }

  /* ── Montant principal ── */
  .amount-block {
    text-align: center;
    margin: 24px 0 28px;
  }
  .amount-label {
    font-size: 11px;
    color: #0F4C5C;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 6px;
  }
  .amount-value {
    font-size: 42px;
    font-weight: 700;
    color: #0F4C5C;
    letter-spacing: -1px;
  }
  .amount-currency {
    font-size: 20px;
    font-weight: 500;
    color: #C97B4A;
    margin-left: 4px;
  }
  .amount-fees {
    font-size: 11px;
    color: #888;
    margin-top: 4px;
  }

  /* ── Séparateur pointillé ── */
  .divider {
    border: none;
    border-top: 1.5px dashed #C97B4A;
    opacity: 0.4;
    margin: 0 0 20px;
  }

  /* ── Bloc d'infos ── */
  .info-card {
    background: #fff;
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 16px;
    border: 1px solid rgba(15,76,92,0.08);
  }
  .info-card-title {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #0F4C5C;
    font-weight: 700;
    margin-bottom: 12px;
  }
  .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid #f0ede8;
  }
  .info-row:last-child { border-bottom: none; }
  .info-label { color: #666; font-size: 12px; }
  .info-value { font-weight: 600; font-size: 12px; color: #1A1F1E; }
  .info-value.accent { color: #C97B4A; }
  .info-value.primary { color: #0F4C5C; }

  /* ── Filigrane ── */
  .watermark {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-35deg);
    font-size: 64px;
    font-weight: 900;
    color: rgba(15,76,92,0.04);
    letter-spacing: -2px;
    pointer-events: none;
    white-space: nowrap;
  }

  /* ── Pied de page ── */
  .footer {
    text-align: center;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid rgba(15,76,92,0.1);
  }
  .footer-text {
    font-size: 10px;
    color: #999;
    line-height: 1.6;
  }
  .footer-brand {
    font-size: 11px;
    font-weight: 700;
    color: #0F4C5C;
    margin-bottom: 4px;
  }

  /* ── QR / référence bloc ── */
  .ref-chip {
    display: inline-block;
    background: #0F4C5C;
    color: #F4ECE0;
    font-size: 12px;
    font-weight: 700;
    padding: 5px 14px;
    border-radius: 20px;
    letter-spacing: 0.5px;
  }
</style>
</head>
<body>
<div class="page">

  <!-- Filigrane -->
  <div class="watermark">TONDO</div>

  <!-- En-tête -->
  <div class="header">
    <div class="header-bg-circle c1"></div>
    <div class="header-bg-circle c2"></div>
    <div class="brand-row">
      <span class="brand-dot">T</span>
      <span class="brand-name">Tondo</span>
    </div>
    <div class="header-subtitle">REÇU DE PAIEMENT · PAYNALA SAS</div>
    <span class="status-badge">✓ PAIEMENT CONFIRMÉ</span>
  </div>

  <!-- Montant principal -->
  <div class="amount-block">
    <div class="amount-label">Montant cotisé</div>
    <div>
      <span class="amount-value">{{ number_format($montant_net, 0, ',', ' ') }}</span>
      <span class="amount-currency">FCFA</span>
    </div>
    <div class="amount-fees">Frais inclus · à la charge du cotisant</div>
  </div>

  <hr class="divider">

  <!-- Infos transaction -->
  <div class="info-card">
    <div class="info-card-title">Transaction</div>
    <div class="info-row">
      <span class="info-label">Référence transaction</span>
      <span class="info-value accent">{{ $trans_id }}</span>
    </div>
    <div class="info-row">
      <span class="info-label">Date & heure</span>
      <span class="info-value">{{ $date_heure }}</span>
    </div>
    <div class="info-row">
      <span class="info-label">Canal</span>
      <span class="info-value">{{ $canal }}</span>
    </div>
    @if($montant_brut !== $montant_net)
    <div class="info-row">
      <span class="info-label">Montant débité (frais inclus)</span>
      <span class="info-value">{{ number_format($montant_brut, 0, ',', ' ') }} FCFA</span>
    </div>
    @endif
  </div>

  <!-- Infos cagnotte -->
  <div class="info-card">
    <div class="info-card-title">Cagnotte</div>
    <div class="info-row">
      <span class="info-label">Nom</span>
      <span class="info-value primary">{{ $cagnotte_titre }}</span>
    </div>
    <div class="info-row">
      <span class="info-label">Référence</span>
      <span class="ref-chip"># {{ $cagnotte_reference }}</span>
    </div>
    <div class="info-row">
      <span class="info-label">Type</span>
      <span class="info-value">{{ $type_cagnotte }}</span>
    </div>
  </div>

  <!-- Infos cotisant -->
  <div class="info-card">
    <div class="info-card-title">Cotisant</div>
    <div class="info-row">
      <span class="info-label">Nom complet</span>
      <span class="info-value">{{ $nom_cotisant }}</span>
    </div>
    <div class="info-row">
      <span class="info-label">Numéro Mobile Money</span>
      <span class="info-value">{{ $numero_masque }}</span>
    </div>
  </div>

  <!-- Pied de page -->
  <div class="footer">
    <div class="footer-brand">Tondo · Paynala SAS</div>
    <div class="footer-text">
      Ce reçu est généré automatiquement et fait foi de paiement.<br>
      En cas de litige, contactez support@tondo.ga<br>
      tondo.ga · Libreville, Gabon
    </div>
  </div>

</div>
</body>
</html>
