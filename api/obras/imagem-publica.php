<?php
declare(strict_types=1);

function falhaImagem(int $status, string $mensagem): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");

    echo json_encode(
        [
            "sucesso" => false,
            "mensagem" => $mensagem
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    falhaImagem(405, "Método não permitido.");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    falhaImagem(400, "Identificador de obra inválido.");
}

require_once __DIR__ . "/../../config/banco.php";

try {
    $conn->set_charset("utf8mb4");

    $stmt = $conn->prepare(
        "SELECT imagem, imagem_mime
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
         LIMIT 1"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $obra = $stmt->get_result()->fetch_assoc();

    $stmt->close();
    $conn->close();

    if (!$obra) {
        falhaImagem(404, "A imagem não foi encontrada.");
    }

    if (
        !is_string($obra["imagem"]) ||
        $obra["imagem"] === ""
    ) {
        falhaImagem(404, "Esta obra não possui imagem salva.");
    }

    $mime = (string) $obra["imagem_mime"];

    $tiposPermitidos = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (!in_array($mime, $tiposPermitidos, true)) {
        falhaImagem(415, "Formato de imagem não permitido.");
    }

    header("Content-Type: " . $mime);
    header("Content-Length: " . strlen($obra["imagem"]));
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");

    echo $obra["imagem"];

} catch (Throwable $erro) {
    error_log(
        "Erro ao carregar imagem pública: "
        . $erro->getMessage()
    );

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    falhaImagem(500, "Não foi possível carregar a imagem.");
}