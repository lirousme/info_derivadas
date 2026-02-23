<?php
// api.php - Backend para gerenciar a ramificação e criptografia (Sem Perguntas Padrões)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once 'db.php';

define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptMessage($message, $userKey) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($message, ENCRYPTION_METHOD, $userKey, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv)); // Codificando o IV para evitar problemas de string
}

function decryptMessage($payload, $userKey) {
    if (!$payload) return "";
    $parts = explode('::', base64_decode($payload), 2);
    if (count($parts) !== 2) return $payload; 
    list($encrypted_data, $iv_base64) = $parts;
    $iv = base64_decode($iv_base64);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $userKey, 0, $iv);
}

// SIMULAÇÃO DE USUÁRIO (Será substituído pelo sistema de Login/Sessão)
$current_user_id = 1; 
$user_encryption_key = 'chave_secreta_do_usuario_123';

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
    
    // PEGAR O CAMINHO DE UMA CONVERSA (Do nó inicial até o nó atual)
    if ($action === 'get_chat_path') {
        $node_id = $_GET['node_id'] ?? null;
        $path = [];

        if ($node_id && $node_id !== 'null') {
            $current_id = $node_id;
            while ($current_id) {
                $stmt = $pdo->prepare("SELECT id, parent_id, speaker, content_encrypted, audio_url, image_url FROM chat_nodes WHERE id = ?");
                $stmt->execute([$current_id]);
                $node = $stmt->fetch();
                
                if ($node) {
                    $node['content'] = decryptMessage($node['content_encrypted'], $user_encryption_key);
                    unset($node['content_encrypted']);
                    array_unshift($path, $node); // Adiciona no início do array
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
            // Busca raízes do usuário
            $stmt = $pdo->prepare("SELECT id, speaker, content_encrypted FROM chat_nodes WHERE parent_id IS NULL AND user_id = ? ORDER BY id DESC");
            $stmt->execute([$current_user_id]);
        } else {
            // Busca filhos de um nó específico
            $stmt = $pdo->prepare("SELECT id, speaker, content_encrypted FROM chat_nodes WHERE parent_id = ?");
            $stmt->execute([$parent_id]);
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
