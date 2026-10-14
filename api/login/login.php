<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require_once "../../config/banco.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);

    exit;
}

$dados = json_decode(
    file_get_contents("php://input"),
    true
);

$email = trim($dados["email"] ?? "");
$senha = $dados["senha"] ?? "";

if ($email === "" || $senha === "") {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Informe o e-mail e a senha."
    ]);

    exit;
}

$email = strtolower($email);

$sql = "SELECT id, nome, sobrenome, email, senha, perfil
        FROM usuarios
        WHERE email = ?";

try {
    $stmt = $conn->prepare($sql);

    $stmt->bind_param("s", $email);

    $stmt->execute();

    $resultado = $stmt->get_result();
    $usuario = $resultado->fetch_assoc();

    if (
        !$usuario ||
        !password_verify($senha, $usuario["senha"])
    ) {
        http_response_code(401);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "E-mail ou senha incorretos."
        ]);

        $stmt->close();
        $conn->close();

        exit;
    }

    // A sessão só é criada após validar as credenciais.
    session_regenerate_id(true);

    $perfil = strtolower(
        trim($usuario["perfil"])
    );

    if ($perfil === "administrador") {
        $perfil = "admin";
    }

    $_SESSION["usuario_id"] = (int) $usuario["id"];
    $_SESSION["nome"] = $usuario["nome"];
    $_SESSION["perfil"] = $perfil;

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Login realizado com sucesso.",
        "usuario" => [
            "id" => $usuario["id"],
            "nome" => $usuario["nome"],
            "sobrenome" => $usuario["sobrenome"],
            "email" => $usuario["email"],
            "perfil" => $perfil
        ]
    ]);

    $stmt->close();
    $conn->close();

} catch (mysqli_sql_exception $erro) {
    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro ao realizar o login."
    ]);

    $conn->close();
}