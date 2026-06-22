<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #111;
  background: #fff;
}

/* ── Filigrane tuilé pleine page ─────────────────────
   Chaque ligne = "TONJI" répété, décalée en alternance.
   Petite police + très faible opacité = effet estompe.
   DomPDF ne supporte pas filter:blur — on simule avec
   une opacité très basse et une couleur légèrement délavée. */
.wm {
  position: fixed;
  left: -5px; right: -5px;
  font-size: 11px;
  font-weight: 700;
  color: #1a6b4a;   /* vert légèrement délavé pour effet flou visuel */
  opacity: 0.055;
  letter-spacing: 9px;
  z-index: 0;
  white-space: nowrap;
  overflow: hidden;
}

.page { position: relative; z-index: 1; }

/* ── Header fond vert ─────────────────────────────── */
.hdr {
  background: #0A6847;
  padding: 9px 14px;
}
.hdr-inner { width: 100%; border-collapse: collapse; }
.logo-cell  { vertical-align: middle; width: 68px; }
.logo-img   { height: 68px; width: 68px; display: block; }
.hdr-right  { text-align: right; vertical-align: middle; }
.recu-label {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #fff;
}
.recu-sub {
  font-size: 10px;
  color: rgba(255,255,255,0.65);
  margin-top: 3px;
}

/* ── Badge confirmé ───────────────────────────────── */
.confirmed-row {
  text-align: center;
  padding: 11px 0 9px;
  border-bottom: 1px solid #eee;
}
.check-circle {
  display: inline-block;
  width: 22px; height: 22px;
  border-radius: 11px;
  background: #0A6847;
  color: #fff;
  font-size: 14px;
  font-weight: 900;
  line-height: 22px;
  text-align: center;
  vertical-align: middle;
  margin-right: 5px;
}
.confirmed-text {
  font-size: 18px;
  font-weight: 900;
  color: #111;
  vertical-align: middle;
}

/* ── Bloc montant ─────────────────────────────────── */
.amount-box {
  margin: 9px 13px;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 6px;
  padding: 12px 10px 9px;
  text-align: center;
}
.amount-lbl {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #888;
  margin-bottom: 5px;
}
.amount-num {
  font-size: 36px;   /* identique à la version web */
  font-weight: 900;
  color: #111;
  line-height: 1;
}
.amount-cur {
  font-size: 18px;   /* identique à la version web */
  font-weight: 700;
  color: #555;
}
.amount-note {
  font-size: 9px;
  color: #bbb;
  margin-top: 5px;
  font-style: italic;
}

/* ── Sections ─────────────────────────────────────── */
.section { padding: 8px 14px 0; }
.section-title {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #0A6847;
  padding-bottom: 4px;
  border-bottom: 1px solid #eee;
  margin-bottom: 1px;
}

table.rows { width: 100%; border-collapse: collapse; }
table.rows td {
  padding: 4px 0;
  font-size: 11px;
  border-bottom: 1px solid #f5f5f5;
  vertical-align: top;
}
table.rows tr:last-child td { border-bottom: none; }
table.rows td.k {
  color: #888;
  width: 38%;
  padding-right: 6px;
  font-size: 10px;
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
  margin: 9px 13px 0;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 6px;
  padding: 9px 11px;
}
.qr-inner { width: 100%; border-collapse: collapse; }
.qr-img-cell { width: 80px; vertical-align: middle; padding-right: 10px; }
.qr-img { width: 70px; height: 70px; }   /* aligné sur la version web (70px) */
.qr-text-cell { vertical-align: middle; }
.qr-title { font-size: 11px; font-weight: 900; color: #111; margin-bottom: 3px; }
.qr-desc  { font-size: 9px; color: #888; line-height: 1.5; }
.qr-link  { font-size: 9px; color: #0A6847; margin-top: 3px; word-break: break-all; }

/* ── Footer ───────────────────────────────────────── */
.footer {
  text-align: center;
  padding: 8px 13px 11px;
  margin-top: 8px;
  border-top: 1px solid #eee;
}
.footer-main { font-size: 9px; color: #888; line-height: 1.6; }
.footer-info { font-size: 9px; color: #bbb; margin-top: 2px; }
</style>
</head>
<body>

{{-- Filigrane tuilé — 11 lignes espacées de ~9%, alternées en décalé --}}
<div class="wm" style="top:1%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:10%; padding-left:28px">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:19%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:28%; padding-left:28px">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:37%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:46%; padding-left:28px">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:55%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:64%; padding-left:28px">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:73%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:82%; padding-left:28px">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>
<div class="wm" style="top:91%">TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI &nbsp; TONJI</div>

<div class="page">

  {{-- Header fond vert avec logo officiel Tonji --}}
  <div class="hdr">
    <table class="hdr-inner">
      <tr>
        <td class="logo-cell">
          @if($logo_data_uri)
            <img src="{{ $logo_data_uri }}" class="logo-img" alt="Tonji" />
          @else
            <span style="color:#fff;font-size:20px;font-weight:900;">Tonji</span>
          @endif
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
    <div style="font-size:10px;color:#E8A830;margin-top:3px">Débité : {{ number_format($montant_brut, 0, ',', ' ') }} FCFA</div>
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
  <div class="section" style="margin-top:7px">
    <div class="section-title">Cagnotte</div>
    <table class="rows">
      <tr><td class="k">Nom</td><td class="v">{{ $cagnotte_titre }}</td></tr>
      <tr><td class="k">Référence</td><td class="v">N°{{ $cagnotte_reference }}</td></tr>
      <tr><td class="k">Type</td><td class="v">{{ $type_cagnotte }}</td></tr>
    </table>
  </div>

  {{-- Cotisant --}}
  <div class="section" style="margin-top:7px">
    <div class="section-title">Cotisant</div>
    <table class="rows">
      <tr><td class="k">Nom complet</td><td class="v">{{ $nom_cotisant }}</td></tr>
      <tr><td class="k">Mobile Money</td><td class="v">{{ $numero_masque }}</td></tr>
    </table>
  </div>

  {{-- QR Code — 70×70px comme la version web --}}
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
