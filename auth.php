<?php
// auth.php - Backend para Login, Cadastro e Validação de Sessão
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once 'db.php';

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CADASTRO DE USUÁRIO
    if ($action === 'register') {
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$username || !$email || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'Preencha todos os campos.']);
            exit;
        }

        // Verifica se usuário ou email já existem
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'Usuário ou E-mail já em uso.']);
            exit;
        }

        // Gera hash da senha, chave de criptografia única e token
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $encryption_key = bin2hex(random_bytes(32)); // Chave segura de 256 bits (64 chars hex)
        $session_token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, encryption_key, session_token) VALUES (?, ?, ?, ?, ?)");
        
        try {
            $stmt->execute([$username, $email, $password_hash, $encryption_key, $session_token]);
            echo json_encode([
                'status' => 'success', 
                'token' => $session_token,
                'user' => ['username' => $username]
            ]);
        } catch(PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Erro ao criar conta.']);
        }
        exit;
    }

    // LOGIN DE USUÁRIO
    if ($action === 'login') {
        $username = trim($data['username'] ?? ''); // Pode ser username ou email no frontend, aqui tratamos como um só
        $password = $data['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Gera novo token de sessão
            $session_token = bin2hex(random_bytes(32));
            
            $stmtUpdate = $pdo->prepare("UPDATE users SET session_token = ? WHERE id = ?");
            $stmtUpdate->execute([$session_token, $user['id']]);

            echo json_encode([
                'status' => 'success', 
                'token' => $session_token,
                'user' => ['username' => $user['username']]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Credenciais inválidas.']);
        }
        exit;
    }

    // LOGOUT
    if ($action === 'logout') {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($token) {
            $stmt = $pdo->prepare("UPDATE users SET session_token = NULL WHERE session_token = ?");
            $stmt->execute([$token]);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// VERIFICAÇÃO DE TOKEN (Usado ao abrir o app para ver se continua logado)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'verify') {
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    
    if (!$token) {
        echo json_encode(['status' => 'error', 'message' => 'Não autenticado']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT username FROM users WHERE session_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => 'success', 'user' => ['username' => $user['username']]]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Token inválido']);
    }
    exit;
}
