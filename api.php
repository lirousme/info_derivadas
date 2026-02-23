<?php
// api.php - Backend atualizado com verificação de segurança e token
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once 'db.php';

define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptMessage($message, $userKey) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($message, ENCRYPTION_METHOD, $userKey, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

function decryptMessage($payload, $userKey) {
    if (!$payload) return "";
    $parts = explode('::', base64_decode($payload), 2);
    if (count($parts) !== 2) return $payload; 
    list($encrypted_data, $iv_base64) = $parts;
    $iv = base64_decode($iv_base64);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $userKey, 0, $iv);
}

// --- VERIFICAÇÃO DE AUTENTICAÇÃO ---
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Acesso negado. Token não fornecido.']);
    exit;
}

// Busca o usuário baseado no token persistente
$stmt = $pdo->prepare("SELECT id, encryption_key FROM users WHERE session_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Sessão inválida ou expirada. Faça login novamente.']);
    exit;
}

// Dados do usuário logado
$current_user_id = $user['id']; 
$user_encryption_key = $user['encryption_key'];

$action = $_GET['action'] ?? '';

// --- MÉTODOS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // ADICIONAR NOVA MENSAGEM (NÓ)
    if ($action === 'add_node') {
        $parent_id = !empty($data['parent_id']) ? $data['parent_id'] : null;
        $speaker = (int)$data['speaker'];
        $content = $data['content'];
        $is_public = $data['is_public'] ?? 0;

        $encrypted_content = encryptMessage($content, $user_encryption_key);

        $stmt = $pdo->prepare("INSERT INTO chat_nodes (parent_id, user_id, speaker, content_encrypted, is_public) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$parent_id, $current_user_id, $speaker, $encrypted_content, $is_public]);
        $new_id = $pdo->lastInsertId();
        
        echo json_encode(['status' => 'success', 'id' => $new_id]);
        exit;
    }
}

// --- MÉTODOS GET ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // PEGAR O CAMINHO DE UMA CONVERSA
    if ($action === 'get_chat_path') {
        $node_id = $_GET['node_id'] ?? null;
        $path = [];

        if ($node_id && $node_id !== 'null') {
            $current_id = $node_id;
            while ($current_id) {
                // Aqui podemos adicionar uma checagem de privacidade no futuro (is_public ou dono do chat)
                $stmt = $pdo->prepare("SELECT id, parent_id, speaker, content_encrypted, audio_url, image_url, user_id FROM chat_nodes WHERE id = ?");
                $stmt->execute([$current_id]);
                $node = $stmt->fetch();
                
                if ($node) {
                    // Só descriptografa se for do próprio usuário (por enquanto)
                    if ($node['user_id'] == $current_user_id) {
                        $node['content'] = decryptMessage($node['content_encrypted'], $user_encryption_key);
                    } else {
                        $node['content'] = "[Conteúdo Protegido/Em desenvolvimento para exibição pública]";
                    }
                    
                    unset($node['content_encrypted']);
                    array_unshift($path, $node);
                    $current_id = $node['parent_id'];
                } else {
                    break;
                }
            }
        }
        echo json_encode(['status' => 'success', 'path' => $path]);
        exit;
    }

    // PEGAR AS DERIVAÇÕES (Filhos de um nó específico)
    if ($action === 'get_branches') {
        $parent_id = $_GET['parent_id'] ?? null;
        
        if ($parent_id === null || $parent_id === 'null' || $parent_id === '') {
            $stmt = $pdo->prepare("SELECT id, speaker, content_encrypted FROM chat_nodes WHERE parent_id IS NULL AND user_id = ? ORDER BY id DESC");
            $stmt->execute([$current_user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, speaker, content_encrypted FROM chat_nodes WHERE parent_id = ? AND user_id = ?");
            $stmt->execute([$parent_id, $current_user_id]);
        }
        
        $branches = $stmt->fetchAll();

        foreach ($branches as &$branch) {
            $branch['content'] = decryptMessage($branch['content_encrypted'], $user_encryption_key);
            unset($branch['content_encrypted']);
        }

        echo json_encode(['status' => 'success', 'branches' => $branches]);
        exit;
    }
}

echo json_encode(['error' => 'Ação inválida']);
