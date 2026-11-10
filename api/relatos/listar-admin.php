<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

ag_exigir_perfil(['admin']);
$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão indisponível.');
    }
    $conn->set_charset('utf8mb4');

    $sql = "SELECT
                r.id,
                r.usuario_id,
                r.obra_id,
                o.titulo AS obra_titulo,
                o.autor AS obra_autor,
                o.criada_por AS obra_criada_por,
                NULLIF(TRIM(CONCAT_WS(' ', criador.nome, criador.sobrenome)), '') AS autor_conta_nome,
                r.editor_responsavel_id,
                NULLIF(TRIM(CONCAT_WS(' ', editor.nome, editor.sobrenome)), '') AS editor_responsavel_nome,
                r.tipo,
                r.descricao,
                r.status,
                r.prioridade,
                r.resposta_editor,
                DATE_FORMAT(r.corrigido_em, '%Y-%m-%d %H:%i:%s') AS corrigido_em,
                r.verificado_por,
                DATE_FORMAT(r.verificado_em, '%Y-%m-%d %H:%i:%s') AS verificado_em,
                DATE_FORMAT(r.enviado_em, '%Y-%m-%d %H:%i:%s') AS enviado_em,
                CASE
                    WHEN relator.id IS NULL THEN 'Visitante anônimo'
                    ELSE TRIM(CONCAT_WS(' ', relator.nome, relator.sobrenome))
                END AS relator_nome,
                (
                    SELECT GROUP_CONCAT(t.tipo ORDER BY t.id SEPARATOR '|')
                    FROM relatos_problema_tipos t
                    WHERE t.relato_id = r.id
                ) AS tipos,
                (
                    SELECT ev.acao
                    FROM relatos_problema_eventos ev
                    WHERE ev.relato_id = r.id
                    ORDER BY ev.id DESC
                    LIMIT 1
                ) AS ultima_acao,
                (
                    SELECT ev.observacao
                    FROM relatos_problema_eventos ev
                    WHERE ev.relato_id = r.id
                    ORDER BY ev.id DESC
                    LIMIT 1
                ) AS ultima_observacao,
                (
                    SELECT DATE_FORMAT(ev.criada_em, '%Y-%m-%d %H:%i:%s')
                    FROM relatos_problema_eventos ev
                    WHERE ev.relato_id = r.id
                    ORDER BY ev.id DESC
                    LIMIT 1
                ) AS ultima_acao_em
            FROM relatos_problema r
            INNER JOIN obras o ON o.id = r.obra_id
            LEFT JOIN usuarios relator ON relator.id = r.usuario_id
            LEFT JOIN usuarios criador ON criador.id = o.criada_por
            LEFT JOIN usuarios editor
                ON editor.id = r.editor_responsavel_id
               AND LOWER(TRIM(editor.perfil)) = 'editor'
            ORDER BY
                CASE r.status
                    WHEN 'aguardando_verificacao' THEN 1
                    WHEN 'reaberto' THEN 2
                    WHEN 'pendente' THEN 3
                    WHEN 'atribuido' THEN 4
                    WHEN 'em_correcao' THEN 5
                    WHEN 'em_analise' THEN 6
                    ELSE 7
                END,
                CASE r.prioridade
                    WHEN 'critica' THEN 1
                    WHEN 'alta' THEN 2
                    WHEN 'media' THEN 3
                    ELSE 4
                END,
                r.enviado_em DESC,
                r.id DESC
            LIMIT 500";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $relatos = [];

    while ($relato = $resultado->fetch_assoc()) {
        $tipos = [];
        if (is_string($relato['tipos']) && $relato['tipos'] !== '') {
            $tipos = array_values(array_unique(array_filter(
                explode('|', $relato['tipos']),
                static fn(string $tipo): bool => $tipo !== ''
            )));
        }
        if ($tipos === [] && !empty($relato['tipo'])) {
            $tipos = [(string) $relato['tipo']];
        }

        $relato['id'] = (int) $relato['id'];
        $relato['obra_id'] = (int) $relato['obra_id'];
        $relato['usuario_id'] = $relato['usuario_id'] === null ? null : (int) $relato['usuario_id'];
        $relato['obra_criada_por'] = $relato['obra_criada_por'] === null ? null : (int) $relato['obra_criada_por'];
        $relato['editor_responsavel_id'] = $relato['editor_responsavel_id'] === null
            ? null : (int) $relato['editor_responsavel_id'];
        $relato['tipos'] = $tipos;
        unset($relato['tipo']);
        $relatos[] = $relato;
    }
    $stmt->close();

    $stmtEditores = $conn->prepare(
        "SELECT id,
                NULLIF(TRIM(CONCAT_WS(' ', nome, sobrenome)), '') AS nome_completo
         FROM usuarios
         WHERE LOWER(TRIM(perfil)) = 'editor'
         ORDER BY nome, sobrenome, id"
    );
    $stmtEditores->execute();
    $resultadoEditores = $stmtEditores->get_result();
    $editores = [];
    while ($editor = $resultadoEditores->fetch_assoc()) {
        $editores[] = [
            'id' => (int) $editor['id'],
            'nome' => $editor['nome_completo'] ?: ('Editor #' . (int) $editor['id'])
        ];
    }
    $stmtEditores->close();

    $conn->close();
    $conn = null;

    ag_responder_json(200, [
        'sucesso' => true,
        'total' => count($relatos),
        'relatos' => $relatos,
        'editores' => $editores
    ]);
} catch (Throwable $erro) {
    error_log('Falha ao listar relatos para administração: ' . $erro->getMessage());
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_json(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível carregar os relatos.'
    ]);
}
