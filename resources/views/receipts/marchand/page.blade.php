{{--
  Page publique de vérification d'un paiement marchand.

  Elle s'ouvre depuis le QR du reçu, souvent sur le téléphone d'un commerçant
  qui contrôle ce que son client lui montre. D'où le parti pris : la réponse à
  « ce paiement est-il réel ? » doit tenir dans le premier écran, sans faire
  défiler.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Paiement {{ $reference }} — Tonji</title>
  <style>
    :root{
      --vert:#0A6847; --or:#E8A830; --encre:#14202E;
      --ardoise:#4A5568; --gris:#8A94A0; --brume:#E8EDE9; --ivoire:#F6F7F4;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--ivoire);color:var(--encre);
         font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
    .page{max-width:460px;margin:0 auto;padding:20px 16px 40px}
    .marque{text-align:center;margin-bottom:14px}
    .marque img{height:26px}

    .carte{background:#fff;border:1px solid var(--brume);border-radius:18px;
           padding:20px;box-shadow:0 1px 3px rgba(20,32,46,.05)}

    .verdict{display:flex;align-items:center;gap:8px;color:var(--vert);
             font-weight:800;font-size:15px}
    .verdict span{font-size:20px}

    .montant{font-size:34px;font-weight:800;color:var(--vert);margin:14px 0 2px;
             letter-spacing:-.5px}
    .montant small{font-size:16px;font-weight:700}
    .quand{color:var(--ardoise);font-size:14px}

    .ref{margin-top:14px;padding:10px 12px;background:var(--ivoire);
         border-radius:10px;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .ref b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:1px}

    h2{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;
       color:var(--ardoise);margin:22px 0 8px}
    dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 14px}
    dt{color:var(--ardoise)}
    dd{margin:0;font-weight:700;text-align:right;overflow-wrap:anywhere}

    .verifie{margin-top:10px;padding:8px 10px;border-radius:9px;
             background:rgba(10,104,71,.07);color:var(--vert);
             font-size:13px;font-weight:600}

    .qr{margin-top:20px;padding:16px;border-top:1px solid var(--brume);text-align:center}
    .qr img{width:168px;height:168px;display:block;margin:0 auto}
    .qr p{margin:10px 0 0;color:var(--ardoise);font-size:13px}

    .telecharger{display:block;margin-top:18px;padding:14px;border-radius:14px;
                 background:var(--vert);color:#fff;text-align:center;
                 font-weight:700;text-decoration:none}
    .pied{margin-top:16px;color:var(--gris);font-size:12px;text-align:center;
          overflow-wrap:anywhere}
  </style>
</head>
<body>
<div class="page">

  <div class="marque">
    @if($logo_data_uri)<img src="{{ $logo_data_uri }}" alt="Tonji">@else<b>TONJI</b>@endif
  </div>

  <div class="carte">
    {{-- La réponse d'abord : si la page s'affiche, le paiement existe et il
         est confirmé. Le reste n'est que le détail. --}}
    <div class="verdict"><span>✓</span> Paiement confirmé</div>

    <div class="montant">{{ $montant_affiche }} <small>FCFA</small></div>
    <div class="quand">{{ $date_heure }}</div>

    <div class="ref">
      <span style="color:var(--ardoise)">Référence</span>
      <b>{{ $reference }}</b>
    </div>

    <h2>Payé à</h2>
    <dl>
      <dt>Commerce</dt><dd>{{ $marchand_nom }}</dd>
      @if($marchand_code)<dt>Code marchand</dt><dd>{{ $marchand_code }}</dd>@endif
      @if($marchand_ville)<dt>Ville</dt><dd>{{ $marchand_ville }}</dd>@endif
      <dt>Numéro</dt><dd>{{ $marchand_numero }}</dd>
    </dl>
    @if($marchand_titulaire)
      {{-- Vérifié à l'enregistrement de la fiche, pas à la volée : c'est ce
           qui donne au nom sa valeur de preuve. --}}
      <div class="verifie">✓ Compte au nom de {{ $marchand_titulaire }}</div>
    @endif

    <h2>Payé par</h2>
    <dl>
      <dt>Client</dt><dd>{{ $payeur }}</dd>
      @if($payeur_numero)<dt>Numéro</dt><dd>{{ $payeur_numero }}</dd>@endif
      <dt>Cagnotte</dt><dd>{{ $cagnotte_titre }}</dd>
      <dt>N° de cagnotte</dt><dd>{{ $cagnotte_reference }}</dd>
    </dl>

    {{-- Le QR est ici parce que c'est ICI qu'il sert : le client ouvre la page
         sur son téléphone et montre l'écran, le commerçant scanne et tombe sur
         cette même page depuis le sien. Le code circulaire n'en est pas un —
         c'est ce qui permet de vérifier sans se faire passer l'appareil. --}}
    <div class="qr">
      <img src="{{ $qr_data_uri }}" alt="Code de vérification du paiement {{ $reference }}">
      <p>Montrez ce code au commerçant pour qu'il vérifie</p>
    </div>

    <a class="telecharger" href="{{ $qr_url }}/pdf">Télécharger le reçu (PDF)</a>
  </div>

  <p class="pied">
    Référence complète : {{ $trans_id }}@if($operateur_id)<br>Identifiant opérateur : {{ $operateur_id }}@endif<br>
    Tonji — service édité par Paynala.
  </p>

</div>
</body>
</html>
