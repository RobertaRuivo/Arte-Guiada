<?php
declare(strict_types=1);

/* Utilitários internos do fluxo de gestão dos relatos. */

function ag_relato_registrar_evento(
    mysqli $conn,
    int $relatoId,
    int $usuarioId,
    string $acao,
    ?string $statusAnterior,
    ?string $statusNovo,
    ?string $prioridadeAnterior,
    ?string $prioridadeNova,
    ?int $editorAnterior,
    ?int $editorNovo,
    string $observacao
): void {
    $stmt = $conn->prepare(
        "INSERT INTO relatos_problema_eventos
            (relato_id, usuario_id, acao, status_anterior, status_novo,
             prioridade_anterior, prioridade_nova, editor_anterior_id,
             editor_novo_id, observacao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'iisssssiis',
        $relatoId,
        $usuarioId,
        $acao,
        $statusAnterior,
        $statusNovo,
        $prioridadeAnterior,
        $prioridadeNova,
        $editorAnterior,
        $editorNovo,
        $observacao
    );
    $stmt->execute();
    $stmt->close();

    // Mantém também o histórico legado para que as consultas já existentes continuem úteis.
    $observacaoHistorico = mb_substr(strtoupper($acao) . ': ' . $observacao, 0, 500, 'UTF-8');
    $statusAnteriorHistorico = $statusAnterior ?? '';
    $statusNovoHistorico = $statusNovo ?? '';
    $stmtHistorico = $conn->prepare(
        "INSERT INTO relatos_problema_historico
            (relato_id, status_anterior, status_novo, observacao, responsavel_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmtHistorico->bind_param(
        'isssi',
        $relatoId,
        $statusAnteriorHistorico,
        $statusNovoHistorico,
        $observacaoHistorico,
        $usuarioId
    );
    $stmtHistorico->execute();
    $stmtHistorico->close();
}

function ag_relato_notificar_editor(
    mysqli $conn,
    int $usuarioId,
    int $obraId,
    string $tipo,
    string $titulo,
    string $mensagem
): void {
    if ($usuarioId < 1) {
        return;
    }
    $titulo = mb_substr($titulo, 0, 200, 'UTF-8');
    $mensagem = mb_substr($mensagem, 0, 500, 'UTF-8');

    $stmt = $conn->prepare(
        "INSERT INTO notificacoes_editor
            (usuario_id, obra_id, tipo, titulo, mensagem)
         SELECT id, ?, ?, ?, ?
         FROM usuarios
         WHERE id = ? AND LOWER(TRIM(perfil)) = 'editor'
         LIMIT 1"
    );
    $stmt->bind_param('isssi', $obraId, $tipo, $titulo, $mensagem, $usuarioId);
    $stmt->execute();
    $stmt->close();
}

function ag_relato_notificar_admins(
    mysqli $conn,
    int $relatoId,
    string $tipo,
    string $titulo,
    string $mensagem
): void {
    $titulo = mb_substr($titulo, 0, 200, 'UTF-8');
    $mensagem = mb_substr($mensagem, 0, 500, 'UTF-8');
    $stmt = $conn->prepare(
        "INSERT INTO notificacoes_admin
            (usuario_id, relato_id, tipo, titulo, mensagem)
         SELECT id, ?, ?, ?, ?
         FROM usuarios
         WHERE LOWER(TRIM(perfil)) IN ('admin', 'administrador')"
    );
    $stmt->bind_param('isss', $relatoId, $tipo, $titulo, $mensagem);
    $stmt->execute();
    $stmt->close();
}

function ag_relato_texto_obrigatorio(
    array $dados,
    string $chave,
    string $rotulo,
    int $limite = 1000
): string {
    $valor = $dados[$chave] ?? '';
    if (!is_string($valor)) {
        ag_responder_json(400, [
            'sucesso' => false,
            'mensagem' => "O campo {$rotulo} é inválido."
        ]);
    }
    $valor = trim($valor);
    if ($valor === '' || mb_strlen($valor, 'UTF-8') > $limite) {
        ag_responder_json(400, [
            'sucesso' => false,
            'mensagem' => "Informe {$rotulo} com até {$limite} caracteres."
        ]);
    }
    return $valor;
}
