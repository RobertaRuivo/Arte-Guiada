<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

function responderJson(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

require_once __DIR__ . "/../../config/banco.php";

try {
    $sql = "
        SELECT
            o.id,
            o.titulo,
            o.autor,
            o.texto_alternativo,
            o.publicada_em,
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
              SELECT 1
              FROM etapas_exploracao e
              WHERE e.obra_id = o.id
          )
        ORDER BY
            COALESCE(o.publicada_em, o.criada_em) DESC,
            o.id DESC
    ";

    $resultado = $conn->query($sql);
    $obras = [];

    while ($obra = $resultado->fetch_assoc()) {
        $obras[] = [
            "id" => (int) $obra["id"],
            "titulo" => $obra["titulo"],
            "autor" => $obra["autor"],
            "texto_alternativo" => $obra["texto_alternativo"],
            "publicada_em" => $obra["publicada_em"],
            "total_etapas" => (int) $obra["total_etapas"]
        ];
    }

    $resultado->free();
    $conn->close();

    responderJson(200, [
        "sucesso" => true,
        "total" => count($obras),
        "obras" => $obras
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao listar obras públicas: " .
        $erro->getMessage()
    );

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível carregar as obras publicadas."
    ]);
}