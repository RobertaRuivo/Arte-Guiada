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

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}

require_once __DIR__ . "/../../config/banco.php";

try {
    $sqlObra = "
        SELECT
            id,
            titulo,
            autor,
            texto_alternativo
        FROM obras
        WHERE id = ?
          AND status = 'publicada'
          AND excluida_em IS NULL
          AND imagem IS NOT NULL
          AND OCTET_LENGTH(imagem) > 0
        LIMIT 1
    ";

    $stmt = $conn->prepare($sqlObra);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$obra) {
        $conn->close();

        responderJson(404, [
            "sucesso" => false,
            "mensagem" => "Esta obra não está disponível na galeria."
        ]);
    }

    $sqlEtapas = "
        SELECT
            ordem,
            titulo,
            texto,
            zoom_x,
            zoom_y,
            zoom_largura,
            zoom_altura
        FROM etapas_exploracao
        WHERE obra_id = ?
        ORDER BY ordem ASC
    ";

    $stmt = $conn->prepare($sqlEtapas);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $etapas = [];

    while ($etapa = $resultado->fetch_assoc()) {
        $etapas[] = [
            "ordem" => (int) $etapa["ordem"],
            "titulo" => $etapa["titulo"],
            "texto" => $etapa["texto"],
            "zoom_x" => $etapa["zoom_x"],
            "zoom_y" => $etapa["zoom_y"],
            "zoom_largura" => $etapa["zoom_largura"],
            "zoom_altura" => $etapa["zoom_altura"]
        ];
    }

    $stmt->close();
    $conn->close();

    if (count($etapas) === 0) {
        responderJson(422, [
            "sucesso" => false,
            "mensagem" => "Esta obra não possui etapas de exploração."
        ]);
    }

    responderJson(200, [
        "sucesso" => true,
        "obra" => [
            "id" => (int) $obra["id"],
            "titulo" => $obra["titulo"],
            "autor" => $obra["autor"],
            "texto_alternativo" => $obra["texto_alternativo"],
            "etapas" => $etapas
        ]
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao consultar detalhes públicos da obra: " .
        $erro->getMessage()
    );

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível carregar os dados da obra."
    ]);
}