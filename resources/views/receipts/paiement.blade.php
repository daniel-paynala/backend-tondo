<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 12px;
  color: #1a1a1a;
  background: #fff;
}

/* ── Filigrane ────────────────────────────────────── */
.watermark {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 0;
  pointer-events: none;
}
.watermark-text {
  font-size: 60px;
  font-weight: 900;
  color: #0A6847;
  opacity: 0.06;
  letter-spacing: 14px;
  text-transform: uppercase;
  white-space: nowrap;
}

.page { position: relative; z-index: 1; }

/* ── En-tête ─────────────────────────────────────── */
.hdr {
  padding: 14px 14px 10px;
  border-bottom: 3px solid #111;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
}
.brand {
  font-size: 25px;
  font-weight: 900;
  color: #0A6847;
  letter-spacing: -0.5px;
  line-height: 1;
}
.brand-tagline {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: #888;
  margin-top: 3px;
}
.hdr-right { text-align: right; }
.hdr-badge {
  display: inline-block;
  background: #0A6847;
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 10px;
  letter-spacing: 0.5px;
}

/* ── Bloc montant ─────────────────────────────────── */
.amount-bloc {
  background: #0A6847;
  color: #fff;
  text-align: center;
  padding: 14px 14px 12px;
}
.amount-lbl {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  opacity: 0.75;
}
.amount-val {
  font-size: 25px;
  font-weight: 900;
  line-height: 1.1;
  margin-top: 4px;
}
.amount-note {
  font-size: 8px;
  opacity: 0.6;
  margin-top: 4px;
  font-style: italic;
}
.amount-brut {
  font-size: 10px;
  color: #E8A830;
  margin-top: 3px;
}

/* ── Cards ────────────────────────────────────────── */
.section-lbl {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: #aaa;
  padding: 9px 14px 3px;
}

.card {
  margin: 0 14px 8px;
  border: 1px solid #e8e8e8;
  border-radius: 4px;
  overflow: hidden;
}
.card-title {
  background: #f5f5f5;
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #555;
  padding: 5px 10px;
  border-bottom: 1px solid #e8e8e8;
}

table.rows {
  width: 100%;
  border-collapse: collapse;
}
table.rows td {
  padding: 6px 10px;
  font-size: 12px;
  border-bottom: 1px solid #f0f0f0;
  vertical-align: top;
}
table.rows tr:last-child td { border-bottom: none; }
table.rows td.k {
  color: #888;
  font-size: 10px;
  width: 40%;
  padding-right: 6px;
}
table.rows td.v {
  font-weight: 700;
  color: #111;
  word-wrap: break-word;
  word-break: break-all;
}
table.rows td.v.accent  { color: #E8A830; }
table.rows td.v.primary { color: #0A6847; }
table.rows td.v.ref {
  font-size: 13px;
  font-weight: 900;
  color: #0A6847;
}

/* ── Pied de page ─────────────────────────────────── */
.footer {
  text-align: center;
  padding: 10px 14px 12px;
  border-top: 2px solid #111;
  margin-top: 12px;
}
.footer-brand {
  font-size: 14px;
  font-weight: 900;
  color: #0A6847;
}
.footer-info {
  font-size: 8px;
  color: #bbb;
  margin-top: 4px;
  line-height: 1.7;
}
.footer-anti-falsif {
  font-size: 7px;
  color: #ccc;
  margin-top: 6px;
  font-style: italic;
}
</style>
</head>
<body>

{{-- Filigrane --}}
<div class="watermark">
  <div class="watermark-text">TONJI</div>
</div>

<div class="page">

  {{-- En-tête --}}
  <div class="hdr">
    <div>
      <div class="brand">Tonji</div>
      <div class="brand-tagline">Tontines &amp; cotisations · Paynala SAS</div>
    </div>
    <div class="hdr-right">
      <div class="hdr-badge">&#10003; PAIEMENT CONFIRMÉ</div>
    </div>
  </div>

  {{-- Montant --}}
  <div class="amount-bloc">
    <div class="amount-lbl">Montant cotisé</div>
    <div class="amount-val">{{ number_format($montant_net, 0, ',', ' ') }}&nbsp;FCFA</div>
    @if($montant_brut !== $montant_net)
    <div class="amount-brut">Débité : {{ number_format($montant_brut, 0, ',', ' ') }}&nbsp;FCFA</div>
    @endif
    <div class="amount-note">* Frais à la charge du cotisant</div>
  </div>

  {{-- Transaction --}}
  <div class="section-lbl">Transaction</div>
  <div class="card">
    <div class="card-title">Détails du paiement</div>
    <table class="rows">
      <tr>
        <td class="k">Référence</td>
        <td class="v accent">{{ $trans_id }}</td>
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
  <div class="card">
    <div class="card-title">Cagnotte</div>
    <table class="rows">
      <tr>
        <td class="k">Nom</td>
        <td class="v primary">{{ $cagnotte_titre }}</td>
      </tr>
      <tr>
        <td class="k">N°</td>
        <td class="v ref">{{ $cagnotte_reference }}</td>
      </tr>
      <tr>
        <td class="k">Type</td>
        <td class="v">{{ $type_cagnotte }}</td>
      </tr>
    </table>
  </div>

  {{-- Cotisant --}}
  <div class="card">
    <div class="card-title">Cotisant</div>
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

  {{-- Pied --}}
  <div class="footer">
    <div class="footer-brand">Tonji &middot; Paynala SAS</div>
    <div class="footer-info">
      Reçu valant preuve de paiement &nbsp;·&nbsp; support@tonji.ga &nbsp;·&nbsp; Libreville, Gabon
    </div>
    <div class="footer-anti-falsif">
      Document officiel Tonji — toute falsification est passible de poursuites.
    </div>
  </div>

</div>{{-- .page --}}

</body>
</html>
