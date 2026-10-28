<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/protecao-csrf.php';
exigirOrigemConfiavelCsrf();

require_once __DIR__ . '/../../config/banco.php';

function responder(int $status, array $dados): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

/*
 * Recuperação por token disponível somente
 * durante o desenvolvimento local.
 */
$host = strtolower(
    explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]
);

$enderecoLocal = in_array(
    $_SERVER['REMOTE_ADDR'] ?? '',
    ['127.0.0.1', '::1'],
    true
);

$hostLocal = ($host === 'localhost');

if (!$enderecoLocal || !$hostLocal) {
    responder(403, [
        'sucesso' => false,
        'mensagem' => 'Recurso disponível apenas no ambiente local.'
    ]);
}

$entrada = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($entrada)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Solicitação inválida.'
    ]);
}

$email = strtolower(trim((string) ($entrada['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Informe um e-mail válido.'
    ]);
}

$transacaoAberta = false;

try {
    $stmt = $conn->prepare(
        'SELECT id
         FROM usuarios
         WHERE email = ?
         LIMIT 1'
    );

    $stmt->bind_param('s', $email);
    $stmt->execute();

    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario) {
        responder(200, [
            'sucesso' => true,
            'mensagem' => 'Se o e-mail estiver cadastrado, será gerado um token de recuperação.'
        ]);
    }

    // Gera um token aleatório de 64 caracteres hexadecimais.
    $token = bin2hex(random_bytes(32));

    // Armazena no banco somente o hash do token.
    $tokenHash = hash('sha256', $token);
    $usuarioId = (int) $usuario['id'];

    $conn->begin_transaction();
    $transacaoAberta = true;

    // Invalida os tokens anteriores ainda não utilizados.
    $stmt = $conn->prepare(
        'UPDATE tokens_recuperacao
         SET utilizado_em = NOW()
         WHERE usuario_id = ?
           AND utilizado_em IS NULL'
    );

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $stmt->close();

    // O token expira em 30 minutos.
    $stmt = $conn->prepare(
        'INSERT INTO tokens_recuperacao
            (usuario_id, token_hash, expira_em)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );

    $stmt->bind_param('is', $usuarioId, $tokenHash);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    $transacaoAberta = false;

    responder(200, [
        'sucesso' => true,
        'mensagem' => 'Token gerado para teste local.',
        'token_desenvolvimento' => $token
    ]);

} catch (Throwable $erro) {
    if ($transacaoAberta) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            error_log(
                'Falha ao reverter transação de recuperação: '
                . $erroRollback->getMessage()
            );
        }
    }

    error_log(
        'Erro ao gerar token de recuperação: '
        . $erro->getMessage()
    );

    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível gerar o token.'
    ]);
}
