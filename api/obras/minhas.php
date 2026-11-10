<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['editor', 'admin']);
$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');

    $sql = "SELECT
                o.id, o.titulo, o.autor, o.status, o.imagem_mime,
                (o.imagem IS NOT NULL AND OCTET_LENGTH(o.imagem) > 0) AS imagem_salva,
                o.criada_por, o.destaque, o.ordem_destaque,
                o.criada_em, o.atualizada_em, o.publicada_em,
                NULLIF(TRIM(CONCAT_WS(' ', u.nome, u.sobrenome)), '') AS editor_nome,
                (SELECT COUNT(*) FROM etapas_exploracao e WHERE e.obra_id = o.id) AS total_etapas,
                CASE
                    WHEN o.criada_por <> ? AND EXISTS (
                        SELECT 1 FROM obras_editores_acesso a
                        WHERE a.obra_id = o.id AND a.usuario_id = ? AND a.ativo = 1
                    ) THEN 1 ELSE 0
                END AS acesso_delegado
            FROM obras o
            LEFT JOIN usuarios u ON u.id = o.criada_por
            WHERE o.excluida_em IS NULL";

    if ($sessao['perfil'] === 'editor') {
        $sql .= " AND (
            EXISTS (
                SELECT 1 FROM obras_editores_acesso a
                WHERE a.obra_id = o.id
                  AND a.usuario_id = ?
                  AND a.ativo = 1
            )
            OR (
                o.criada_por = ?
                AND NOT EXISTS (
                    SELECT 1 FROM obras_editores_acesso a
                    WHERE a.obra_id = o.id AND a.usuario_id = ?
                )
            )
        )";
    }
    $sql .= ' ORDER BY o.atualizada_em DESC, o.id DESC';

    $stmt = $conn->prepare($sql);
    $usuarioId = (int) $sessao['usuario_id'];
    if ($sessao['perfil'] === 'editor') {
        $stmt->bind_param('iiiii', $usuarioId, $usuarioId, $usuarioId, $usuarioId, $usuarioId);
    } else {
        $stmt->bind_param('ii', $usuarioId, $usuarioId);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
    $obras = [];
    while ($obra = $resultado->fetch_assoc()) {
        $obras[] = [
            'id' => (int) $obra['id'],
            'titulo' => $obra['titulo'],
            'autor' => $obra['autor'],
            'status' => $obra['status'],
            'imagem_mime' => $obra['imagem_mime'],
            'imagem_salva' => (bool) $obra['imagem_salva'],
            'criada_por' => $obra['criada_por'] === null ? null : (int) $obra['criada_por'],
            'editor_nome' => $obra['editor_nome'],
            'destaque' => (bool) $obra['destaque'],
            'ordem_destaque' => $obra['ordem_destaque'] === null ? null : (int) $obra['ordem_destaque'],
            'criada_em' => $obra['criada_em'],
            'atualizada_em' => $obra['atualizada_em'],
            'publicada_em' => $obra['publicada_em'],
            'total_etapas' => (int) $obra['total_etapas'],
            'acesso_delegado' => (bool) $obra['acesso_delegado']
        ];
    }
    $stmt->close();
    $conn->close();
    $conn = null;
    ag_responder_json(200, ['sucesso' => true, 'total' => count($obras), 'obras' => $obras]);
} catch (Throwable $erro) {
    error_log('Falha em api/obras/minhas.php: ' . $erro->getMessage());
    if ($conn instanceof mysqli) { try { $conn->close(); } catch (Throwable $ignorado) {} }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar as obras.']);
}
