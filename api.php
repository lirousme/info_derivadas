<?php
// api.php - Backend para gerenciar a ramificação e criptografia
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once 'db.php'; // Usa o db.php que você enviou

// --- FUNÇÕES DE CRIPTOGRAFIA ---
// Para segurança extrema, a chave deve vir do frontend (E2EE), mas para este MVP
// usaremos criptografia simétrica no servidor usando uma chave do usuário.
define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptMessage($message, $userKey) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($message, ENCRYPTION_METHOD, $userKey, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptMessage($payload, $userKey) {
    list($encrypted_data, $iv) = explode('::', base64_decode($payload), 2);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $userKey, 0, $iv);
}

// Simulando usuário logado (Para testar, substitua pelo seu sistema de sessão)
$current_user_id = 1; 
$user_encryption_key = 'chave_secreta_do_usuario_123'; // Buscaria do BD no login

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // ADICIONAR NOVA MENSAGEM (PERGUNTA OU RESPOSTA)
    if ($action === 'add_node') {
        $parent_id = $data['parent_id'] ?? null;
        $speaker = $data['speaker']; // 1 ou 2
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // PEGAR O CAMINHO DE UMA CONVERSA (Do nó inicial até o nó atual)
    if ($action === 'get_chat_path') {
        $node_id = $_GET['node_id'] ?? null;
        $path = [];

        if ($node_id) {
            // Busca recursiva para trás (ancestrais)
            $current_id = $node_id;
            while ($current_id) {
                $stmt = $pdo->prepare("SELECT id, parent_id, speaker, content_encrypted, audio_url FROM chat_nodes WHERE id = ?");
                $stmt->execute([$current_id]);
                $node = $stmt->fetch();
                
                if ($node) {
                    $node['content'] = decryptMessage($node['content_encrypted'], $user_encryption_key);
                    unset($node['content_encrypted']); // Não envia o hash pro frontend
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

    // PEGAR AS DERIVAÇÕES (Filhos de uma resposta)
    if ($action === 'get_branches') {
        $parent_id = $_GET['parent_id'];
        
        $stmt = $pdo->prepare("SELECT id, speaker, content_encrypted FROM chat_nodes WHERE parent_id = ?");
        $stmt->execute([$parent_id]);
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
