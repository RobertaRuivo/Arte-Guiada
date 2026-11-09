<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';
require_once __DIR__ . '/gestao-comum.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['editor']);
ag_exigir_csrf();
$dados = ag_receber_json();

$relatoId = filter_var($dados['relato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($relatoId === false || $relatoId === null) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador do relato inválido.']);
}
$acao = $dados['acao'] ?? '';
if (!is_string($acao) || !in_array($acao, ['iniciar', 'enviar_verificacao'], true)) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Ação inválida.']);
}

$observacao = '';
if ($acao === 'enviar_verificacao') {
    $observacao = ag_relato_texto_obrigatorio($dados, 'observacao', 'a descrição da correção');
    if (mb_strlen($observacao, 'UTF-8') < 5) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Descreva a correção com pelo menos 5 caracteres.']);
    }
} elseif (isset($dados['observacao']) && !is_string($dados['observacao'])) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'A observação é inválida.']);
}

$usuarioId = (int) $sessao['usuario_id'];
$conn = null;
$transacao = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão indisponível.');
    }
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();
    $transacao = true;

    $stmt = $conn->prepare(
        "SELECT id, obra_id, status, prioridade, editor_responsavel_id
         FROM relatos_problema
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('i', $relatoId);
    $stmt->execute();
    $relato = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$relato) {
        $conn->rollback();
        $transacao = false;
        ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'Relato não encontrado.']);
    }
    if ((int) $relato['editor_responsavel_id'] !== $usuarioId) {
        $conn->rollback();
        $transacao = false;
        ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Este relato não está atribuído à sua conta.']);
    }

    $statusAnterior = (string) $relato['status'];
    $statusNovo = $statusAnterior;
    $obraId = (int) $relato['obra_id'];
    $prioridade = (string) $relato['prioridade'];

    if ($acao === 'iniciar') {
        if ($statusAnterior === 'em_correcao') {
            $conn->commit();
            $transacao = false;
            $conn->close();
            ag_responder_json(200, ['sucesso' => true, 'alterado' => false, 'status' => 'em_correcao', 'mensagem' => 'A correção já está em andamento.']);
        }
        if (!in_array($statusAnterior, ['pendente', 'em_analise', 'atribuido', 'reaberto'], true)) {
            throw new DomainException('Este relato não pode ser iniciado no estado atual.');
        }
        $statusNovo = 'em_correcao';
        $stmtUpdate = $conn->prepare('UPDATE relatos_problema SET status = ? WHERE id = ?');
        $stmtUpdate->bind_param('si', $statusNovo, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $usuarioId, 'correcao_iniciada',
            $statusAnterior, $statusNovo, $prioridade, $prioridade,
            $usuarioId, $usuarioId, 'O editor iniciou a análise e correção da obra.'
        );
        $mensagemResposta = 'A correção foi marcada como em andamento.';

    } else {
        if (!in_array($statusAnterior, ['pendente', 'em_analise', 'atribuido', 'em_correcao', 'reaberto'], true)) {
            throw new DomainException('Este relato não está aguardando uma correção sua.');
        }
        $statusNovo = 'aguardando_verificacao';
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, resposta_editor = ?, corrigido_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmtUpdate->bind_param('ssi', $statusNovo, $observacao, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $usuarioId, 'correcao_enviada',
            $statusAnterior, $statusNovo, $prioridade, $prioridade,
            $usuarioId, $usuarioId, $observacao
        );
        ag_relato_notificar_admins(
            $conn,
            (int) $relatoId,
            'correcao_para_verificacao',
            'Correção enviada para verificação',
            'O editor enviou a correção do relato #' . $relatoId . ' para análise administrativa.'
        );
        $mensagemResposta = 'A correção foi enviada ao administrador para verificação.';
    }

    $conn->commit();
    $transacao = false;
    $conn->close();
    $conn = null;
    ag_responder_json(200, [
        'sucesso' => true,
        'mensagem' => $mensagemResposta,
        'relato_id' => (int) $relatoId,
        'status_anterior' => $statusAnterior,
        'status' => $statusNovo
    ]);

} catch (DomainException $erro) {
    if ($transacao && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
    }
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_json(409, ['sucesso' => false, 'mensagem' => $erro->getMessage()]);
} catch (Throwable $erro) {
    if ($transacao && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
    }
    error_log('Falha na resposta do editor ao relato #' . $relatoId . ': ' . $erro->getMessage());
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível registrar sua resposta. Nenhuma alteração foi confirmada.']);
}
