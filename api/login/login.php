<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderJsonLogin(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
 * 1. Método HTTP.
 */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJsonLogin(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
 * 2. Proteção de origem.
 */

require_once __DIR__ . "/../../config/protecao-csrf.php";

exigirOrigemConfiavelCsrf();

/*
 * 3. Validação do JSON.
 */

try {
    $dados = json_decode(
        file_get_contents("php://input"),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $erro) {
    responderJsonLogin(400, [
        "sucesso" => false,
        "mensagem" => "O corpo da requisição contém JSON inválido."
    ]);
}

if (!is_array($dados)) {
    responderJsonLogin(400, [
        "sucesso" => false,
        "mensagem" => "Os dados enviados são inválidos."
    ]);
}

/*
 * 4. Validação dos campos.
 */

foreach (["email", "senha"] as $campo) {
    if (!isset($dados[$campo]) || !is_string($dados[$campo])) {
        responderJsonLogin(400, [
            "sucesso" => false,
            "mensagem" => "Os dados enviados são inválidos."
        ]);
    }
}

$email = strtolower(trim($dados["email"]));
$senhaInformada = $dados["senha"];

if ($email === "" || $senhaInformada === "") {
    responderJsonLogin(400, [
        "sucesso" => false,
        "mensagem" => "Informe o e-mail e a senha."
    ]);
}

if (
    mb_strlen($email, "UTF-8") > 150 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    responderJsonLogin(422, [
        "sucesso" => false,
        "mensagem" => "Informe um endereço de e-mail válido."
    ]);
}

if (strlen($senhaInformada) > 4096) {
    responderJsonLogin(422, [
        "sucesso" => false,
        "mensagem" => "Os dados enviados são inválidos."
    ]);
}

/*
 * 5. Consulta ao banco.
 */

$conn = null;
$stmt = null;
$usuarioAutenticado = null;

try {
    require_once __DIR__ . "/../../config/banco.php";

    $sql = "SELECT id, nome, sobrenome, email, senha, perfil
            FROM usuarios
            WHERE email = ?";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param("s", $email);

    $stmt->execute();

    $stmt->bind_result(
        $id,
        $nome,
        $sobrenome,
        $emailBanco,
        $senhaHash,
        $perfilBanco
    );

    if ($stmt->fetch()) {
        $usuarioAutenticado = [
            "id" => (int) $id,
            "nome" => $nome,
            "sobrenome" => $sobrenome,
            "email" => $emailBanco,
            "senha" => $senhaHash,
            "perfil" => $perfilBanco
        ];
    }

    $stmt->close();
    $stmt = null;

    $conn->close();
    $conn = null;

} catch (Throwable $erro) {

    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    if ($conn instanceof mysqli) {
        $conn->close();
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro interno no login: " .
        $erro->getMessage()
    );

    responderJsonLogin(500, [
        "sucesso" => false,
        "mensagem" => "Erro ao realizar o login."
    ]);
}

/*
 * 6. Verificação das credenciais.
 */

if (
    !$usuarioAutenticado ||
    !password_verify($senhaInformada, $usuarioAutenticado["senha"])
) {
    error_log(
        "[" . date("Y-m-d H:i:s") . "] Falha de autenticação no login."
    );

    responderJsonLogin(401, [
        "sucesso" => false,
        "mensagem" => "E-mail ou senha incorretos."
    ]);
}

/*
 * 7. Validação do perfil.
 */

$perfil = strtolower(
    trim((string) ($usuarioAutenticado["perfil"] ?? ""))
);

if ($perfil === "administrador") {
    $perfil = "admin";
}

$perfisPermitidos = [
    "visitante",
    "editor",
    "admin"
];

if (!in_array($perfil, $perfisPermitidos, true)) {
    responderJsonLogin(403, [
        "sucesso" => false,
        "mensagem" => "Perfil de acesso inválido ou não autorizado."
    ]);
}

/*
 * 8. Configuração do cookie da sessão.
 */

$scriptName = str_replace(
    "\\",
    "/",
    (string) ($_SERVER["SCRIPT_NAME"] ?? "/")
);

$posicaoApi = strpos($scriptName, "/api/");

$caminhoCookie = $posicaoApi === false
    ? "/"
    : substr($scriptName, 0, $posicaoApi + 1);

$https = (
    !empty($_SERVER["HTTPS"]) &&
    strtolower((string) $_SERVER["HTTPS"]) !== "off"
) || (
    (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443
);

try {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");

    session_cache_limiter("");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => $caminhoCookie,
        "secure" => $https,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    if (!session_start()) {
        throw new RuntimeException("Falha ao iniciar sessão.");
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException("Falha ao regenerar sessão.");
    }

    $_SESSION = [];

    $_SESSION["usuario_id"] = $usuarioAutenticado["id"];
    $_SESSION["nome"] = $usuarioAutenticado["nome"];
    $_SESSION["perfil"] = $perfil;

    if (!session_write_close()) {
        throw new RuntimeException("Falha ao salvar sessão.");
    }

} catch (Throwable $erro) {

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_abort();
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro na sessão: " .
        $erro->getMessage()
    );

    responderJsonLogin(500, [
        "sucesso" => false,
        "mensagem" => "Erro ao realizar o login."
    ]);
}

/*
 * 9. Registro do sucesso.
 */

error_log(
    "[" . date("Y-m-d H:i:s") . "] Login realizado com sucesso. Usuario ID: " .
    $usuarioAutenticado["id"]
);

/*
 * 10. Resposta.
 */

responderJsonLogin(200, [
    "sucesso" => true,
    "mensagem" => "Login realizado com sucesso.",
    "usuario" => [
        "id" => $usuarioAutenticado["id"],
        "nome" => $usuarioAutenticado["nome"],
        "sobrenome" => $usuarioAutenticado["sobrenome"],
        "email" => $usuarioAutenticado["email"],
        "perfil" => $perfil
    ]
]);