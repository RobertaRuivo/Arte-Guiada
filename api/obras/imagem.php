<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

function falhaImagem(int $status, string $mensagem): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store");

    echo json_encode(
        [
            "sucesso" => false,
            "mensagem" => $mensagem
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
 * 1. Validar o método e o identificador.
 */

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    falhaImagem(405, "Método não permitido.");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    falhaImagem(400, "Identificador de obra inválido.");
}

/*
 * 2. Identificar o usuário, caso exista uma sessão.
 *
 * Obras publicadas podem ser visualizadas sem login.
 * Rascunhos continuam protegidos.
 */

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$perfil = strtolower(
    trim((string) ($_SESSION["perfil"] ?? ""))
);

require_once __DIR__ . "/../../config/banco.php";

try {
    /*
     * 3. Consultar a obra, sem permitir acesso a registros
     * excluídos logicamente.
     */

    $sql = "
        SELECT
            criada_por,
            status,
            imagem,
            imagem_mime
        FROM obras
        WHERE id = ?
          AND excluida_em IS NULL
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $obra = $stmt->get_result()->fetch_assoc();

    $stmt->close();
    $conn->close();

    if (!$obra) {
        falhaImagem(404, "A imagem não foi encontrada.");
    }

    /*
     * 4. Aplicar as permissões.
     *
     * Obras publicadas são públicas.
     * Obras em rascunho exigem uma sessão autorizada.
     */

    $obraPublicada = $obra["status"] === "publicada";

    if (!$obraPublicada) {
        if ($usuarioId <= 0) {
            falhaImagem(
                401,
                "Entre na sua conta para acessar esta imagem."
            );
        }

        if (!in_array($perfil, ["editor", "admin"], true)) {
            falhaImagem(
                403,
                "Você não tem permissão para acessar esta imagem."
            );
        }

        if (
            $perfil !== "admin" &&
            (int) $obra["criada_por"] !== $usuarioId
        ) {
            falhaImagem(
                403,
                "Você só pode acessar imagens das obras que criou."
            );
        }
    }

    /*
     * 5. Validar a imagem armazenada.
     */

    if (
        !is_string($obra["imagem"]) ||
        $obra["imagem"] === ""
    ) {
        falhaImagem(
            404,
            "Esta obra ainda não possui uma imagem salva."
        );
    }

    $mime = (string) $obra["imagem_mime"];

    $tiposPermitidos = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (!in_array($mime, $tiposPermitidos, true)) {
        falhaImagem(
            415,
            "Formato de imagem armazenado não permitido."
        );
    }

    /*
     * 6. Retornar os bytes da imagem.
     *
     * O navegador recebe a imagem diretamente, não um JSON.
     */

    header("Content-Type: " . $mime);
    header("Content-Length: " . strlen($obra["imagem"]));
    header("X-Content-Type-Options: nosniff");

    if ($obraPublicada) {
        header("Cache-Control: public, max-age=3600");
    } else {
        header("Cache-Control: private, no-store, max-age=0");
    }

    echo $obra["imagem"];

} catch (Throwable $erro) {
    error_log(
        "Erro ao carregar imagem de obra: " .
        $erro->getMessage()
    );

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    falhaImagem(
        500,
        "Não foi possível carregar a imagem."
    );
}