<?php
// proxy_2adbca.php
declare(strict_types=1);

// ===== CORS (mesmo domínio normalmente nem precisa, mas não atrapalha) =====
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ===== Somente POST =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'method_not_allowed']); exit;
}

// ===== Lê JSON =====
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);

if (!is_array($in)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'invalid_json','raw'=>$raw], JSON_UNESCAPED_UNICODE); exit;
}

// ===== Validação mínima =====
$errs = [];
if (empty($in['productHash'])) $errs['productHash'] = 'required';
if (empty($in['customer']['name'])) $errs['customer.name'] = 'required';
if (empty($in['customer']['email'])) $errs['customer.email'] = 'required';
// Se seu gateway exige amount, deixe como required:
if (!isset($in['amount'])) $errs['amount'] = 'required (centavos)';
if (!empty($errs)) {
  http_response_code(422);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'validation_failed','details'=>$errs], JSON_UNESCAPED_UNICODE); exit;
}

// ===== Monta payload p/ gateway =====
// Ajuste os campos conforme a API do seu provedor (ex.: SkalePay)
$payload = [
  'product_hash' => $in['productHash'],
  'amount'       => (int)$in['amount'], // remova se seu provider não aceitar
  'customer'     => [
    'name'  => (string)$in['customer']['name'],
    'email' => (string)$in['customer']['email'],
  ],
  'metadata'     => [
    'utms'        => $in['utms'] ?? new stdClass(),
    'checkoutUrl' => $in['checkoutUrl'] ?? null,
  ],
  // Alguns gateways exigem isso explicitamente.
  'traceable'    => true,
];

// ===== Chamada ao gateway =====
$GATEWAY_URL = 'https://SEU-GATEWAY-AQUI/pix/create'; // TODO: preencha
$API_KEY     = 'SEU_TOKEN_API_AQUI';                   // TODO: preencha

$ch = curl_init($GATEWAY_URL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer '.$API_KEY,
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 25,
]);
$respBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');

// Erro de transporte
if ($respBody === false || $curlErr) {
  http_response_code(502);
  echo json_encode(['error'=>'gateway_unreachable','curl_error'=>$curlErr], JSON_UNESCAPED_UNICODE); exit;
}

// Tenta decodificar
$gw = json_decode($respBody, true);

// Se o gateway respondeu erro (4xx/5xx), propaga pra frente (ajuda no debug)
if ($httpCode < 200 || $httpCode >= 300) {
  http_response_code($httpCode ?: 500);
  echo $respBody ?: json_encode(['error'=>'gateway_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Normaliza a resposta para o front =====
// Adapte conforme a chave real do QR/copia-e-cola vinda do gateway
$qr = null;

// Exemplos comuns de chaves que podem vir:
if (isset($gw['pix']['emv']) && $gw['pix']['emv'])           $qr = $gw['pix']['emv'];
elseif (isset($gw['emv']) && $gw['emv'])                      $qr = $gw['emv'];
elseif (isset($gw['pix_qr_code']) && $gw['pix_qr_code'])      $qr = $gw['pix_qr_code'];
elseif (isset($gw['data']['qr']) && $gw['data']['qr'])        $qr = $gw['data']['qr'];

if (!$qr) {
  // Se não encontrou, devolve bruto p/ debug
  http_response_code(500);
  echo json_encode(['error'=>'missing_qr_in_gateway_response','gateway'=>$gw], JSON_UNESCAPED_UNICODE);
  exit;
}

// OK
http_response_code(200);
echo json_encode([
  'pix' => [
    'pix_qr_code' => $qr,
    // (opcional) repasse outros campos úteis:
    'amount'      => $payload['amount'] ?? null,
    'expires_in'  => 300
  ]
], JSON_UNESCAPED_UNICODE);
