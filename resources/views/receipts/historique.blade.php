<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 25px;
  color: #111;
  background: #fff;
}

/* ── En-tête ─────────────────────────────────────── */
.hdr {
  text-align: center;
  padding: 28px 20px 20px;
  border-bottom: 4px solid #111;
}
.brand {
  font-size: 72px;
  font-weight: 900;
  letter-spacing: -1px;
  color: #0A6847;
}
.hdr-sub {
  font-size: 36px;
  color: #555;
  margin-top: 6px;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* ── Infos cagnotte ───────────────────────────────── */
.meta {
  padding: 24px 20px 18px;
  border-bottom: 2px dashed #bbb;
}
.meta-titre {
  font-size: 60px;
  font-weight: 700;
  color: #111;
  margin-bottom: 10px;
}
.meta-line {
  font-size: 42px;
  color: #444;
  margin-top: 6px;
}
.meta-line strong {
  color: #111;
}

/* ── Bloc total ───────────────────────────────────── */
.total-bloc {
  text-align: center;
  padding: 24px 20px;
  border-bottom: 4px solid #111;
}
.total-lbl {
  font-size: 40px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #555;
  margin-bottom: 8px;
}
.total-val {
  font-size: 96px;
  font-weight: 900;
  color: #0A6847;
  line-height: 1;
}
.total-cur {
  font-size: 52px;
  font-weight: 700;
  color: #0A6847;
}
.total-count {
  font-size: 40px;
  color: #777;
  margin-top: 8px;
}

/* ── Titre section ────────────────────────────────── */
.section-title {
  font-size: 38px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #555;
  padding: 18px 20px 10px;
}

/* ── Tableau transactions ─────────────────────────── */
table.hist {
  width: 100%;
  border-collapse: collapse;
}
table.hist thead th {
  background: #111;
  color: #fff;
  font-size: 40px;
  font-weight: 700;
  padding: 14px 16px;
  text-align: left;
}
table.hist thead th:last-child {
  text-align: right;
}
table.hist tbody tr:nth-child(even) td { background: #f5f5f5; }
table.hist tbody tr:nth-child(odd)  td { background: #fff; }
table.hist tbody td {
  padding: 14px 16px;
  font-size: 46px;
  border-bottom: 1px solid #ddd;
  vertical-align: middle;
}
table.hist td.cotisant {
  font-weight: 600;
  color: #111;
}
table.hist td.date {
  font-size: 38px;
  color: #666;
}
table.hist td.montant {
  font-weight: 900;
  color: #0A6847;
  text-align: right;
  white-space: nowrap;
}

/* ── Pied de page ─────────────────────────────────── */
.footer {
  text-align: center;
  padding: 20px 20px 24px;
  border-top: 4px solid #111;
  margin-top: 18px;
}
.footer-brand { font-size: 48px; font-weight: 900; color: #0A6847; }
.footer-date  { font-size: 38px; color: #777; margin-top: 8px; }
</style>
</head>
<body>

<div class="hdr">
  <div class="brand">Tonji</div>
  <div class="hdr-sub">Historique des paiements</div>
</div>

<div class="meta">
  <div class="meta-titre">{{ $cagnotte->titre }}</div>
  <div class="meta-line"><strong>N° {{ $cagnotte->reference }}</strong></div>
  <div class="meta-line">{{ $cagnotte->type === 'tontine_periodique' ? 'Tontine périodique' : 'Cotisation ouverte' }}</div>
</div>

<div class="total-bloc">
  <div class="total-lbl">Total collecté</div>
  <div>
    <span class="total-val">{{ number_format($total, 0, ',', ' ') }}</span>
    <span class="total-cur"> FCFA</span>
  </div>
  <div class="total-count">{{ $paiements->count() }} transaction(s)</div>
</div>

@if($paiements->isNotEmpty())
<div class="section-title">Détail</div>
<table class="hist">
  <thead>
    <tr>
      <th>Cotisant</th>
      <th style="text-align:right">Montant</th>
    </tr>
  </thead>
  <tbody>
    @foreach($paiements as $p)
    <tr>
      <td>
        <div class="cotisant">{{ $p->cotisant }}</div>
        <div class="date">{{ \Carbon\Carbon::parse($p->updated_at)->format('d/m/Y') }}</div>
      </td>
      <td class="montant">{{ number_format((int)$p->montant, 0, ',', ' ') }}<br><span style="font-size:36px;font-weight:600">FCFA</span></td>
    </tr>
    @endforeach
  </tbody>
</table>
@else
<p style="text-align:center;font-size:46px;color:#aaa;padding:40px 20px">Aucune transaction.</p>
@endif

<div class="footer">
  <div class="footer-brand">Tonji · Paynala SAS</div>
  <div class="footer-date">Généré le {{ $date }}</div>
</div>

</body>
</html>
