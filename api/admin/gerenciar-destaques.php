<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

ag_exigir_perfil(['admin']);
if ($metodo === 'POST') {
    ag_exigir_csrf();
}

$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão indisponível.');
    }
    $conn->set_charset('utf8mb4');

    if ($metodo === 'GET') {
        $sql = "
            SELECT
                o.id,
                o.titulo,
                o.autor,
                o.texto_alternativo,
                o.destaque,
                o.ordem_destaque,
                (
                    SELECT COUNT(*)
                    FROM etapas_exploracao e
                    WHERE e.obra_id = o.id
                ) AS total_etapas
            FROM obras o
            WHERE o.status = 'publicada'
              AND o.excluida_em IS NULL
              AND o.imagem IS NOT NULL
              AND OCTET_LENGTH(o.imagem) > 0
              AND EXISTS (
                  SELECT 1 FROM etapas_exploracao e WHERE e.obra_id = o.id
              )
            ORDER BY
                CASE WHEN o.destaque = 1 THEN 0 ELSE 1 END,
                COALESCE(o.ordem_destaque, 9999),
                COALESCE(o.publicada_em, o.criada_em) DESC,
                o.id DESC
            LIMIT 200
        ";
        $resultado = $conn->query($sql);
        $obras = [];
        $selecionadas = [];
        while ($obra = $resultado->fetch_assoc()) {
            $item = [
                'id' => (int) $obra['id'],
                'titulo' => (string) ($obra['titulo'] ?? ''),
                'autor' => (string) ($obra['autor'] ?? ''),
                'texto_alternativo' => (string) ($obra['texto_alternativo'] ?? ''),
                'destaque' => (int) $obra['destaque'] === 1,
                'ordem_destaque' => $obra['ordem_destaque'] === null ? null : (int) $obra['ordem_destaque'],
                'total_etapas' => (int) $obra['total_etapas']
            ];
            $obras[] = $item;
            if ($item['destaque']) {
                $selecionadas[] = $item['id'];
            }
        }
        $resultado->free();
        $conn->close();
        $conn = null;

        ag_responder_json(200, [
            'sucesso' => true,
            'limite' => 3,
            'obras' => $obras,
            'selecionadas' => $selecionadas
        ]);
    }

    $dados = ag_receber_json();
    if (!is_array($dados) || !isset($dados['ids']) || !is_array($dados['ids'])) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Envie uma lista válida de obras.']);
    }
    $idsRecebidos = $dados['ids'];
    if (count($idsRecebidos) > 3) {
        ag_responder_json(422, ['sucesso' => false, 'mensagem' => 'A página inicial permite no máximo três obras em destaque.']);
    }

    $ids = [];
    foreach ($idsRecebidos as $valor) {
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'A seleção contém um identificador inválido.']);
        }
        $ids[] = (int) $id;
    }
    if (count(array_unique($ids)) !== count($ids)) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'A mesma obra não pode ser selecionada mais de uma vez.']);
    }

    $conn->begin_transaction();
    $stmtValidar = $conn->prepare(
        "SELECT id
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
           AND imagem IS NOT NULL
           AND OCTET_LENGTH(imagem) > 0
           AND EXISTS (SELECT 1 FROM etapas_exploracao e WHERE e.obra_id = obras.id)
         LIMIT 1
         FOR UPDATE"
    );
    foreach ($ids as $id) {
        $stmtValidar->bind_param('i', $id);
        $stmtValidar->execute();
        $obraValida = $stmtValidar->get_result()->fetch_assoc();
        if (!$obraValida) {
            $stmtValidar->close();
            $conn->rollback();
            ag_responder_json(422, [
                'sucesso' => false,
                'mensagem' => 'Uma das obras selecionadas não está publicada, não possui imagem/etapas ou não está mais disponível.'
            ]);
        }
    }
    $stmtValidar->close();

    $conn->query('UPDATE obras SET destaque = 0, ordem_destaque = NULL');
    $stmtAtualizar = $conn->prepare(
        "UPDATE obras
         SET destaque = 1, ordem_destaque = ?
         WHERE id = ? AND status = 'publicada' AND excluida_em IS NULL"
    );
    foreach ($ids as $indice => $id) {
        $ordem = $indice + 1;
        $stmtAtualizar->bind_param('ii', $ordem, $id);
        $stmtAtualizar->execute();
    }
    $stmtAtualizar->close();
    $conn->commit();
    $conn->close();
    $conn = null;

    ag_responder_json(200, [
        'sucesso' => true,
        'mensagem' => count($ids) . (count($ids) === 1 ? ' obra foi salva' : ' obras foram salvas') . ' como destaque da página inicial.',
        'selecionadas' => $ids
    ]);
} catch (Throwable $erro) {
    if ($conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    error_log('Falha em api/admin/gerenciar-destaques.php: ' . $erro->getMessage());
    ag_responder_json(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível carregar ou salvar os destaques.'
    ]);
}
