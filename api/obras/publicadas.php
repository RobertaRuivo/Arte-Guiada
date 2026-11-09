<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderJson(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Validar método HTTP
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Consultar obras publicadas
|--------------------------------------------------------------------------
*/

$conn = null;
$resultado = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco não foi inicializada."
        );
    }

    $conn->set_charset("utf8mb4");

    /*
     * Retorna somente obras:
     * - publicadas;
     * - não excluídas;
     * - com imagem armazenada;
     * - com pelo menos uma etapa de exploração.
     *
     * Não há parâmetros externos nesta consulta.
     */

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
    $resultado = null;

    $totalObras = count($obras);

    $conn->close();
    $conn = null;

    responderJson(200, [
        "sucesso" => true,
        "total" => $totalObras,
        "obras" => $obras
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao listar obras públicas: " .
        $erro->getMessage()
    );

    if ($resultado instanceof mysqli_result) {
        try {
            $resultado->free();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível carregar as obras publicadas."
    ]);
}