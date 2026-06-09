<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  font-family: DejaVu Sans, sans-serif;
  color: #1A1F1E;
  background: #F4ECE0;
}

/* ── Header pleine largeur ───────────────────────────── */
.hdr {
  background: #0F4C5C;
  padding: 24px 64px 20px;
}
.brand {
  font-size: 24px;
  font-weight: 700;
  color: #F4ECE0;
}
.brand-badge {
  display: inline-block;
  background: #C97B4A;
  color: #1A1F1E;
  font-size: 15px;
  font-weight: 700;
  width: 26px;
  height: 26px;
  line-height: 26px;
  text-align: center;
  border-radius: 5px;
  margin-right: 7px;
  vertical-align: middle;
}
.hdr-sub {
  font-size: 8px;
  color: rgba(244,236,224,0.5);
  letter-spacing: 1.2px;
  margin-top: 4px;
}
.hdr-status {
  display: inline-block;
  background: #6B8E4E;
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  padding: 3px 14px;
  border-radius: 20px;
  margin-top: 12px;
}

/* ── Zone de contenu ─────────────────────────────────── */
.wrap {
  padding: 32px 64px 40px;
}

/* ── Bloc montant ────────────────────────────────────── */
.amount {
  text-align: center;
  padding: 22px 0 20px;
  margin-bottom: 22px;
  border-bottom: 2px dashed rgba(201,123,74,0.4);
}
.amount-lbl {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: #0F4C5C;
  margin-bottom: 8px;
}
.amount-num {
  font-size: 56px;
  font-weight: 700;
  color: #0F4C5C;
  line-height: 1;
}
.amount-cur {
  font-size: 22px;
  font-weight: 600;
  color: #C97B4A;
}
.amount-note {
  font-size: 9px;
  color: #bbb;
  margin-top: 8px;
}

/* ── Cards ───────────────────────────────────────────── */
.card {
  background: #fff;
  border-radius: 8px;
  padding: 14px 20px;
  margin-bottom: 12px;
  border: 1px solid rgba(15,76,92,0.07);
}
.card-title {
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #0F4C5C;
  font-weight: 700;
  padding-bottom: 8px;
  margin-bottom: 4px;
  border-bottom: 1px solid #f0ede8;
}

/*
 * Pas de table-layout:fixed ni overflow:hidden — DomPDF coupe les textes
 * avec ces propriétés. word-break:break-all gère les IDs longs.
 */
table.rows {
  width: 100%;
  border-collapse: collapse;
}
table.rows td {
  padding: 6px 0;
  font-size: 11px;
  border-bottom: 1px solid #f8f6f3;
  vertical-align: top;
}
table.rows tr:last-child td { border-bottom: none; }
table.rows td.k {
  color: #888;
  width: 36%;
  padding-right: 16px;
  font-size: 10px;
}
table.rows td.v {
  font-weight: 600;
  color: #1A1F1E;
  word-wrap: break-word;
  word-break: break-all;
}
table.rows td.v.accent  { color: #C97B4A; }
table.rows td.v.primary { color: #0F4C5C; }

.pill {
  display: inline-block;
  background: #0F4C5C;
  color: #F4ECE0;
  font-size: 9px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 12px;
}

/* ── Footer ──────────────────────────────────────────── */
.footer {
  text-align: center;
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid rgba(15,76,92,0.12);
}
.footer-brand { font-size: 10px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 8px; color: #bbb; margin-top: 3px; line-height: 1.7; }
</style>
</head>
<body>

<div class="hdr">
  <div class="brand"><span class="brand-badge">T</span>Tondo</div>
  <div class="hdr-sub">REÇU DE PAIEMENT · PAYNALA SAS</div>
  <div class="hdr-status">&#10003; PAIEMENT CONFIRMÉ</div>
</div>

<div class="wrap">

  <div class="amount">
    <div class="amount-lbl">Montant cotisé</div>
    <span class="amount-num">{{ number_format($montant_net, 0, ',', ' ') }}</span>
    <span class="amount-cur"> FCFA</span>
    <div class="amount-note">* Frais à la charge du cotisant</div>
  </div>

  <div class="card">
    <div class="card-title">Transaction</div>
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
      @if($montant_brut !== $montant_net)
      <tr>
        <td class="k">Montant débité</td>
        <td class="v">{{ number_format($montant_brut, 0, ',', ' ') }} FCFA</td>
      </tr>
      @endif
    </table>
  </div>

  <div class="card">
    <div class="card-title">Cagnotte</div>
    <table class="rows">
      <tr>
        <td class="k">Nom</td>
        <td class="v primary">{{ $cagnotte_titre }}</td>
      </tr>
      <tr>
        <td class="k">Référence</td>
        <td class="v"><span class="pill"># {{ $cagnotte_reference }}</span></td>
      </tr>
      <tr>
        <td class="k">Type</td>
        <td class="v">{{ $type_cagnotte }}</td>
      </tr>
    </table>
  </div>

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

  <div class="footer">
    <div class="footer-brand">Tondo · Paynala SAS</div>
    <div class="footer-text">
      Reçu généré automatiquement et valant preuve de paiement.<br>
      support@tondo.ga &nbsp;·&nbsp; www.tondo.ga &nbsp;·&nbsp; Libreville, Gabon
    </div>
  </div>

</div>
</body>
</html>
