<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['editor', 'admin']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador de obra inválido.']);
$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');

    $stmt = $conn->prepare(
        "SELECT o.id, o.titulo, o.autor, o.texto_alternativo, o.status, o.criada_por,
                (o.imagem IS NOT NULL AND OCTET_LENGTH(o.imagem) > 0) AS imagem_salva,
                NULLIF(TRIM(CONCAT_WS(' ', u.nome, u.sobrenome)), '') AS editor_nome
         FROM obras o
         LEFT JOIN usuarios u ON u.id = o.criada_por
         WHERE o.id = ? AND o.excluida_em IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$obra) {
        $conn->close();
        ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'A obra não foi encontrada.']);
    }

    $acessoDelegado = false;
    if ($sessao['perfil'] === 'editor') {
        $stmtAcesso = $conn->prepare(
            'SELECT ativo FROM obras_editores_acesso WHERE obra_id = ? AND usuario_id = ? LIMIT 1'
        );
        $usuarioId = (int) $sessao['usuario_id'];
        $stmtAcesso->bind_param('ii', $id, $usuarioId);
        $stmtAcesso->execute();
        $permissao = $stmtAcesso->get_result()->fetch_assoc();
        $stmtAcesso->close();

        $acessoDelegado = $permissao !== null && (int) $permissao['ativo'] === 1
            && (int) $obra['criada_por'] !== $usuarioId;
        $editorEhAutor = (int) $obra['criada_por'] === $usuarioId;
        $editorPodeEditar = $permissao !== null
            ? (int) $permissao['ativo'] === 1
            : $editorEhAutor;

        if (!$editorPodeEditar) {
            $conn->close();
            ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Você não tem mais permissão para editar esta obra.']);
        }
    }

    $stmt = $conn->prepare(
        'SELECT ordem, titulo, texto, zoom_x, zoom_y, zoom_largura, zoom_altura
         FROM etapas_exploracao WHERE obra_id = ? ORDER BY ordem ASC, id ASC'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $etapas = [];
    while ($etapa = $resultado->fetch_assoc()) {
        $etapas[] = [
            'ordem' => (int) $etapa['ordem'],
            'titulo' => $etapa['titulo'],
            'texto' => $etapa['texto'],
            'zoom_x' => $etapa['zoom_x'] === null ? null : (float) $etapa['zoom_x'],
            'zoom_y' => $etapa['zoom_y'] === null ? null : (float) $etapa['zoom_y'],
            'zoom_largura' => $etapa['zoom_largura'] === null ? null : (float) $etapa['zoom_largura'],
            'zoom_altura' => $etapa['zoom_altura'] === null ? null : (float) $etapa['zoom_altura']
        ];
    }
    $stmt->close();
    $conn->close();
    $conn = null;

    ag_responder_json(200, ['sucesso' => true, 'obra' => [
        'id' => (int) $obra['id'],
        'titulo' => $obra['titulo'],
        'autor' => $obra['autor'],
        'texto_alternativo' => $obra['texto_alternativo'],
        'status' => $obra['status'],
        'imagem_salva' => (bool) $obra['imagem_salva'],
        'criada_por' => (int) $obra['criada_por'],
        'editor_nome' => $obra['editor_nome'],
        'acesso_delegado' => $acessoDelegado,
        'etapas' => $etapas
    ]]);
} catch (Throwable $erro) {
    error_log('Falha em api/obras/detalhe.php: ' . $erro->getMessage());
    if ($conn instanceof mysqli) { try { $conn->close(); } catch (Throwable $ignorado) {} }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar a obra.']);
}
