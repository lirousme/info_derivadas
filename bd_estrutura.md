Estrutura do Banco de Dados: Info Derivadas

Banco de Dados: info_derivadas
Charset / Collation: utf8mb4 / utf8mb4_unicode_ci

Esta é a documentação de referência para a estrutura de dados do aplicativo "Info Derivadas", refletindo o sistema central de chat em árvore, criptografia, ganho de XP e organização em diretórios hierárquicos.

Tabela de Usuários (users)

Armazena as credenciais e informações básicas dos usuários. Possui um campo essencial para a segurança dos dados, garantindo que o sistema seja anti-vazamento de informações sensíveis.

id (INT) [PK] - Identificador único do usuário (Auto Incremento).
username (VARCHAR 50) [UNIQUE] - Nome de usuário único escolhido no cadastro.
email (VARCHAR 100) [UNIQUE] - E-mail para login e contato.
password_hash (VARCHAR 255) - Hash seguro da senha do usuário.
encryption_key (VARCHAR 255) - Chave gerada no cadastro para criptografar as mensagens deste usuário ponta a ponta.
created_at (TIMESTAMP) - Data e hora de criação da conta.
session_token (VARCHAR 255) - Token de sessão ativa.

Tabela Central: Nós do Chat (chat_nodes)

Esta é a tabela principal do aplicativo. Ela armazena a "Árvore de Derivação". Cada mensagem é um nó que pode ou não ter originado de outra mensagem.

id (INT) [PK] - Identificador único da mensagem (nó).
parent_id (INT) [FK] - Qual mensagem originou esta? (Nulo = mensagem inicial/ponto de partida).
user_id (INT) [FK] - Dono/Criador da mensagem.
speaker (TINYINT 1) - Define quem fala: 1 = Personagem 1 (Pergunta/Derivação), 2 = Personagem 2 (Resposta).
content_encrypted (TEXT) - Texto da mensagem, salvo de forma criptografada no banco.
image_url (VARCHAR 255) - Caminho para arquivo de imagem anexado à mensagem (se houver).
audio_url (VARCHAR 255) - Caminho para o arquivo de áudio (TTS via fish_audio).
is_public (BOOLEAN) - Controle de privacidade (Público/Privado).
created_at (TIMESTAMP) - Data e hora em que a mensagem foi criada.

Sistema de Diretórios e Agrupamentos (NOVO)

Organiza os chats em uma estrutura de árvore de diretórios (pastas e grupos de estudo), permitindo a acumulação e o cálculo recursivo de XP (Pontuação).

3.1 Tabela de Grupos/Diretórios (groups)
Armazena a estrutura hierárquica de pastas e grupos criados pelo usuário.

id (INT) [PK] - Identificador único do grupo (Auto Incremento).
user_id (INT) [FK] - Dono/Criador do grupo.
parent_id (INT) [FK] - Identificador do grupo pai (Nulo = Grupo/Pasta na Raiz).
name (VARCHAR 100) - Nome do diretório/grupo (ex: "Matemática", "Receitas").
type (ENUM) - Tipo do diretório: 'folder' (pode conter outras pastas) ou 'chat' (contêiner final para os nós/chats).

created_at (TIMESTAMP) - Data e hora de criação.

3.2 Relação Grupo e Nós (group_nodes)
Tabela associativa que vincula os chats consolidados (nós finais) aos seus respectivos grupos. Um mesmo chat pode pertencer a mais de um grupo se necessário.

group_id (INT) [PK/FK] - O identificador do grupo.
node_id (INT) [PK/FK] - O identificador do nó/chat.

Sistema de Revisão e Experiência (study_progress)

Gerencia o progresso linear de estudo do usuário com base nas mensagens (chats). Agora focado no acúmulo de XP a cada leitura concluída.

id (INT) [PK] - Identificador único do registro de progresso.
user_id (INT) [FK] - O usuário que está estudando.
node_id (INT) [FK] - O nó (mensagem/tópico) que está sendo estudado.
repetitions (INT) - Quantas vezes o usuário já revisou este item (Padrão: 0).
interval_minutes (INT) - (Legado/Ajustado) Mantido para compatibilidade, agora atua com tempo fixo de 24h (1440 min).
ease_factor (FLOAT) - (Legado) Fator de facilidade, sem uso crítico no sistema linear de XP atual.
next_review_date (DATETIME) - Data e hora exatas de quando este item deve ser revisado novamente.
score (INT) - XP (Pontos de Experiência) total acumulado neste chat específico (ex: +10 por revisão).

Sistema Social

Permite interações entre os usuários da plataforma, como seguir perfis ou se inscrever em árvores de conhecimento específicas.

5.1 Inscrições em Tópicos (subscriptions)
Quando um usuário se interessa pelo conhecimento gerado a partir de um ponto inicial de outro usuário.

id (INT) [PK] - Identificador único da inscrição.
user_id (INT) [FK] - O usuário que está se inscrevendo.
node_id (INT) [FK] - O ponto de partida específico do chat em que o usuário se inscreveu.
created_at (TIMESTAMP) - Data da inscrição.

5.2 Seguidores (followers)
Relação de usuários seguindo outros usuários para acompanhar suas derivações e chats públicos.

follower_id (INT) [PK/FK] - O ID do usuário que está seguindo (Fã).
followed_id (INT) [PK/FK] - O ID do usuário que está sendo seguido (Criador).
created_at (TIMESTAMP) - Data em que o vínculo foi criado.
