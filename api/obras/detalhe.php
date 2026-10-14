<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();
header("Content-Type: application/json; charset=UTF-8");

function responderJson(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    responderJson(405, ["sucesso" => false, "mensagem" => "Método não permitido."]);
}

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);
$perfil = strtolower(trim((string) ($_SESSION["perfil"] ?? "")));
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($usuarioId <= 0) {
    responderJson(401, ["sucesso" => false, "mensagem" => "Sua sessão expirou. Entre novamente."]);
}
if (!in_array($perfil, ["editor", "admin"], true)) {
    responderJson(403, ["sucesso" => false, "mensagem" => "Você não tem permissão para abrir esta obra."]);
}
if (!$id || $id < 1) {
    responderJson(400, ["sucesso" => false, "mensagem" => "Identificador de obra inválido."]);
}

require_once "../../config/banco.php";

try {
    $sql = "SELECT id, titulo, autor, texto_alternativo, status, criada_por,
                   (imagem IS NOT NULL AND OCTET_LENGTH(imagem) > 0) AS imagem_salva
            FROM obras
            WHERE id = ? AND excluida_em IS NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$obra) {
        $conn->close();
        responderJson(404, ["sucesso" => false, "mensagem" => "A obra não foi encontrada."]);
    }

    if ($perfil !== "admin" && (int) $obra["criada_por"] !== $usuarioId) {
        $conn->close();
        responderJson(403, ["sucesso" => false, "mensagem" => "Você só pode editar obras que criou."]);
    }

    $stmtEtapas = $conn->prepare(
        "SELECT ordem, titulo, texto, zoom_x, zoom_y, zoom_largura, zoom_altura
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
            "zoom_x" => $etapa["zoom_x"] === null ? null : (float) $etapa["zoom_x"],
            "zoom_y" => $etapa["zoom_y"] === null ? null : (float) $etapa["zoom_y"],
            "zoom_largura" => $etapa["zoom_largura"] === null ? null : (float) $etapa["zoom_largura"],
            "zoom_altura" => $etapa["zoom_altura"] === null ? null : (float) $etapa["zoom_altura"]
        ];
    }

    $stmtEtapas->close();
    $conn->close();

    responderJson(200, [
        "sucesso" => true,
        "obra" => [
            "id" => (int) $obra["id"],
            "titulo" => $obra["titulo"],
            "autor" => $obra["autor"],
            "texto_alternativo" => $obra["texto_alternativo"],
            "status" => $obra["status"],
            "imagem_salva" => (bool) $obra["imagem_salva"],
            "etapas" => $etapas
        ]
    ]);
} catch (Throwable $erro) {
    error_log("Erro ao carregar obra para edição: " . $erro->getMessage());
    $conn->close();
    responderJson(500, ["sucesso" => false, "mensagem" => "Não foi possível carregar a obra."]);
}
