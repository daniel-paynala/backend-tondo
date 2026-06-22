<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reçu {{ $trans_id }} — Tonji</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #f0f0f0; font-family: -apple-system, 'Helvetica Neue', Arial, sans-serif; }

.outer { max-width: 420px; margin: 24px auto; padding: 0 12px 40px; }

/* Bouton télécharger */
.dl-btn {
  display: block;
  width: 100%;
  background: #0A6847;
  color: #fff;
  text-align: center;
  padding: 14px;
  border-radius: 10px;
  font-size: 15px;
  font-weight: 700;
  text-decoration: none;
  margin-bottom: 16px;
  letter-spacing: 0.3px;
}
.dl-btn:hover { background: #085c3e; }

/* Card reçu */
.card {
  background: #fff;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 2px 16px rgba(0,0,0,0.10);
}

/* Header fond vert — logo officiel Tonji */
.hdr {
  background: #0A6847;
  padding: 10px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
/* Logo wordmark carré 1024×1024 — fond vert intégré, seamless */
.logo-img { height: 64px; width: 64px; display: block; }
.hdr-right { text-align: right; }
.recu-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #fff; }
.recu-sub   { font-size: 10px; color: rgba(255,255,255,0.65); margin-top: 2px; }

/* Badge confirmé */
.confirmed {
  text-align: center;
  padding: 14px 0 12px;
  border-bottom: 1px solid #eee;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.check-circle {
  width: 24px; height: 24px; border-radius: 12px;
  background: #0A6847; color: #fff;
  font-size: 15px; font-weight: 900;
  display: flex; align-items: center; justify-content: center;
}
.confirmed-text { font-size: 18px; font-weight: 900; color: #111; }

/* Bloc montant */
.amount-box {
  margin: 14px 16px;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 16px;
  text-align: center;
}
.amount-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: #888; margin-bottom: 6px; }
.amount-num { font-size: 36px; font-weight: 900; color: #111; }
.amount-cur { font-size: 18px; font-weight: 700; color: #555; }
.amount-brut { font-size: 11px; color: #E8A830; margin-top: 4px; }
.amount-note { font-size: 10px; color: #bbb; margin-top: 6px; font-style: italic; }

/* Sections */
.section { padding: 10px 16px 0; }
.section + .section { margin-top: 8px; }
.section-title {
  font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 1.5px;
  color: #0A6847;
  padding-bottom: 6px;
  border-bottom: 1px solid #eee;
  margin-bottom: 2px;
}
.row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f5f5f5; }
.row:last-child { border-bottom: none; }
.row-k { font-size: 11px; color: #888; }
.row-v { font-size: 11px; font-weight: 700; color: #111; text-align: right; word-break: break-all; max-width: 60%; }

/* QR */
.qr-box {
  margin: 12px 16px 0;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 12px;
  display: flex; gap: 12px; align-items: center;
}
.qr-img { width: 70px; height: 70px; flex-shrink: 0; }
.qr-text {}
.qr-title { font-size: 12px; font-weight: 900; color: #111; margin-bottom: 4px; }
.qr-desc  { font-size: 10px; color: #888; line-height: 1.5; }
.qr-link  { font-size: 10px; color: #0A6847; margin-top: 4px; word-break: break-all; }

/* Footer */
.footer {
  text-align: center;
  padding: 12px 16px 16px;
  margin-top: 10px;
  border-top: 1px solid #eee;
}
.footer-main { font-size: 10px; color: #888; line-height: 1.6; }
.footer-info { font-size: 10px; color: #bbb; margin-top: 3px; }
</style>
</head>
<body>

<div class="outer">

  <a href="/recu/{{ $trans_id }}/pdf" class="dl-btn">&#8595; Télécharger le PDF</a>

  <div class="card">

    {{-- Header fond vert avec logo officiel Tonji (base64 — pas de dépendance Nginx) --}}
    <div class="hdr">
      @if(!empty($logo_data_uri))
        <img src="{{ $logo_data_uri }}" class="logo-img" alt="Tonji" />
      @else
        <span style="color:#fff;font-size:22px;font-weight:900;letter-spacing:-0.5px">Tonji</span>
      @endif
      <div class="hdr-right">
        <div class="recu-label">Reçu de paiement</div>
        <div class="recu-sub">Paynala SAS</div>
      </div>
    </div>

    <div class="confirmed">
      <div class="check-circle">&#10003;</div>
      <div class="confirmed-text">Paiement confirmé</div>
    </div>

    <div class="amount-box">
      <div class="amount-lbl">Montant cotisé</div>
      <span class="amount-num">{{ number_format($montant_net, 0, ',', ' ') }}</span>
      <span class="amount-cur"> FCFA</span>
      @if($montant_brut !== $montant_net)
      <div class="amount-brut">Débité : {{ number_format($montant_brut, 0, ',', ' ') }} FCFA</div>
      @endif
      <div class="amount-note">* Frais à la charge du cotisant</div>
    </div>

    <div class="section">
      <div class="section-title">Transaction</div>
      <div class="row"><span class="row-k">Référence</span><span class="row-v" style="font-size:10px">{{ $trans_id }}</span></div>
      <div class="row"><span class="row-k">Date &amp; heure</span><span class="row-v">{{ $date_heure }}</span></div>
      <div class="row"><span class="row-k">Canal</span><span class="row-v">{{ $canal }}</span></div>
    </div>

    <div class="section">
      <div class="section-title">Cagnotte</div>
      <div class="row"><span class="row-k">Nom</span><span class="row-v">{{ $cagnotte_titre }}</span></div>
      <div class="row"><span class="row-k">Référence</span><span class="row-v">N°{{ $cagnotte_reference }}</span></div>
      <div class="row"><span class="row-k">Type</span><span class="row-v">{{ $type_cagnotte }}</span></div>
    </div>

    <div class="section">
      <div class="section-title">Cotisant</div>
      <div class="row"><span class="row-k">Nom complet</span><span class="row-v">{{ $nom_cotisant }}</span></div>
      <div class="row"><span class="row-k">Mobile Money</span><span class="row-v">{{ $numero_masque }}</span></div>
    </div>

    <div class="qr-box">
      <img src="{{ $qr_data_uri }}" class="qr-img" alt="QR" />
      <div class="qr-text">
        <div class="qr-title">Vérifier ce reçu</div>
        <div class="qr-desc">Scannez le QR code pour confirmer l'authenticité du paiement en ligne.</div>
        <div class="qr-link">{{ $qr_url }}</div>
      </div>
    </div>

    <div class="footer">
      <div class="footer-main">Reçu généré automatiquement et valant preuve de paiement.</div>
      <div class="footer-info">support@tonji.ga &nbsp;·&nbsp; www.tonji.ga &nbsp;·&nbsp; Libreville, Gabon</div>
    </div>

  </div>
</div>

</body>
</html>
