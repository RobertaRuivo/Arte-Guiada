<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
ag_exigir_csrf();
$sessao = ag_exigir_perfil(['editor', 'admin']);
$dados = ag_receber_json();
$id = filter_var($dados['obra_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador de obra inválido.']);
$conn = null; $transacao = false;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction(); $transacao = true;
    $stmt = $conn->prepare("SELECT criada_por FROM obras WHERE id = ? AND excluida_em IS NULL LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $id); $stmt->execute(); $obra = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$obra) { $conn->rollback(); $transacao = false; $conn->close(); ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'Obra não encontrada ou já excluída.']); }
    $editorId = (int) $obra['criada_por'];
    if ($sessao['perfil'] === 'editor' && $editorId !== $sessao['usuario_id']) {
        $conn->rollback(); $transacao = false; $conn->close(); ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Você só pode excluir obras que pertencem à sua conta.']);
    }
    $stmt = $conn->prepare('UPDATE obras SET excluida_em = CURRENT_TIMESTAMP WHERE id = ? AND excluida_em IS NULL');
    $stmt->bind_param('i', $id); $stmt->execute(); $alteradas = $stmt->affected_rows; $stmt->close();
    if ($alteradas !== 1) throw new RuntimeException('A exclusão não alterou o registro esperado.');

    $conn->commit(); $transacao = false; $conn->close(); $conn = null;
    ag_responder_json(200, ['sucesso' => true, 'mensagem' => 'Obra excluída com sucesso. Ela pode ser restaurada pela Lixeira.', 'obra_id' => (int) $id]);
} catch (Throwable $erro) {
    if ($transacao && $conn instanceof mysqli) { try { $conn->rollback(); } catch (Throwable $ignorado) {} }
    if ($conn instanceof mysqli) $conn->close();
    error_log('Falha ao excluir obra: ' . $erro->getMessage());
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível excluir a obra. Verifique os registros do servidor.']);
}
