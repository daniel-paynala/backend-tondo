<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 10px;
  color: #111;
  background: #fff;
}

/* ── Filigrane ────────────────────────────────────── */
.wm {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  text-align: center;
  padding-top: 42%;
  font-size: 52px;
  font-weight: 900;
  color: #0A6847;
  opacity: 0.05;
  letter-spacing: 14px;
  z-index: 0;
}
.page { position: relative; z-index: 1; }

/* ── Header ───────────────────────────────────────── */
.hdr { padding: 14px 16px 10px; border-bottom: 2px solid #0A6847; }
.hdr-inner { width: 100%; border-collapse: collapse; }
.logo-cell { vertical-align: middle; }
.logo-circle {
  display: inline-block;
  width: 28px; height: 28px;
  border-radius: 14px;
  background: #0A6847;
  color: #fff;
  font-size: 14px;
  font-weight: 900;
  text-align: center;
  line-height: 28px;
  vertical-align: middle;
  margin-right: 6px;
}
.brand-name {
  font-size: 22px;
  font-weight: 900;
  color: #0A6847;
  letter-spacing: -0.5px;
  vertical-align: middle;
}
.hdr-right { text-align: right; vertical-align: middle; }
.recu-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #111;
}
.recu-sub {
  font-size: 9px;
  color: #888;
  margin-top: 2px;
}

/* ── Badge confirmé ───────────────────────────────── */
.confirmed-row {
  text-align: center;
  padding: 12px 0 10px;
  border-bottom: 1px solid #eee;
}
.check-circle {
  display: inline-block;
  width: 20px; height: 20px;
  border-radius: 10px;
  background: #0A6847;
  color: #fff;
  font-size: 13px;
  font-weight: 900;
  line-height: 20px;
  text-align: center;
  vertical-align: middle;
  margin-right: 6px;
}
.confirmed-text {
  font-size: 16px;
  font-weight: 900;
  color: #111;
  vertical-align: middle;
}

/* ── Bloc montant ─────────────────────────────────── */
.amount-box {
  margin: 10px 14px;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 6px;
  padding: 14px 12px 10px;
  text-align: center;
}
.amount-lbl {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #888;
  margin-bottom: 6px;
}
.amount-num {
  font-size: 25px;
  font-weight: 900;
  color: #111;
  line-height: 1;
}
.amount-cur {
  font-size: 14px;
  font-weight: 700;
  color: #555;
}
.amount-note {
  font-size: 8px;
  color: #bbb;
  margin-top: 6px;
  font-style: italic;
}
@if($montant_brut !== $montant_net)
.amount-brut {
  font-size: 9px;
  color: #E8A830;
  margin-top: 3px;
}
@endif

/* ── Sections ─────────────────────────────────────── */
.section {
  padding: 10px 16px 0;
}
.section-title {
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #0A6847;
  padding-bottom: 5px;
  border-bottom: 1px solid #eee;
  margin-bottom: 2px;
}

table.rows {
  width: 100%;
  border-collapse: collapse;
}
table.rows td {
  padding: 5px 0;
  font-size: 10px;
  border-bottom: 1px solid #f5f5f5;
  vertical-align: top;
}
table.rows tr:last-child td { border-bottom: none; }
table.rows td.k {
  color: #888;
  width: 38%;
  padding-right: 8px;
  font-size: 9px;
}
table.rows td.v {
  font-weight: 700;
  color: #111;
  text-align: right;
  word-wrap: break-word;
  word-break: break-all;
}

/* ── QR Code ──────────────────────────────────────── */
.qr-box {
  margin: 10px 14px 0;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 6px;
  padding: 10px 12px;
}
.qr-inner { width: 100%; border-collapse: collapse; }
.qr-img-cell { width: 60px; vertical-align: middle; padding-right: 10px; }
.qr-img { width: 56px; height: 56px; }
.qr-text-cell { vertical-align: middle; }
.qr-title {
  font-size: 10px;
  font-weight: 900;
  color: #111;
  margin-bottom: 3px;
}
.qr-desc {
  font-size: 8px;
  color: #888;
  line-height: 1.5;
}
.qr-link {
  font-size: 8px;
  color: #0A6847;
  margin-top: 3px;
  word-break: break-all;
}

/* ── Footer ───────────────────────────────────────── */
.footer {
  text-align: center;
  padding: 10px 14px 12px;
  margin-top: 10px;
  border-top: 1px solid #eee;
}
.footer-main { font-size: 8px; color: #888; line-height: 1.6; }
.footer-info { font-size: 8px; color: #bbb; margin-top: 3px; }
</style>
</head>
<body>

<div class="wm">TONJI</div>

<div class="page">

  {{-- Header --}}
  <div class="hdr">
    <table class="hdr-inner">
      <tr>
        <td class="logo-cell">
          <span class="logo-circle">T</span><span class="brand-name">Tonji</span>
        </td>
        <td class="hdr-right">
          <div class="recu-label">Reçu de paiement</div>
          <div class="recu-sub">Paynala SAS</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- Paiement confirmé --}}
  <div class="confirmed-row">
    <span class="check-circle">&#10003;</span>
    <span class="confirmed-text">Paiement confirmé</span>
  </div>

  {{-- Montant --}}
  <div class="amount-box">
    <div class="amount-lbl">Montant cotisé</div>
    <span class="amount-num">{{ number_format($montant_net, 0, ',', ' ') }}</span>
    <span class="amount-cur"> FCFA</span>
    @if($montant_brut !== $montant_net)
    <div style="font-size:9px;color:#E8A830;margin-top:3px">Débité : {{ number_format($montant_brut, 0, ',', ' ') }} FCFA</div>
    @endif
    <div class="amount-note">* Frais à la charge du cotisant</div>
  </div>

  {{-- Transaction --}}
  <div class="section">
    <div class="section-title">Transaction</div>
    <table class="rows">
      <tr>
        <td class="k">Référence</td>
        <td class="v" style="word-break:break-all;font-size:9px">{{ $trans_id }}</td>
      </tr>
      <tr>
        <td class="k">Date &amp; heure</td>
        <td class="v">{{ $date_heure }}</td>
      </tr>
      <tr>
        <td class="k">Canal</td>
        <td class="v">{{ $canal }}</td>
      </tr>
    </table>
  </div>

  {{-- Cagnotte --}}
  <div class="section" style="margin-top:8px">
    <div class="section-title">Cagnotte</div>
    <table class="rows">
      <tr>
        <td class="k">Nom</td>
        <td class="v">{{ $cagnotte_titre }}</td>
      </tr>
      <tr>
        <td class="k">Référence</td>
        <td class="v">#{{ $cagnotte_reference }}</td>
      </tr>
      <tr>
        <td class="k">Type</td>
        <td class="v">{{ $type_cagnotte }}</td>
      </tr>
    </table>
  </div>

  {{-- Cotisant --}}
  <div class="section" style="margin-top:8px">
    <div class="section-title">Cotisant</div>
    <table class="rows">
      <tr>
        <td class="k">Nom complet</td>
        <td class="v">{{ $nom_cotisant }}</td>
      </tr>
      <tr>
        <td class="k">Mobile Money</td>
        <td class="v">{{ $numero_masque }}</td>
      </tr>
    </table>
  </div>

  {{-- QR Code --}}
  <div class="qr-box">
    <table class="qr-inner">
      <tr>
        <td class="qr-img-cell">
          <img src="{{ $qr_data_uri }}" class="qr-img" alt="QR" />
        </td>
        <td class="qr-text-cell">
          <div class="qr-title">Vérifier ce reçu</div>
          <div class="qr-desc">Scannez le QR code pour confirmer l'authenticité du paiement en ligne.</div>
          <div class="qr-link">{{ $qr_url }}</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- Footer --}}
  <div class="footer">
    <div class="footer-main">Reçu généré automatiquement et valant preuve de paiement.</div>
    <div class="footer-info">support@tonji.ga &nbsp;·&nbsp; www.tonji.ga &nbsp;·&nbsp; Libreville, Gabon</div>
  </div>

</div>{{-- .page --}}
</body>
</html>
