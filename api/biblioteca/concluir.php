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
if ($usuarioId <= 0) responderJson(401, ['sucesso' => false, 'mensagem' => 'Entre na sua conta para registrar esta exploração.']);

require_once __DIR__ . '/../../config/protecao-csrf.php';
exigirOrigemConfiavelCsrf();

$dados = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($dados)) responderJson(400, ['sucesso' => false, 'mensagem' => 'O conteúdo enviado é inválido.']);
$obraId = filter_var($dados['obra_id'] ?? null, FILTER_VALIDATE_INT);
$etapasConcluidas = filter_var($dados['etapas_concluidas'] ?? null, FILTER_VALIDATE_INT);
if (!$obraId || $obraId < 1 || !$etapasConcluidas || $etapasConcluidas < 1) {
    responderJson(400, ['sucesso' => false, 'mensagem' => 'Não foi possível validar a conclusão da obra.']);
}

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
        responderJson(404, ['sucesso' => false, 'mensagem' => 'A obra não está disponível.']);
    }

    $stmtEtapas = $conn->prepare('SELECT COUNT(*) AS total FROM etapas_exploracao WHERE obra_id = ?');
    $stmtEtapas->bind_param('i', $obraId);
    $stmtEtapas->execute();
    $resultadoEtapas = $stmtEtapas->get_result()->fetch_assoc();
    $stmtEtapas->close();
    $totalEtapas = (int) ($resultadoEtapas['total'] ?? 0);
    if ($totalEtapas < 1 || $etapasConcluidas !== $totalEtapas) {
        $conn->close();
        responderJson(409, ['sucesso' => false, 'mensagem' => 'A conclusão não corresponde ao número de etapas cadastradas.']);
    }

    $stmt = $conn->prepare(
        'INSERT INTO biblioteca_usuario (usuario_id, obra_id, explorada_em)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE explorada_em = COALESCE(explorada_em, NOW())'
    );
    $stmt->bind_param('ii', $usuarioId, $obraId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    responderJson(200, ['sucesso' => true, 'obra_id' => $obraId, 'explorada' => true, 'mensagem' => 'Obra registrada em Já explorei.']);
} catch (Throwable $erro) {
    error_log('Erro ao registrar conclusão de obra: ' . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    responderJson(500, ['sucesso' => false, 'mensagem' => 'Não foi possível registrar a conclusão.']);
}
