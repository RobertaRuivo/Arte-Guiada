<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';
require_once __DIR__ . '/gestao-comum.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

$sessao = ag_exigir_perfil(['admin']);
ag_exigir_csrf();
$dados = ag_receber_json();

$acao = $dados['acao'] ?? '';
$acoes = ['atribuir_editor', 'reforcar_aviso', 'aprovar_correcao', 'devolver_correcao', 'marcar_improcedente', 'reabrir_relato'];
if (!is_string($acao) || !in_array($acao, $acoes, true)) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Ação administrativa inválida.']);
}

$relatoId = filter_var($dados['relato_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($relatoId === false || $relatoId === null) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador do relato inválido.']);
}

$observacao = '';
if (array_key_exists('observacao', $dados)) {
    if (!is_string($dados['observacao'])) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'A observação é inválida.']);
    }
    $observacao = trim($dados['observacao']);
    if (mb_strlen($observacao, 'UTF-8') > 1000) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'A observação deve ter no máximo 1.000 caracteres.']);
    }
}

$prioridades = ['baixa', 'media', 'alta', 'critica'];
$prioridade = $dados['prioridade'] ?? 'media';
if (!is_string($prioridade) || !in_array($prioridade, $prioridades, true)) {
    ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Prioridade inválida.']);
}

$adminId = (int) $sessao['usuario_id'];
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
        "SELECT r.id, r.obra_id, r.status, r.prioridade,
                r.editor_responsavel_id, o.criada_por, o.excluida_em, u.perfil AS criador_perfil
         FROM relatos_problema r
         INNER JOIN obras o ON o.id = r.obra_id
         LEFT JOIN usuarios u ON u.id = o.criada_por
         WHERE r.id = ?
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

    $obraId = (int) $relato['obra_id'];
    $statusAnterior = (string) $relato['status'];
    $prioridadeAnterior = (string) $relato['prioridade'];
    $editorAnterior = $relato['editor_responsavel_id'] !== null
        ? (int) $relato['editor_responsavel_id']
        : (strtolower(trim((string) ($relato['criador_perfil'] ?? ''))) === 'editor'
            ? (int) $relato['criada_por'] : 0);
    $editorNovo = $editorAnterior > 0 ? $editorAnterior : null;
    $statusNovo = $statusAnterior;
    $mensagemResposta = '';

    if ($acao === 'atribuir_editor') {
        if ($relato['excluida_em'] !== null) {
            throw new DomainException('A obra está na Lixeira. Restaure-a antes de encaminhar a correção.');
        }
        $relatoEncerrado = in_array($statusAnterior, ['resolvido', 'improcedente'], true);
        $editorRecebido = filter_var($dados['editor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($editorRecebido === false || $editorRecebido === null) {
            throw new DomainException('Selecione um editor válido.');
        }
        $stmtEditor = $conn->prepare(
            "SELECT id, NULLIF(TRIM(CONCAT_WS(' ', nome, sobrenome)), '') AS nome_completo
             FROM usuarios
             WHERE id = ? AND LOWER(TRIM(perfil)) = 'editor'
             LIMIT 1"
        );
        $stmtEditor->bind_param('i', $editorRecebido);
        $stmtEditor->execute();
        $editorDados = $stmtEditor->get_result()->fetch_assoc();
        $stmtEditor->close();
        if (!$editorDados) {
            throw new DomainException('A conta selecionada não possui perfil de editor.');
        }
        $editorNovo = (int) $editorRecebido;
        $revogarAnterior = filter_var($dados['revogar_editor_anterior'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $revogarAutor = filter_var($dados['revogar_autor_original'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $autorOriginal = (int) $relato['criada_por'];

        $stmtDesmarcar = $conn->prepare(
            "UPDATE obras_editores_acesso
             SET principal = 0, atualizado_em = CURRENT_TIMESTAMP
             WHERE obra_id = ?"
        );
        $stmtDesmarcar->bind_param('i', $obraId);
        $stmtDesmarcar->execute();
        $stmtDesmarcar->close();

        $motivoAcesso = $observacao !== '' ? $observacao : ('Atribuição do relato #' . $relatoId . '.');
        $stmtConceder = $conn->prepare(
            "INSERT INTO obras_editores_acesso
                (obra_id, usuario_id, ativo, principal, concedido_por, motivo)
             VALUES (?, ?, 1, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                ativo = 1,
                principal = 1,
                concedido_por = VALUES(concedido_por),
                motivo = VALUES(motivo),
                atualizado_em = CURRENT_TIMESTAMP"
        );
        $stmtConceder->bind_param('iiis', $obraId, $editorNovo, $adminId, $motivoAcesso);
        $stmtConceder->execute();
        $stmtConceder->close();

        if ($revogarAnterior && $editorAnterior > 0 && $editorAnterior !== $editorNovo) {
            $stmtRevogar = $conn->prepare(
                "INSERT INTO obras_editores_acesso
                    (obra_id, usuario_id, ativo, principal, concedido_por, motivo)
                 VALUES (?, ?, 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    ativo = 0,
                    principal = 0,
                    concedido_por = VALUES(concedido_por),
                    motivo = VALUES(motivo),
                    atualizado_em = CURRENT_TIMESTAMP"
            );
            $motivoRevogacao = 'Acesso revogado após encaminhamento do relato #' . $relatoId . '.';
            $stmtRevogar->bind_param('iiis', $obraId, $editorAnterior, $adminId, $motivoRevogacao);
            $stmtRevogar->execute();
            $stmtRevogar->close();
        }

        if ($revogarAutor && $autorOriginal > 0 && $autorOriginal !== $editorNovo) {
            $stmtRevogarAutor = $conn->prepare(
                "INSERT INTO obras_editores_acesso
                    (obra_id, usuario_id, ativo, principal, concedido_por, motivo)
                 VALUES (?, ?, 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    ativo = 0,
                    principal = 0,
                    concedido_por = VALUES(concedido_por),
                    motivo = VALUES(motivo),
                    atualizado_em = CURRENT_TIMESTAMP"
            );
            $motivoRevogacaoAutor = 'Acesso do autor original revogado pelo administrador no relato #' . $relatoId . '.';
            $stmtRevogarAutor->bind_param('iiis', $obraId, $autorOriginal, $adminId, $motivoRevogacaoAutor);
            $stmtRevogarAutor->execute();
            $stmtRevogarAutor->close();
        }

        $responsavelMudou = $editorAnterior !== $editorNovo;
        $precisaReencaminhar = $responsavelMudou || $relatoEncerrado;
        $statusNovo = $relatoEncerrado ? 'reaberto' : ($responsavelMudou ? 'atribuido' : $statusAnterior);
        if ($precisaReencaminhar) {
            $stmtUpdate = $conn->prepare(
                "UPDATE relatos_problema
                 SET editor_responsavel_id = ?, prioridade = ?, status = ?,
                     resposta_editor = NULL, corrigido_em = NULL,
                     verificado_por = NULL, verificado_em = NULL
                 WHERE id = ?"
            );
            $stmtUpdate->bind_param('issi', $editorNovo, $prioridade, $statusNovo, $relatoId);
        } else {
            $stmtUpdate = $conn->prepare(
                'UPDATE relatos_problema SET prioridade = ? WHERE id = ?'
            );
            $stmtUpdate->bind_param('si', $prioridade, $relatoId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $nomeEditor = $editorDados['nome_completo'] ?: ('Editor #' . $editorNovo);
        if ($precisaReencaminhar) {
            $mensagemResposta = $relatoEncerrado
                ? 'O administrador reabriu e encaminhou o relato #' . $relatoId . '. Acesse suas pendências e revise novamente o problema.'
                : 'O administrador encaminhou o relato #' . $relatoId . ' da obra. Acesse suas pendências e revise o problema.';
            ag_relato_notificar_editor(
                $conn,
                $editorNovo,
                $obraId,
                'relato_atribuido',
                'Um problema foi encaminhado para você',
                $mensagemResposta
            );
        } else {
            $mensagemResposta = 'A prioridade do relato foi atualizada. A responsabilidade atual foi mantida.';
            if ($observacao !== '') {
                ag_relato_notificar_editor(
                    $conn, $editorNovo, $obraId, 'relato_orientacao_admin',
                    'Há uma orientação do administrador sobre o relato',
                    'Relato #' . $relatoId . ': ' . $observacao
                );
            }
        }
        if ($editorAnterior > 0 && $editorAnterior !== $editorNovo) {
            $acessoTexto = $revogarAnterior ? 'Seu acesso de edição foi revogado.' : 'Seu acesso anterior à obra foi mantido.';
            ag_relato_notificar_editor(
                $conn,
                $editorAnterior,
                $obraId,
                'relato_reatribuido',
                'A responsabilidade pelo relato foi alterada',
                'O relato #' . $relatoId . ' foi encaminhado para outro editor. ' . $acessoTexto
            );
        }

        $acaoEvento = $relatoEncerrado
            ? 'relato_reaberto_e_atribuido'
            : ($responsavelMudou ? 'editor_atribuido' : 'prioridade_atualizada');
        $textoEvento = $observacao !== ''
            ? $observacao
            : ($relatoEncerrado
                ? ('Relato reaberto e encaminhado para: ' . $nomeEditor . '.')
                : ($responsavelMudou
                    ? ('Responsável definido: ' . $nomeEditor . '.')
                    : ('Prioridade atualizada para ' . $prioridade . '; responsável mantido: ' . $nomeEditor . '.')));
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, $acaoEvento,
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridade,
            $editorAnterior > 0 ? $editorAnterior : null, $editorNovo,
            $textoEvento
        );

    } elseif ($acao === 'reforcar_aviso') {
        if (in_array($statusAnterior, ['resolvido', 'improcedente'], true)) {
            throw new DomainException('Não é possível reforçar um relato encerrado. Reabra-o primeiro.');
        }
        if ($editorAnterior < 1) {
            throw new DomainException('O relato ainda não possui editor responsável. Atribua um editor primeiro.');
        }
        $observacao = ag_relato_texto_obrigatorio(['observacao' => $observacao], 'observacao', 'uma orientação para o editor');
        if ($statusAnterior === 'aguardando_verificacao') {
            $statusNovo = 'reaberto';
            $stmtUpdate = $conn->prepare('UPDATE relatos_problema SET status = ? WHERE id = ?');
            $stmtUpdate->bind_param('si', $statusNovo, $relatoId);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }
        ag_relato_notificar_editor(
            $conn, $editorAnterior, $obraId, 'relato_aviso_reforcado',
            'O administrador reforçou uma orientação sobre a obra',
            'Relato #' . $relatoId . ': ' . $observacao
        );
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, 'aviso_reforcado',
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridadeAnterior,
            $editorAnterior, $editorAnterior, $observacao
        );
        $mensagemResposta = 'A orientação foi enviada ao editor responsável.';

    } elseif ($acao === 'aprovar_correcao') {
        if ($statusAnterior !== 'aguardando_verificacao') {
            throw new DomainException('Só é possível aprovar uma correção que aguarda verificação.');
        }
        $statusNovo = 'resolvido';
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, verificado_por = ?, verificado_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmtUpdate->bind_param('sii', $statusNovo, $adminId, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        if ($editorAnterior > 0) {
            ag_relato_notificar_editor(
                $conn, $editorAnterior, $obraId, 'relato_correcao_aprovada',
                'Correção aprovada pelo administrador',
                'A correção do relato #' . $relatoId . ' foi aprovada. Obrigado pelo ajuste.'
            );
        }
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, 'correcao_aprovada',
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridadeAnterior,
            $editorAnterior > 0 ? $editorAnterior : null, $editorAnterior > 0 ? $editorAnterior : null,
            $observacao !== '' ? $observacao : 'O administrador verificou e aprovou a correção.'
        );
        $mensagemResposta = 'A correção foi aprovada e o relato foi encerrado.';

    } elseif ($acao === 'devolver_correcao') {
        if ($statusAnterior !== 'aguardando_verificacao') {
            throw new DomainException('Só é possível devolver uma correção que aguarda verificação.');
        }
        $observacao = ag_relato_texto_obrigatorio(['observacao' => $observacao], 'observacao', 'o motivo da devolução');
        if ($editorAnterior < 1) {
            throw new DomainException('O relato não possui editor responsável.');
        }
        $statusNovo = 'reaberto';
        $stmtUpdate = $conn->prepare('UPDATE relatos_problema SET status = ? WHERE id = ?');
        $stmtUpdate->bind_param('si', $statusNovo, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        ag_relato_notificar_editor(
            $conn, $editorAnterior, $obraId, 'relato_correcao_devolvida',
            'A correção precisa de mais um ajuste',
            'O administrador devolveu o relato #' . $relatoId . ': ' . $observacao
        );
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, 'correcao_devolvida',
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridadeAnterior,
            $editorAnterior, $editorAnterior, $observacao
        );
        $mensagemResposta = 'O relato foi devolvido ao editor com a orientação registrada.';

    } elseif ($acao === 'reabrir_relato') {
        if (!in_array($statusAnterior, ['resolvido', 'improcedente'], true)) {
            throw new DomainException('Este relato ainda não está encerrado.');
        }
        $observacao = ag_relato_texto_obrigatorio(['observacao' => $observacao], 'observacao', 'o motivo da reabertura');
        if ($editorAnterior < 1) {
            throw new DomainException('Atribua um editor antes de reabrir este relato.');
        }
        $statusNovo = 'reaberto';
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, verificado_por = NULL, verificado_em = NULL
             WHERE id = ?"
        );
        $stmtUpdate->bind_param('si', $statusNovo, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        ag_relato_notificar_editor(
            $conn, $editorAnterior, $obraId, 'relato_reaberto',
            'Um relato foi reaberto para revisão',
            'O administrador reabriu o relato #' . $relatoId . ': ' . $observacao
        );
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, 'relato_reaberto',
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridadeAnterior,
            $editorAnterior, $editorAnterior, $observacao
        );
        $mensagemResposta = 'O relato foi reaberto e enviado novamente ao editor responsável.';

    } elseif ($acao === 'marcar_improcedente') {
        if (in_array($statusAnterior, ['resolvido', 'improcedente'], true)) {
            throw new DomainException('O relato já está encerrado.');
        }
        $observacao = ag_relato_texto_obrigatorio(['observacao' => $observacao], 'observacao', 'a justificativa');
        $statusNovo = 'improcedente';
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, verificado_por = ?, verificado_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmtUpdate->bind_param('sii', $statusNovo, $adminId, $relatoId);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        if ($editorAnterior > 0) {
            ag_relato_notificar_editor(
                $conn, $editorAnterior, $obraId, 'relato_encerrado_improcedente',
                'Relato encerrado pelo administrador',
                'O relato #' . $relatoId . ' foi considerado improcedente. ' . $observacao
            );
        }
        ag_relato_registrar_evento(
            $conn, (int) $relatoId, $adminId, 'relato_improcedente',
            $statusAnterior, $statusNovo, $prioridadeAnterior, $prioridadeAnterior,
            $editorAnterior > 0 ? $editorAnterior : null, $editorAnterior > 0 ? $editorAnterior : null,
            $observacao
        );
        $mensagemResposta = 'O relato foi encerrado como improcedente.';
    }

    $conn->commit();
    $transacao = false;
    $conn->close();
    $conn = null;

    ag_responder_json(200, [
        'sucesso' => true,
        'mensagem' => $mensagemResposta !== '' ? $mensagemResposta : 'Ação administrativa concluída.',
        'relato_id' => (int) $relatoId,
        'status_anterior' => $statusAnterior,
        'status' => $statusNovo,
        'editor_responsavel_id' => $editorNovo,
        'prioridade' => $prioridade
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
    error_log('Falha na gestão administrativa do relato #' . $relatoId . ': ' . $erro->getMessage());
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível concluir a ação. Nenhuma alteração foi confirmada.']);
}
