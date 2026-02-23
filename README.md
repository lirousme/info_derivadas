Estrutura em que o banco de dados foi criado:

-- Criação do banco de dados
CREATE DATABASE IF NOT EXISTS info_derivadas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE info_derivadas;

-- 1. Tabela de Usuários
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    encryption_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabela Central: Nós do Chat (A Árvore de Derivação)
CREATE TABLE IF NOT EXISTS chat_nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    user_id INT NOT NULL,
    speaker TINYINT(1) NOT NULL,  -- 1 = Personagem 1 (Pergunta), 2 = Personagem 2 (Resposta)
    content_encrypted TEXT NOT NULL,
    image_url VARCHAR(255) NULL,
    audio_url VARCHAR(255) NULL,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES chat_nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Sistema de Repetição Espaçada (Estudos)
CREATE TABLE IF NOT EXISTS study_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    node_id INT NOT NULL,
    repetitions INT DEFAULT 0,    -- Quantas vezes revisou
    interval_days INT DEFAULT 1,  -- Intervalo até a próxima revisão
    ease_factor FLOAT DEFAULT 2.5,-- Fator de facilidade (algoritmo SM-2)
    next_review_date DATETIME NOT NULL,
    score INT DEFAULT 0,          -- Pontuação atual
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES chat_nodes(id) ON DELETE CASCADE
);

-- 4. Sistema Social
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    node_id INT NOT NULL, -- Ponto de partida específico
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES chat_nodes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS followers (
    follower_id INT NOT NULL,
    followed_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, followed_id),
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
);
