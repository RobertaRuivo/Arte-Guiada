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

$entrada = json_decode(file_get_contents('php://input'), true);

if (!is_array($entrada)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Solicitação inválida.'
    ]);
}

$token = trim((string) ($entrada['token'] ?? ''));
$novaSenha = (string) ($entrada['nova_senha'] ?? '');
$confirmarSenha = (string) ($entrada['confirmar_senha'] ?? '');

if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Token inválido ou expirado.'
    ]);
}

if (
    strlen($novaSenha) < 8 ||
    (PASSWORD_DEFAULT === PASSWORD_BCRYPT && strlen($novaSenha) > 72)
) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'A senha deve ter pelo menos 8 bytes e, neste ambiente, no máximo 72 bytes.'
    ]);
}

if (!hash_equals($novaSenha, $confirmarSenha)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'As senhas não coincidem.'
    ]);
}

$transacaoAberta = false;

try {
    $tokenHash = hash('sha256', strtolower($token));

    $conn->begin_transaction();
    $transacaoAberta = true;

    // Bloqueia a linha durante a validação para evitar uso simultâneo.
    $stmt = $conn->prepare(
        'SELECT id, usuario_id
         FROM tokens_recuperacao
         WHERE token_hash = ?
           AND utilizado_em IS NULL
           AND expira_em > NOW()
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();

    $registroToken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$registroToken) {
        $conn->rollback();
        $transacaoAberta = false;

        responder(400, [
            'sucesso' => false,
            'mensagem' => 'Token inválido, expirado ou já utilizado.'
        ]);
    }

    $usuarioId = (int) $registroToken['usuario_id'];
    $tokenId = (int) $registroToken['id'];

    // Mantém compatibilidade com o login que usa password_verify().
    $hashSenha = password_hash($novaSenha, PASSWORD_DEFAULT);

    if ($hashSenha === false) {
        throw new RuntimeException('Não foi possível gerar o hash da senha.');
    }

    $stmt = $conn->prepare(
        'UPDATE usuarios SET senha = ? WHERE id = ?'
    );
    $stmt->bind_param('si', $hashSenha, $usuarioId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Não foi possível atualizar a senha do usuário.');
    }

    $stmt->close();

    // Marca o token como utilizado para impedir reutilização.
    $stmt = $conn->prepare(
        'UPDATE tokens_recuperacao
         SET utilizado_em = NOW()
         WHERE id = ?
           AND utilizado_em IS NULL
           AND expira_em > NOW()'
    );

    $stmt->bind_param('i', $tokenId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Não foi possível invalidar o token.');
    }

    $stmt->close();

    $conn->commit();
    $transacaoAberta = false;

    responder(200, [
        'sucesso' => true,
        'mensagem' => 'Senha redefinida com sucesso. Você já pode entrar com a nova senha.'
    ]);

} catch (Throwable $erro) {
    if ($transacaoAberta) {
        try {
            $conn->rollback();
        } catch (Throwable $ignorado) {
            // Mantém o erro original.
        }
    }

    error_log('Erro ao redefinir senha: ' . $erro->getMessage());

    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível redefinir a senha neste momento.'
    ]);
}
