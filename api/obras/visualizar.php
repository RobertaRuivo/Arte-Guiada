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
| 2. Validar identificador da obra
|--------------------------------------------------------------------------
*/

$idRecebido = $_GET["id"] ?? null;

if (
    !is_string($idRecebido) &&
    !is_int($idRecebido)
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}

$id = filter_var(
    $idRecebido,
    FILTER_VALIDATE_INT
);

if ($id === false || $id === null || $id < 1) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Consultar dados públicos da obra
|--------------------------------------------------------------------------
*/

$conn = null;

$stmt = null;
$stmtEtapas = null;

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
     * A API pública disponibiliza somente obras publicadas
     * e que não foram excluídas.
     */

    $stmt = $conn->prepare(
        "SELECT
            id,
            titulo,
            autor,
            texto_alternativo,
            (
                imagem IS NOT NULL AND
                OCTET_LENGTH(imagem) > 0
            ) AS imagem_salva
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
         LIMIT 1"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $resultadoObra = $stmt->get_result();

    $obra = $resultadoObra->fetch_assoc();

    $resultadoObra->free();

    $stmt->close();
    $stmt = null;

    if (!$obra) {
        $conn->close();
        $conn = null;

        responderJson(404, [
            "sucesso" => false,
            "mensagem" => "Esta obra não existe ou não está publicada."
        ]);
    }

    /*
     * Recupera as etapas na ordem definida no editor.
     */

    $stmtEtapas = $conn->prepare(
        "SELECT
            ordem,
            titulo,
            texto,
            zoom_x,
            zoom_y,
            zoom_largura,
            zoom_altura
         FROM etapas_exploracao
         WHERE obra_id = ?
         ORDER BY ordem ASC, id ASC"
    );

    $stmtEtapas->bind_param("i", $id);
    $stmtEtapas->execute();

    $resultadoEtapas = $stmtEtapas->get_result();

    $etapas = [];

    while ($etapa = $resultadoEtapas->fetch_assoc()) {
        $etapas[] = [
            "ordem" => (int) $etapa["ordem"],
            "titulo" => $etapa["titulo"],
            "texto" => $etapa["texto"],

            "zoom_x" => $etapa["zoom_x"] === null
                ? null
                : (float) $etapa["zoom_x"],

            "zoom_y" => $etapa["zoom_y"] === null
                ? null
                : (float) $etapa["zoom_y"],

            "zoom_largura" => $etapa["zoom_largura"] === null
                ? null
                : (float) $etapa["zoom_largura"],

            "zoom_altura" => $etapa["zoom_altura"] === null
                ? null
                : (float) $etapa["zoom_altura"]
        ];
    }

    $resultadoEtapas->free();

    $stmtEtapas->close();
    $stmtEtapas = null;

    $conn->close();
    $conn = null;

    /*
     * Mantém o formato JSON utilizado pelo galeria.js.
     */

    responderJson(200, [
        "sucesso" => true,

        "obra" => [
            "id" => (int) $obra["id"],
            "titulo" => $obra["titulo"],
            "autor" => $obra["autor"],
            "texto_alternativo" => $obra["texto_alternativo"],
            "imagem_salva" => (bool) $obra["imagem_salva"],
            "etapas" => $etapas
        ]
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao consultar obra para visualização: " .
        $erro->getMessage()
    );

    // Fecha os comandos preparados que ainda estejam abertos.
    foreach ([$stmtEtapas, $stmt] as $stmtPendente) {
        if ($stmtPendente instanceof mysqli_stmt) {
            try {
                $stmtPendente->close();
            } catch (Throwable $erroFechamento) {
                // Preserva o erro original.
            }
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
        "mensagem" => "Não foi possível carregar os dados da obra."
    ]);
}