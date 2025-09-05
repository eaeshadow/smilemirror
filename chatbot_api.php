<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'voz/sintetizar_vozes.php';

// =================================================================
// CONFIGURAÇÃO
// =================================================================
$geminiApiKey = 'AIzaSyDRVzpN-ZrGhIdtzme7noQupdMM2y4Ji8w';

// Configurações do Banco de Dados
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = ''; // 🔹 Se der erro, teste também '' (senha vazia)
$dbName = 'chatbot_db';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Conexão
    $conn = new mysqli($dbHost, $dbUser, $dbPass);
    if ($conn->connect_error) {
        throw new Exception("Erro de conexão: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    // Cria o banco se não existir
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($dbName);

    // Cria a tabela se não existir
    $conn->query("
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender ENUM('user','bot') NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Recebe a mensagem do usuário
    $data = json_decode(file_get_contents('php://input'), true);
    $userMessage = trim($data['message'] ?? '');

    if (empty($userMessage)) {
        echo json_encode(['error' => 'Nenhuma mensagem recebida.']);
        exit;
    }

    // Salva a mensagem do usuário
    $stmtUser = $conn->prepare("INSERT INTO messages (sender, message) VALUES (?, ?)");
    $senderUser = 'user';
    $stmtUser->bind_param("ss", $senderUser, $userMessage);
    $stmtUser->execute();
    $stmtUser->close();

    // FILTRO DE ASSUNTOS PROIBIDOS (antes de chamar a API)
    $assuntos_proibidos = ["política", "religião", "guerra", "ódio", "violência"];
    foreach ($assuntos_proibidos as $assunto) {
        if (stripos($userMessage, $assunto) !== false) {
            $botReply = "Prefiro não falar sobre esse assunto. Mas podemos conversar sobre coisas boas!";
            // Salva resposta no banco
            $stmtBot = $conn->prepare("INSERT INTO messages (sender, message) VALUES (?, ?)");
            $senderBot = 'bot';
            $stmtBot->bind_param("ss", $senderBot, $botReply);
            $stmtBot->execute();
            $stmtBot->close();

            echo json_encode(["reply" => $botReply]);
            $conn->close();
            exit;
        }
    }

    // Busca o histórico (últimas 10 mensagens) — pega as últimas e reverte para ordem cronológica
    $stmtHistory = $conn->prepare("SELECT sender, message FROM messages ORDER BY id DESC LIMIT 10");
    $stmtHistory->execute();
    $resultHistory = $stmtHistory->get_result();

    $rows = [];
    while ($row = $resultHistory->fetch_assoc()) {
        $rows[] = $row;
    }
    $rows = array_reverse($rows); // agora está em ordem cronológica

    // Monta o histórico para enviar ao Gemini
    $conversationHistory = [];
    $contexto = '';
    foreach ($rows as $row) {
        $role = $row['sender'] === 'user' ? 'user' : 'model';
        $conversationHistory[] = [
            'role' => $role,
            'parts' => [['text' => $row['message']]]
        ];
        $contexto .= ucfirst($role) . ": " . $row['message'] . "\n";
    }

    // Garante que a nova mensagem esteja no contexto
    $conversationHistory[] = [
        'role' => 'user',
        'parts' => [['text' => $userMessage]]
    ];
    $contexto .= "user: " . $userMessage . "\n";

    $stmtHistory->close();

    // PROMPT PERSONALIZADO PARA O GEMINI
    $instrucoes = "
Você é um assistente virtual simpático e positivo.
Regras:
- Responda de forma amigável e curta.
- Sempre mantenha um tom otimista e leve.
- Evite falar sobre política, religião, violência ou temas negativos.
- Se não souber responder, diga algo positivo como 'Podemos falar de algo divertido?'
";

    $postData = [
        "contents" => [[
            "parts" => [[
                "text" => $instrucoes . "\n\n" . $contexto . "\n\nUsuário: " . $userMessage . "\nBot:"
            ]]
        ]]
    ];

    // Chamada para a API do Gemini
    $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $geminiApiKey;

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    // 🔹 Ignora verificação SSL (apenas para desenvolvimento! remova em produção)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpcode != 200) {
        throw new Exception('Falha ao chamar a API do Gemini. Código: ' . $httpcode . ' Erro cURL: ' . $curlError . ' Resposta: ' . $response);
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Erro ao decodificar a resposta JSON da API.');
    }

    // Extração segura da resposta (com fallback)
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $botReply = $responseData['candidates'][0]['content']['parts'][0]['text'];
    } elseif (isset($responseData['output'][0]['content'][0]['text'])) {
        $botReply = $responseData['output'][0]['content'][0]['text'];
    } else {
        $botReply = 'Desculpe, não consegui processar sua solicitação no momento.';
    }

    // --- INÍCIO DA INTEGRAÇÃO DO ÁUDIO ---
    $audio_filename = 'audio_' . uniqid() . '.mp3';
    $caminho_do_audio_completo = synthesize_text_to_speech($botReply, $audio_filename);
    error_log("Caminho do áudio retornado: " . var_export($caminho_do_audio_completo, true));

    $audio_url = null;
    if ($caminho_do_audio_completo !== false) {
        $audio_url = 'voz/' . $audio_filename;
    }
    // --- FIM DA INTEGRAÇÃO DO ÁUDIO ---

    // Salva a resposta do bot (agora com a resposta textual)
    $stmtBot = $conn->prepare("INSERT INTO messages (sender, message) VALUES (?, ?)");
    $senderBot = 'bot';
    $stmtBot->bind_param("ss", $senderBot, $botReply);
    $stmtBot->execute();
    $stmtBot->close();

    // Envia a resposta final para o front-end
    echo json_encode([
        'reply' => $botReply,
        'audio_url' => $audio_url
    ]);
    $conn->close();
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ocorreu um erro no servidor.', 'details' => $e->getMessage()]);
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    exit;
}
