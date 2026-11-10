<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['editor']);
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
                r.obra_id,
                o.titulo AS obra_titulo,
                o.autor AS obra_autor,
                o.excluida_em,
                r.descricao,
                r.tipo,
                r.status,
                r.prioridade,
                r.resposta_editor,
                DATE_FORMAT(r.enviado_em, '%Y-%m-%d %H:%i:%s') AS enviado_em,
                (
                    SELECT GROUP_CONCAT(t.tipo ORDER BY t.id SEPARATOR '|')
                    FROM relatos_problema_tipos t
                    WHERE t.relato_id = r.id
                ) AS tipos,
                (
                    SELECT ev.observacao
                    FROM relatos_problema_eventos ev
                    WHERE ev.relato_id = r.id
                      AND ev.acao IN ('editor_atribuido', 'aviso_reforcado', 'correcao_devolvida', 'relato_reaberto', 'relato_reaberto_e_atribuido', 'prioridade_atualizada')
                      AND ev.observacao <> ''
                    ORDER BY ev.id DESC
                    LIMIT 1
                ) AS orientacao_admin
            FROM relatos_problema r
            INNER JOIN obras o ON o.id = r.obra_id
            WHERE r.editor_responsavel_id = ?
              AND r.status NOT IN ('resolvido', 'improcedente')
            ORDER BY
                CASE r.prioridade
                    WHEN 'critica' THEN 1
                    WHEN 'alta' THEN 2
                    WHEN 'media' THEN 3
                    ELSE 4
                END,
                r.enviado_em ASC,
                r.id ASC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    $usuarioId = (int) $sessao['usuario_id'];
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $relatos = [];
    while ($r = $resultado->fetch_assoc()) {
        $tipos = [];
        if (is_string($r['tipos']) && $r['tipos'] !== '') {
            $tipos = array_values(array_unique(array_filter(explode('|', $r['tipos']))));
        }
        if ($tipos === [] && !empty($r['tipo'])) {
            $tipos = [(string) $r['tipo']];
        }
        $relatos[] = [
            'id' => (int) $r['id'],
            'obra_id' => (int) $r['obra_id'],
            'obra_titulo' => $r['obra_titulo'],
            'obra_autor' => $r['obra_autor'],
            'obra_excluida' => $r['excluida_em'] !== null,
            'descricao' => (string) $r['descricao'],
            'tipos' => $tipos,
            'status' => (string) $r['status'],
            'prioridade' => (string) $r['prioridade'],
            'resposta_editor' => $r['resposta_editor'],
            'orientacao_admin' => $r['orientacao_admin'],
            'enviado_em' => $r['enviado_em']
        ];
    }
    $stmt->close();
    $conn->close();
    $conn = null;

    ag_responder_json(200, [
        'sucesso' => true,
        'total' => count($relatos),
        'relatos' => $relatos
    ]);
} catch (Throwable $erro) {
    error_log('Falha ao listar relatos atribuídos ao editor: ' . $erro->getMessage());
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar suas pendências.']);
}
