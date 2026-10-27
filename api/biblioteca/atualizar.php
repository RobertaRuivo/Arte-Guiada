<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/Arte-Guiada/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responderJson(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderJson(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) responderJson(401, ['sucesso' => false, 'mensagem' => 'Entre na sua conta para salvar obras na biblioteca.']);

require_once __DIR__ . '/../../config/protecao-csrf.php';
exigirOrigemConfiavelCsrf();

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) responderJson(400, ['sucesso' => false, 'mensagem' => 'O conteúdo enviado é inválido.']);
$obraId = filter_var($dados['obra_id'] ?? null, FILTER_VALIDATE_INT);
$acao = (string) ($dados['acao'] ?? '');
$ativo = $dados['ativo'] ?? null;
if (!$obraId || $obraId < 1) responderJson(400, ['sucesso' => false, 'mensagem' => 'Identificador de obra inválido.']);
if (!in_array($acao, ['favorito', 'quero_explorar'], true)) responderJson(400, ['sucesso' => false, 'mensagem' => 'Ação de biblioteca inválida.']);
if (!is_bool($ativo)) responderJson(400, ['sucesso' => false, 'mensagem' => 'O estado da ação deve ser verdadeiro ou falso.']);

require_once __DIR__ . '/../../config/banco.php';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');
    $stmtObra = $conn->prepare("SELECT id FROM obras WHERE id = ? AND status = 'publicada' AND excluida_em IS NULL LIMIT 1");
    $stmtObra->bind_param('i', $obraId);
    $stmtObra->execute();
    $obra = $stmtObra->get_result()->fetch_assoc();
    $stmtObra->close();
    if (!$obra) {
        $conn->close();
        responderJson(404, ['sucesso' => false, 'mensagem' => 'A obra não está disponível para a biblioteca.']);
    }

    $valor = $ativo ? 1 : 0;
    if ($acao === 'favorito') {
        $stmt = $conn->prepare(
            'INSERT INTO biblioteca_usuario (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE favorito = VALUES(favorito)'
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO biblioteca_usuario (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE quero_explorar = VALUES(quero_explorar)'
        );
    }
    $stmt->bind_param('iii', $usuarioId, $obraId, $valor);
    $stmt->execute();
    $stmt->close();

    // Remove registros sem nenhum estado, preservando favoritos ou conclusões existentes.
    $stmtLimpar = $conn->prepare(
        'DELETE FROM biblioteca_usuario
         WHERE usuario_id = ? AND obra_id = ?
           AND favorito = 0 AND quero_explorar = 0 AND explorada_em IS NULL'
    );
    $stmtLimpar->bind_param('ii', $usuarioId, $obraId);
    $stmtLimpar->execute();
    $stmtLimpar->close();

    $stmtEstado = $conn->prepare('SELECT favorito, quero_explorar, explorada_em FROM biblioteca_usuario WHERE usuario_id = ? AND obra_id = ? LIMIT 1');
    $stmtEstado->bind_param('ii', $usuarioId, $obraId);
    $stmtEstado->execute();
    $estado = $stmtEstado->get_result()->fetch_assoc();
    $stmtEstado->close();
    $conn->close();

    responderJson(200, [
        'sucesso' => true,
        'obra_id' => $obraId,
        'acao' => $acao,
        'estado' => [
            'favorito' => (bool) ($estado['favorito'] ?? false),
            'quero_explorar' => (bool) ($estado['quero_explorar'] ?? false),
            'explorada' => !empty($estado['explorada_em']),
            'explorada_em' => $estado['explorada_em'] ?? null
        ]
    ]);
} catch (Throwable $erro) {
    error_log('Erro ao atualizar biblioteca: ' . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    responderJson(500, ['sucesso' => false, 'mensagem' => 'Não foi possível atualizar sua biblioteca.']);
}
