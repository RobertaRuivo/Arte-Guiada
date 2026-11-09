<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ag_responder_destaques(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    ag_responder_destaques(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão indisponível.');
    }
    $conn->set_charset('utf8mb4');

    $sql = "
        SELECT
            o.id,
            o.titulo,
            o.autor,
            o.texto_alternativo,
            (
                SELECT COUNT(*)
                FROM etapas_exploracao e
                WHERE e.obra_id = o.id
            ) AS total_etapas
        FROM obras o
        WHERE o.destaque = 1
          AND o.status = 'publicada'
          AND o.excluida_em IS NULL
          AND o.imagem IS NOT NULL
          AND OCTET_LENGTH(o.imagem) > 0
          AND EXISTS (
              SELECT 1 FROM etapas_exploracao e WHERE e.obra_id = o.id
          )
        ORDER BY o.ordem_destaque ASC, o.id ASC
        LIMIT 3
    ";
    $resultado = $conn->query($sql);
    $obras = [];
    while ($obra = $resultado->fetch_assoc()) {
        $obras[] = [
            'id' => (int) $obra['id'],
            'titulo' => (string) ($obra['titulo'] ?? ''),
            'autor' => (string) ($obra['autor'] ?? ''),
            'texto_alternativo' => (string) ($obra['texto_alternativo'] ?? ''),
            'total_etapas' => (int) $obra['total_etapas']
        ];
    }
    $resultado->free();
    $conn->close();
    $conn = null;

    ag_responder_destaques(200, [
        'sucesso' => true,
        'total' => count($obras),
        'obras' => $obras
    ]);
} catch (Throwable $erro) {
    error_log('Falha ao consultar destaques públicos: ' . $erro->getMessage());
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    ag_responder_destaques(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível carregar as obras em destaque.'
    ]);
}
