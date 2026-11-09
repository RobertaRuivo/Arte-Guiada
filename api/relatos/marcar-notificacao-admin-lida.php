<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['admin']);
ag_exigir_csrf();
$dados = ag_receber_json();
$id = filter_var($dados['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador do aviso inválido.']);
}
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');
    $stmt = $conn->prepare(
        'UPDATE notificacoes_admin SET lida_em = CURRENT_TIMESTAMP WHERE id = ? AND usuario_id = ? AND lida_em IS NULL'
    );
    $usuarioId = (int) $sessao['usuario_id'];
    $stmt->bind_param('ii', $id, $usuarioId);
    $stmt->execute();
    $alteradas = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    $conn = null;
    if ($alteradas !== 1) {
        ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'Aviso não encontrado ou já lido.']);
    }
    ag_responder_json(200, ['sucesso' => true, 'mensagem' => 'Aviso marcado como lido.']);
} catch (Throwable $erro) {
    error_log('Falha ao marcar aviso administrativo como lido: ' . $erro->getMessage());
    if ($conn instanceof mysqli) { try { $conn->close(); } catch (Throwable $ignorado) {} }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível atualizar o aviso.']);
}
