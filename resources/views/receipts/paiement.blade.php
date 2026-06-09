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
  padding: 12px 18px 10px;
}
.brand {
  font-size: 15px;
  font-weight: 700;
  color: #F4ECE0;
}
.brand-badge {
  display: inline-block;
  background: #C97B4A;
  color: #1A1F1E;
  font-size: 10px;
  font-weight: 700;
  width: 17px;
  height: 17px;
  line-height: 17px;
  text-align: center;
  border-radius: 3px;
  margin-right: 5px;
  vertical-align: middle;
}
.hdr-sub {
  font-size: 6px;
  color: rgba(244,236,224,0.5);
  letter-spacing: 0.8px;
  margin-top: 2px;
}
.hdr-status {
  display: inline-block;
  background: #6B8E4E;
  color: #fff;
  font-size: 7px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
  margin-top: 6px;
}

/* ── Zone de contenu ─────────────────────────────────── */
.wrap {
  padding: 10px 18px 16px;
}

/* ── Bloc montant ────────────────────────────────────── */
.amount {
  text-align: center;
  padding: 10px 0 9px;
  margin-bottom: 10px;
  border-bottom: 1px dashed rgba(201,123,74,0.4);
}
.amount-lbl {
  font-size: 6px;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: #0F4C5C;
  margin-bottom: 4px;
}
.amount-num {
  font-size: 32px;
  font-weight: 700;
  color: #0F4C5C;
  line-height: 1;
}
.amount-cur {
  font-size: 13px;
  font-weight: 600;
  color: #C97B4A;
}
.amount-note {
  font-size: 7px;
  color: #bbb;
  margin-top: 4px;
}

/* ── Cards ───────────────────────────────────────────── */
.card {
  background: #fff;
  border-radius: 5px;
  padding: 7px 10px;
  margin-bottom: 6px;
  border: 1px solid rgba(15,76,92,0.07);
}
.card-title {
  font-size: 6px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #0F4C5C;
  font-weight: 700;
  padding-bottom: 4px;
  margin-bottom: 2px;
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
  padding: 3px 0;
  font-size: 8px;
  border-bottom: 1px solid #f8f6f3;
  vertical-align: top;
}
table.rows tr:last-child td { border-bottom: none; }
table.rows td.k {
  color: #888;
  width: 38%;
  padding-right: 8px;
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
  font-size: 7px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 10px;
}

/* ── Footer ──────────────────────────────────────────── */
.footer {
  text-align: center;
  margin-top: 8px;
  padding-top: 6px;
  border-top: 1px solid rgba(15,76,92,0.12);
}
.footer-brand { font-size: 7px; font-weight: 700; color: #0F4C5C; }
.footer-text  { font-size: 6px; color: #bbb; margin-top: 2px; line-height: 1.6; }
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
