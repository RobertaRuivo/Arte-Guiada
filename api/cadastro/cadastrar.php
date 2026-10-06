<?php

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

$dados = json_decode(file_get_contents("php://input"), true);

$nome = trim($dados["nome"] ?? "");
$sobrenome = trim($dados["sobrenome"] ?? "");
$email = trim($dados["email"] ?? "");
$senha = $dados["senha"] ?? "";
$perfil = trim($dados["perfil"] ?? "");
$mensagem = trim($dados["mensagem"] ?? "");

if (
    $nome === "" ||
    $sobrenome === "" ||
    $email === "" ||
    $senha === ""
) {
    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Preencha todos os campos obrigatórios."
    ]);

    exit;
}

$email = strtolower($email);

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

$sql = "INSERT INTO usuarios
        (nome, sobrenome, email, senha, perfil, mensagem)
        VALUES (?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro ao preparar o cadastro."
    ]);

    exit;
}

$stmt->bind_param(
    "ssssss",
    $nome,
    $sobrenome,
    $email,
    $senhaHash,
    $perfil,
    $mensagem
);

try {
    $stmt->execute();

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Cadastro realizado com sucesso."
    ]);
} catch (mysqli_sql_exception $erro) {
    if ($erro->getCode() === 1062) {
        http_response_code(409);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Este e-mail já está cadastrado."
        ]);
    } else {
        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao realizar o cadastro."
        ]);
    }
}

$stmt->close();
$conn->close();
