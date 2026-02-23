<?php
// api.php - Backend com Sistema de Estudo (SM-2) Integrado
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

$stmt = $pdo->prepare("SELECT id, encryption_key FROM users WHERE session_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Sessão inválida ou expirada. Faça login novamente.']);
    exit;
}

$current_user_id = $user['id']; 
$user_encryption_key = $user['encryption_key'];

$action = $_GET['action'] ?? '';

// --- MÉTODOS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // ADICIONAR NOVA MENSAGEM
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

    // ADICIONAR UM CHAT (NÓ) AOS ESTUDOS
    if ($action === 'add_to_study') {
        $node_id = $data['node_id'];
        
        // Verifica se já existe
        $stmt = $pdo->prepare("SELECT id FROM study_progress WHERE user_id = ? AND node_id = ?");
        $stmt->execute([$current_user_id, $node_id]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Chat já está nos seus estudos.']);
            exit;
        }

        // Insere com valores padrão. interval_minutes = 1. Revisão imediata (NOW())
        $stmt = $pdo->prepare("INSERT INTO study_progress (user_id, node_id, repetitions, interval_minutes, ease_factor, next_review_date, score) VALUES (?, ?, 0, 1, 2.5, NOW(), 0)");
        $stmt->execute([$current_user_id, $node_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SUBMETER REVISÃO DE ESTUDO (ALGORITMO SM-2 EM MINUTOS)
    if ($action === 'submit_review') {
        $node_id = $data['node_id'];
        $quality = (int)$data['quality']; // 0 a 5 (Usamos 3=Difícil, 4=Bom, 5=Fácil)

        $stmt = $pdo->prepare("SELECT * FROM study_progress WHERE user_id = ? AND node_id = ?");
        $stmt->execute([$current_user_id, $node_id]);
        $progress = $stmt->fetch();

        if (!$progress) {
            echo json_encode(['status' => 'error', 'message' => 'Progresso não encontrado.']);
            exit;
        }

        $repetitions = (int)$progress['repetitions'];
        // Puxamos os minutos salvos na base
        $interval = (int)$progress['interval_minutes']; 
        $ease = (float)$progress['ease_factor'];

        if ($quality < 3) {
            // Se errou/esqueceu totalmente, volta pro zero (1 minuto)
            $repetitions = 0;
            $interval = 1; 
        } else {
            // Acertou/Lembrou
            if ($repetitions === 0) {
                $interval = 1; // 1 minuto
            } elseif ($repetitions === 1) {
                $interval = 5; // 5 minutos (Ajustado para o fluxo rápido)
            } else {
                // Multiplica os minutos pelo fator de facilidade (ex: 5 * 2.5 = 13 minutos)
                $interval = round($interval * $ease);
            }
            $repetitions++;
        }

        // Atualiza fator de facilidade
        $ease = $ease + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
        if ($ease < 1.3) $ease = 1.3;

        // --- A MÁGICA DOS MINUTOS ACONTECE AQUI ---
        // Adiciona $interval minutos à hora exata atual
        $next_review = date('Y-m-d H:i:s', strtotime("+$interval minutes"));

        // Atualiza o banco com a nova quantidade de minutos (interval_minutes)
        $stmt = $pdo->prepare("UPDATE study_progress SET repetitions = ?, interval_minutes = ?, ease_factor = ?, next_review_date = ?, score = score + ? WHERE id = ?");
        
        // Dá pontos apenas se a qualidade for boa
        $pontos = $quality >= 3 ? $quality : 0;
        $stmt->execute([$repetitions, $interval, $ease, $next_review, $pontos, $progress['id']]);

        echo json_encode(['status' => 'success', 'next_review' => $next_review]);
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
                $stmt = $pdo->prepare("SELECT id, parent_id, speaker, content_encrypted, audio_url, image_url, user_id FROM chat_nodes WHERE id = ?");
                $stmt->execute([$current_id]);
                $node = $stmt->fetch();
                
                if ($node) {
                    if ($node['user_id'] == $current_user_id) {
                        $node['content'] = decryptMessage($node['content_encrypted'], $user_encryption_key);
                    } else {
                        $node['content'] = "[Conteúdo Protegido]";
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

    // OBTER TODOS OS CHATS CONSOLIDADOS DO USUÁRIO (PERFIL)
    if ($action === 'get_my_chats') {
        $stmt = $pdo->prepare("
            SELECT sp.node_id, sp.repetitions, sp.next_review_date, cn.content_encrypted, cn.created_at 
            FROM study_progress sp 
            JOIN chat_nodes cn ON sp.node_id = cn.id 
            WHERE sp.user_id = ? 
            ORDER BY cn.created_at DESC
        ");
        $stmt->execute([$current_user_id]);
        $chats = $stmt->fetchAll();

        foreach ($chats as &$chat) {
            $chat['content'] = decryptMessage($chat['content_encrypted'], $user_encryption_key);
            unset($chat['content_encrypted']);
        }

        echo json_encode(['status' => 'success', 'chats' => $chats]);
        exit;
    }

    // PEGAR AS DERIVAÇÕES
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

    // OBTER FILA DE ESTUDOS PENDENTES
    if ($action === 'get_study_queue') {
        // Pega todos os nodes agendados para revisão até o momento atual
        $stmt = $pdo->prepare("SELECT node_id, score FROM study_progress WHERE user_id = ? AND next_review_date <= NOW() ORDER BY next_review_date ASC LIMIT 10");
        $stmt->execute([$current_user_id]);
        $queue = $stmt->fetchAll();
        
        echo json_encode(['status' => 'success', 'queue' => $queue]);
        exit;
    }
}

echo json_encode(['error' => 'Ação inválida']);
