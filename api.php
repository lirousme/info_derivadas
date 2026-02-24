<?php
// api.php - Backend com Sistema de Diretórios, Pontuação, Edição de Nós e Upload de Imagens
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once 'db.php';

define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptMessage($message, $userKey) {
    if ($message === '') return ''; // Permite mensagem vazia (caso tenha só imagem)
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

// Função auxiliar para upload de imagens
function handleImageUpload($fileField) {
    if (isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $_FILES[$fileField]['tmp_name']);
        finfo_close($fileInfo);

        if (in_array($mimeType, $allowedTypes)) {
            $uploadDir = 'uploads/images/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $ext = pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION);
            $filename = uniqid('img_') . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES[$fileField]['tmp_name'], $targetPath)) {
                return $targetPath;
            }
        }
    }
    return null;
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
    
    // Suporte para JSON ou Multipart Form-Data (Upload de Arquivos)
    $isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
    
    if ($isMultipart) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    }

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

    // EDITAR DIRETÓRIO / GRUPO
    if ($action === 'edit_group') {
        $group_id = $data['group_id'] ?? null;
        $name = trim($data['name'] ?? '');
        $type = in_array($data['type'] ?? '', ['folder', 'chat']) ? $data['type'] : 'folder';

        if (!$group_id || !$name) {
            echo json_encode(['status' => 'error', 'message' => 'ID e Nome são obrigatórios.']);
            exit;
        }

        // Atualiza apenas se o grupo pertencer ao usuário logado
        $stmt = $pdo->prepare("UPDATE `groups` SET name = ?, type = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $type, $group_id, $current_user_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // EXCLUIR DIRETÓRIO / GRUPO
    if ($action === 'delete_group') {
        $group_id = $data['group_id'] ?? null;

        if (!$group_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID do grupo não fornecido.']);
            exit;
        }

        function getSubGroups($pdo, $parentId, $userId) {
            $stmt = $pdo->prepare("SELECT id FROM `groups` WHERE parent_id = ? AND user_id = ?");
            $stmt->execute([$parentId, $userId]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $allIds = $children;
            foreach ($children as $childId) {
                $allIds = array_merge($allIds, getSubGroups($pdo, $childId, $userId));
            }
            return $allIds;
        }

        function pruneOrphanedChats($pdo, $nodeIds) {
            foreach($nodeIds as $nodeId) {
                if (!$nodeId) continue;

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_nodes WHERE node_id = ?");
                $stmt->execute([$nodeId]);
                if ($stmt->fetchColumn() > 0) continue; 

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_nodes WHERE parent_id = ?");
                $stmt->execute([$nodeId]);
                if ($stmt->fetchColumn() > 0) continue; 

                $stmt = $pdo->prepare("SELECT parent_id, audio_url, image_url FROM chat_nodes WHERE id = ?");
                $stmt->execute([$nodeId]);
                $nodeData = $stmt->fetch();

                if ($nodeData) {
                    $parentId = $nodeData['parent_id'];
                    // Remove arquivos físicos
                    if ($nodeData['audio_url'] && file_exists($nodeData['audio_url'])) unlink($nodeData['audio_url']);
                    if ($nodeData['image_url'] && file_exists($nodeData['image_url'])) unlink($nodeData['image_url']);

                    $pdo->prepare("DELETE FROM study_progress WHERE node_id = ?")->execute([$nodeId]);
                    $pdo->prepare("DELETE FROM chat_nodes WHERE id = ?")->execute([$nodeId]);

                    if ($parentId) { pruneOrphanedChats($pdo, [$parentId]); }
                }
            }
        }

        try {
            $pdo->beginTransaction();

            $idsToDelete = getSubGroups($pdo, $group_id, $current_user_id);
            $idsToDelete[] = $group_id; 
            
            $inQuery = implode(',', array_fill(0, count($idsToDelete), '?'));

            $stmtNodes = $pdo->prepare("SELECT DISTINCT node_id FROM group_nodes WHERE group_id IN ($inQuery)");
            $stmtNodes->execute($idsToDelete);
            $affectedNodes = $stmtNodes->fetchAll(PDO::FETCH_COLUMN);

            $stmtRel = $pdo->prepare("DELETE FROM group_nodes WHERE group_id IN ($inQuery)");
            $stmtRel->execute($idsToDelete);

            $stmtDel = $pdo->prepare("DELETE FROM `groups` WHERE id IN ($inQuery) AND user_id = ?");
            $params = array_merge($idsToDelete, [$current_user_id]);
            $stmtDel->execute($params);

            if (!empty($affectedNodes)) { pruneOrphanedChats($pdo, $affectedNodes); }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Grupo excluído e chats órfãos limpos com sucesso.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir grupo: ' . $e->getMessage()]);
        }
        exit;
    }

    // ADICIONAR NOVA MENSAGEM (ATUALIZADO COM IMAGEM)
    if ($action === 'add_node') {
        $parent_id = !empty($data['parent_id']) && $data['parent_id'] !== 'null' ? $data['parent_id'] : null;
        $speaker = (int)($data['speaker'] ?? 1);
        $content = trim($data['content'] ?? '');
        $is_public = $data['is_public'] ?? 0;
        
        $imageUrl = handleImageUpload('image');

        if (empty($content) && !$imageUrl) {
            echo json_encode(['status' => 'error', 'message' => 'A mensagem deve conter texto ou uma imagem.']);
            exit;
        }

        $encrypted_content = encryptMessage($content, $user_encryption_key);

        $stmt = $pdo->prepare("INSERT INTO chat_nodes (parent_id, user_id, speaker, content_encrypted, image_url, is_public) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$parent_id, $current_user_id, $speaker, $encrypted_content, $imageUrl, $is_public]);
        
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // EDITAR MENSAGEM (ATUALIZADO COM IMAGEM)
    if ($action === 'edit_node') {
        $node_id = $data['node_id'] ?? null;
        $content = trim($data['content'] ?? '');
        $remove_image = isset($data['remove_image']) && $data['remove_image'] === 'true';

        if (!$node_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID da mensagem inválido.']);
            exit;
        }

        // Verifica permissão e pega a imagem atual
        $stmt = $pdo->prepare("SELECT image_url FROM chat_nodes WHERE id = ? AND user_id = ?");
        $stmt->execute([$node_id, $current_user_id]);
        $node = $stmt->fetch();

        if (!$node) {
            echo json_encode(['status' => 'error', 'message' => 'Mensagem não encontrada ou sem permissão.']);
            exit;
        }

        $currentImageUrl = $node['image_url'];
        $newImageUrl = $currentImageUrl;

        // Processamento da Imagem
        if ($remove_image) {
            if ($currentImageUrl && file_exists($currentImageUrl)) unlink($currentImageUrl);
            $newImageUrl = null;
        } else {
            $uploadedImage = handleImageUpload('image');
            if ($uploadedImage) {
                if ($currentImageUrl && file_exists($currentImageUrl)) unlink($currentImageUrl);
                $newImageUrl = $uploadedImage;
            }
        }

        if (empty($content) && !$newImageUrl) {
            echo json_encode(['status' => 'error', 'message' => 'A mensagem não pode ficar vazia (sem texto e sem imagem).']);
            exit;
        }

        $encrypted_content = encryptMessage($content, $user_encryption_key);

        $stmtUpdate = $pdo->prepare("UPDATE chat_nodes SET content_encrypted = ?, image_url = ? WHERE id = ?");
        $stmtUpdate->execute([$encrypted_content, $newImageUrl, $node_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // EXCLUIR MENSAGEM EM CASCATA (ATUALIZADO)
    if ($action === 'delete_node') {
        $node_id = $data['node_id'] ?? null;

        if (!$node_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID da mensagem não fornecido.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT parent_id FROM chat_nodes WHERE id = ? AND user_id = ?");
        $stmt->execute([$node_id, $current_user_id]);
        $node = $stmt->fetch();

        if (!$node) {
            echo json_encode(['status' => 'error', 'message' => 'Mensagem não encontrada ou permissão negada.']);
            exit;
        }

        $parentId = $node['parent_id'];

        function deleteNodeTree($pdo, $startNodeId) {
            $stmt = $pdo->prepare("SELECT id FROM chat_nodes WHERE parent_id = ?");
            $stmt->execute([$startNodeId]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($children as $childId) {
                deleteNodeTree($pdo, $childId);
            }

            $pdo->prepare("DELETE FROM study_progress WHERE node_id = ?")->execute([$startNodeId]);
            $pdo->prepare("DELETE FROM group_nodes WHERE node_id = ?")->execute([$startNodeId]);
            
            // Exclui áudio e imagem
            $stmtMedia = $pdo->prepare("SELECT audio_url, image_url FROM chat_nodes WHERE id = ?");
            $stmtMedia->execute([$startNodeId]);
            $media = $stmtMedia->fetch();
            
            if ($media) {
                if ($media['audio_url'] && file_exists($media['audio_url'])) unlink($media['audio_url']);
                if ($media['image_url'] && file_exists($media['image_url'])) unlink($media['image_url']);
            }

            $pdo->prepare("DELETE FROM chat_nodes WHERE id = ?")->execute([$startNodeId]);
        }

        try {
            $pdo->beginTransaction();
            deleteNodeTree($pdo, $node_id);
            $pdo->commit();
            
            echo json_encode(['status' => 'success', 'parent_id' => $parentId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Erro ao excluir ramificação: ' . $e->getMessage()]);
        }
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

        $stmt = $pdo->prepare("SELECT id FROM study_progress WHERE user_id = ? AND node_id = ?");
        $stmt->execute([$current_user_id, $node_id]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO study_progress (user_id, node_id, repetitions, interval_minutes, ease_factor, next_review_date, score) VALUES (?, ?, 0, 15, 2.5, NOW(), 0)");
            $stmt->execute([$current_user_id, $node_id]);
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO group_nodes (group_id, node_id) VALUES (?, ?)");
        $stmt->execute([$group_id, $node_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // SUBMETER REVISÃO
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
        $score_increment = 10; 
        $interval_minutes = $repetitions * 15;
        $next_review = date('Y-m-d H:i:s', strtotime("+$interval_minutes minutes"));

        $stmt = $pdo->prepare("UPDATE study_progress SET repetitions = ?, interval_minutes = ?, next_review_date = ?, score = score + ? WHERE id = ?");
        $stmt->execute([$repetitions, $interval_minutes, $next_review, $score_increment, $progress['id']]);

        echo json_encode(['status' => 'success', 'next_review' => $next_review]);
        exit;
    }
}

// --- MÉTODOS GET ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    if ($action === 'get_directory') {
        $parent_id = !empty($_GET['parent_id']) && $_GET['parent_id'] !== 'null' ? (int)$_GET['parent_id'] : null;
        
        if ($parent_id === null) {
            $stmt = $pdo->prepare("SELECT id, name, type FROM `groups` WHERE user_id = ? AND parent_id IS NULL ORDER BY type ASC, name ASC");
            $stmt->execute([$current_user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name, type FROM `groups` WHERE user_id = ? AND parent_id = ? ORDER BY type ASC, name ASC");
            $stmt->execute([$current_user_id, $parent_id]);
        }
        $dirs = $stmt->fetchAll();

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
