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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responderJson(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$obraId = filter_input(INPUT_GET, 'obra_id', FILTER_VALIDATE_INT);
if ($usuarioId <= 0) responderJson(401, ['sucesso' => false, 'mensagem' => 'Entre na sua conta para salvar esta obra.']);
if (!$obraId || $obraId < 1) responderJson(400, ['sucesso' => false, 'mensagem' => 'Identificador de obra inválido.']);

require_once __DIR__ . '/../../config/banco.php';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');
    $stmtObra = $conn->prepare("SELECT id FROM obras WHERE id = ? AND status = 'publicada' AND excluida_em IS NULL LIMIT 1");
    $stmtObra->bind_param('i', $obraId);
    $stmtObra->execute();
    $existe = $stmtObra->get_result()->fetch_assoc();
    $stmtObra->close();
    if (!$existe) {
        $conn->close();
        responderJson(404, ['sucesso' => false, 'mensagem' => 'A obra não está disponível.']);
    }

    $stmt = $conn->prepare('SELECT favorito, quero_explorar, explorada_em FROM biblioteca_usuario WHERE usuario_id = ? AND obra_id = ? LIMIT 1');
    $stmt->bind_param('ii', $usuarioId, $obraId);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    responderJson(200, [
        'sucesso' => true,
        'obra_id' => $obraId,
        'estado' => [
            'favorito' => (bool) ($linha['favorito'] ?? false),
            'quero_explorar' => (bool) ($linha['quero_explorar'] ?? false),
            'explorada' => !empty($linha['explorada_em']),
            'explorada_em' => $linha['explorada_em'] ?? null
        ]
    ]);
} catch (Throwable $erro) {
    error_log('Erro ao consultar estado da biblioteca: ' . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    responderJson(500, ['sucesso' => false, 'mensagem' => 'Não foi possível consultar esta obra.']);
}
