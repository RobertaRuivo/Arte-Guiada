<?php

declare(strict_types=1);

function falhaImagemPublica(int $status, string $mensagem): void
{
    http_response_code($status);

    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");

    echo json_encode(
        [
            "sucesso" => false,
            "mensagem" => $mensagem
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
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

    falhaImagemPublica(
        405,
        "Metodo nao permitido."
    );
}

/*
|--------------------------------------------------------------------------
| 2. Validar identificador
|--------------------------------------------------------------------------
*/

$idRecebido = $_GET["id"] ?? null;

if (!is_string($idRecebido) && !is_int($idRecebido)) {
    falhaImagemPublica(
        400,
        "Identificador invalido."
    );
}

$id = filter_var(
    $idRecebido,
    FILTER_VALIDATE_INT
);

if ($id === false || $id === null || $id < 1) {
    falhaImagemPublica(
        400,
        "Identificador invalido."
    );
}

/*
|--------------------------------------------------------------------------
| 3. Inicializar recursos
|--------------------------------------------------------------------------
*/

$conn = null;
$stmt = null;

try {
    mysqli_report(
        MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
    );

    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexao com o banco nao foi inicializada."
        );
    }

    $conn->set_charset("utf8mb4");

    /*
    |--------------------------------------------------------------------------
    | 4. Consultar somente obras publicadas e nao excluidas
    |--------------------------------------------------------------------------
    */

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

    $resultado = $stmt->get_result();

    $obra = $resultado->fetch_assoc();

    $resultado->free();

    $stmt->close();
    $stmt = null;

    $conn->close();
    $conn = null;

    if (
        !$obra ||
        !isset($obra["imagem"]) ||
        !is_string($obra["imagem"]) ||
        $obra["imagem"] === ""
    ) {
        falhaImagemPublica(
            404,
            "Imagem nao encontrada."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Validar MIME declarado
    |--------------------------------------------------------------------------
    */

    $mimeDeclarado = (string) (
        $obra["imagem_mime"] ?? ""
    );

    $mimesPermitidos = [
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if (!in_array($mimeDeclarado, $mimesPermitidos, true)) {
        falhaImagemPublica(
            415,
            "Formato de imagem nao permitido."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Conferir o conteúdo binário real
    |--------------------------------------------------------------------------
    */

    $imagem = $obra["imagem"];

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeReal = $finfo->buffer($imagem);

    if (
        !is_string($mimeReal) ||
        $mimeReal !== $mimeDeclarado
    ) {
        falhaImagemPublica(
            415,
            "O conteudo da imagem nao corresponde ao formato declarado."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Entregar imagem
    |--------------------------------------------------------------------------
    */

    header("Content-Type: " . $mimeDeclarado);
    header("Content-Length: " . strlen($imagem));
    header("Content-Disposition: inline");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");

    echo $imagem;
    exit;

} catch (Throwable $erro) {

    error_log(
        "Erro ao carregar imagem publica: "
        . $erro->getMessage()
    );

    if ($stmt instanceof mysqli_stmt) {
        try {
            $stmt->close();
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

    falhaImagemPublica(
        500,
        "Nao foi possivel carregar a imagem."
    );
}
