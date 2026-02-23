<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json');

require_once 'db.php';
// Inclua a api.php ou duplique as funções de descriptografia aqui para poder ler o texto.
// Para este exemplo, vou simular as funções para manter o arquivo único, mas no projeto real, crie um "helpers.php".
define('ENCRYPTION_METHOD', 'AES-256-CBC');
$user_encryption_key = 'chave_secreta_do_usuario_123'; // Puxar da sessão no futuro

function decryptMessageTTS($payload, $userKey) {
    if (!$payload) return "";
    $parts = explode('::', base64_decode($payload), 2);
    if (count($parts) !== 2) return $payload;
    list($encrypted_data, $iv) = $parts;
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $userKey, 0, $iv);
}

$apiKey = '5381aa36f1834e57acc7e066ae1c51f0';
$apiUrl = 'https://api.fish.audio/v1/tts';

if (!isset($_REQUEST['id'])) {
    echo json_encode(["status" => "erro", "mensagem" => "Parâmetro 'id' não enviado"]);
    exit;
}

$id = intval($_REQUEST['id']);

// Busca o nó na tabela correta (chat_nodes) usando PDO
$stmt = $pdo->prepare("SELECT speaker, content_encrypted, audio_url FROM chat_nodes WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(["status" => "erro", "mensagem" => "Nó (mensagem) não encontrado"]);
    exit;
}

$texto = decryptMessageTTS($row['content_encrypted'], $user_encryption_key);
$audioAntigo = $row['audio_url'];
$speaker = (int)$row['speaker'];

// Limpeza básica do texto antes de enviar para a API (mantenha seu array de substituições aqui)
$texto = strip_tags($texto);
$texto = str_replace(['"', "'", "*", "/", ":"], ' ', $texto);

// SELEÇÃO DE VOZES FIXAS (Personagem 1 e Personagem 2)
if ($speaker === 1) {
    // Voz Personagem 1 (Ex: Pergunta)
    $reference_id = '67814b0453c741f1beb01bbbc01c17e3'; 
} else {
    // Voz Personagem 2 (Ex: Resposta)
    $reference_id = '0931435e95d5432e9384a4975e4b382e'; 
}

// Remove áudio antigo se existir
if (!empty($audioAntigo) && file_exists($audioAntigo)) {
    unlink($audioAntigo);
}

// Chamada à API
$body = [
    'text' => $texto,
    'reference_id' => $reference_id,
    'chunk_length' => 200,
    'normalize' => true,
    'format' => 'mp3',
    'mp3_bitrate' => 128,
    'latency' => 'normal',
    'model' => 's1'
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(["status" => "erro", "mensagem" => "Erro na API TTS", "detalhe" => $response]);
    exit;
}

// Salva o arquivo
$target_dir = "audios/";
if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
$filename = "node_" . $id . "_" . time() . ".mp3";
$fullPath = $target_dir . $filename;

file_put_contents($fullPath, $response);

// Atualiza o DB
$stmtUpdate = $pdo->prepare("UPDATE chat_nodes SET audio_url = ? WHERE id = ?");
$stmtUpdate->execute([$fullPath, $id]);

echo json_encode(["status" => "ok", "mensagem" => "Áudio gerado", "arquivo" => $fullPath]);
