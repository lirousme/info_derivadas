<?php
// api.php - Backend com Sistema de Diretórios e Pontuação Linear
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
    echo json_encode(['error' => 'Sessão inválida ou expirada.']);
    exit;
}

$current_user_id = $user['id']; 
$user_encryption_key = $user['encryption_key'];

$action = $_GET['action'] ?? '';

// --- MÉTODOS POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // CRIAR DIRETÓRIO / GRUPO
    if ($action === 'create_group') {
        $parent_id = !empty($data['parent_id']) ? $data['parent_id'] : null;
        $name = trim($data['name']);
        $type = in_array($data['type'], ['folder', 'chat']) ? $data['type'] : 'folder';

        if (!$name) {
            echo json_encode(['status' => 'error', 'message' => 'Nome do grupo é obrigatório.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO `groups` (user_id, parent_id, name, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $parent_id, $name, $type]);
        
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // ADICIONAR NOVA MENSAGEM
    if ($action === 'add_node') {
        $parent_id = !empty($data['parent_id']) ? $data['parent_id'] : null;
        $speaker = (int)$data['speaker'];
        $content = $data['content'];
        $is_public = $data['is_public'] ?? 0;

        $encrypted_content = encryptMessage($content, $user_encryption_key);

        $stmt = $pdo->prepare("INSERT INTO chat_nodes (parent_id, user_id, speaker, content_encrypted, is_public) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$parent_id, $current_user_id, $speaker, $encrypted_content, $is_public]);
        
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // CONSOLIDAR CHAT E ASSOCIAR AO GRUPO
    if ($action === 'add_to_study') {
        $node_id = $data['node_id'];
        $group_id = $data['group_id'];
        
        if (!$group_id) {
            echo json_encode(['status' => 'error', 'message' => 'Grupo não especificado.']);
            exit;
        }

        // Verifica se já está nos estudos
        $stmt = $pdo->prepare("SELECT id FROM study_progress WHERE user_id = ? AND node_id = ?");
        $stmt->execute([$current_user_id, $node_id]);
        if (!$stmt->fetch()) {
            // Se não existe, cria a raiz de progresso (pontuação zero)
            // interval_minutes inicia como 15 para padrão do banco, mas a primeira revisão real é imediata (NOW)
            $stmt = $pdo->prepare("INSERT INTO study_progress (user_id, node_id, repetitions, interval_minutes, ease_factor, next_review_date, score) VALUES (?, ?, 0, 15, 2.5, NOW(), 0)");
            $stmt->execute([$current_user_id, $node_id]);
        }

        // Associa o chat ao grupo selecionado (ignora se já estiver lá)
        $stmt = $pdo->prepare("INSERT IGNORE INTO group_nodes (group_id, node_id) VALUES (?, ?)");
        $stmt->execute([$group_id, $node_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SUBMETER REVISÃO (LÓGICA LINEAR E ESPAÇAMENTO DINÂMICO)
    if ($action === 'submit_review') {
        $node_id = $data['node_id'];
        
        $stmt = $pdo->prepare("SELECT id, repetitions, score FROM study_progress WHERE user_id = ? AND node_id = ?");
        $stmt->execute([$current_user_id, $node_id]);
        $progress = $stmt->fetch();

        if (!$progress) {
            echo json_encode(['status' => 'error', 'message' => 'Progresso não encontrado.']);
            exit;
        }

        $repetitions = (int)$progress['repetitions'] + 1;
        
        // PONTUAÇÃO FIXA: +10 pontos de XP por revisão completada
        $score_increment = 10; 
        
        // INTERVALO DINÂMICO: Quantidade de revisões (atual) vezes 15 minutos
        $interval_minutes = $repetitions * 15;
        $next_review = date('Y-m-d H:i:s', strtotime("+$interval_minutes minutes"));

        // Atualiza salvando também a nova quantidade de minutos no banco (interval_minutes)
        $stmt = $pdo->prepare("UPDATE study_progress SET repetitions = ?, interval_minutes = ?, next_review_date = ?, score = score + ? WHERE id = ?");
        $stmt->execute([$repetitions, $interval_minutes, $next_review, $score_increment, $progress['id']]);

        echo json_encode(['status' => 'success', 'next_review' => $next_review]);
        exit;
    }
}

// --- MÉTODOS GET ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // NAVEGAR DIRETÓRIOS E CALCULAR PONTUAÇÃO (SQL AVANÇADO)
    if ($action === 'get_directory') {
        $parent_id = !empty($_GET['parent_id']) && $_GET['parent_id'] !== 'null' ? (int)$_GET['parent_id'] : null;
        
        // Busca os grupos que estão dentro deste parent_id
        if ($parent_id === null) {
            $stmt = $pdo->prepare("SELECT id, name, type FROM `groups` WHERE user_id = ? AND parent_id IS NULL ORDER BY type ASC, name ASC");
            $stmt->execute([$current_user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name, type FROM `groups` WHERE user_id = ? AND parent_id = ? ORDER BY type ASC, name ASC");
            $stmt->execute([$current_user_id, $parent_id]);
        }
        $dirs = $stmt->fetchAll();

        // Cálculo RECURSIVO da pontuação para cada diretório encontrado
        foreach ($dirs as &$dir) {
            $dirId = $dir['id'];
            $queryScore = "
                WITH RECURSIVE GroupHierarchy AS (
                    SELECT id, parent_id FROM `groups` WHERE id = :root_id
                    UNION ALL
                    SELECT g.id, g.parent_id FROM `groups` g
                    INNER JOIN GroupHierarchy gh ON g.parent_id = gh.id
                )
                SELECT IFNULL(SUM(sp.score), 0) as total_score
                FROM GroupHierarchy gh
                INNER JOIN group_nodes gn ON gn.group_id = gh.id
                INNER JOIN study_progress sp ON sp.node_id = gn.node_id
                WHERE sp.user_id = :user_id;
            ";
            $stmtScore = $pdo->prepare($queryScore);
            $stmtScore->execute(['root_id' => $dirId, 'user_id' => $current_user_id]);
            $resScore = $stmtScore->fetch();
            $dir['score'] = (int)$resScore['total_score'];
        }

        echo json_encode(['status' => 'success', 'directories' => $dirs]);
        exit;
    }

    // PEGAR O CAMINHO DE UMA CONVERSA (Árvore/Grafo)
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

    // OBTER FILA DE ESTUDOS DE UM GRUPO ESPECÍFICO
    if ($action === 'get_study_queue') {
        $group_id = $_GET['group_id'] ?? null;
        if (!$group_id) {
            echo json_encode(['status' => 'success', 'queue' => []]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT sp.node_id, sp.score 
            FROM study_progress sp
            INNER JOIN group_nodes gn ON gn.node_id = sp.node_id
            WHERE sp.user_id = ? AND gn.group_id = ? AND sp.next_review_date <= NOW() 
            ORDER BY sp.next_review_date ASC LIMIT 15
        ");
        $stmt->execute([$current_user_id, $group_id]);
        $queue = $stmt->fetchAll();
        
        echo json_encode(['status' => 'success', 'queue' => $queue]);
        exit;
    }
}

echo json_encode(['error' => 'Ação inválida']);
