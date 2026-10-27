
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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}
require_once __DIR__ . '/../../config/protecao-csrf.php';
exigirOrigemConfiavelCsrf();

$corpo = file_get_contents("php://input");
$dados = json_decode($corpo, true);

if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O corpo da requisição contém JSON inválido."
    ]);
}

foreach (["nome", "sobrenome", "email", "senha"] as $campo) {
    if (!isset($dados[$campo]) || !is_string($dados[$campo])) {
        responderJson(400, [
            "sucesso" => false,
            "mensagem" => "Os dados enviados são inválidos."
        ]);
    }
}

$nome = trim($dados["nome"]);
$sobrenome = trim($dados["sobrenome"]);
$email = strtolower(trim($dados["email"]));
$senha = $dados["senha"];

$mensagemRecebida = $dados["mensagem"] ?? "";

if (!is_string($mensagemRecebida)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O campo de mensagem é inválido."
    ]);
}

$mensagem = trim($mensagemRecebida);

if (
    $nome === "" ||
    $sobrenome === "" ||
    $email === "" ||
    $senha === ""
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Preencha todos os campos obrigatórios."
    ]);
}

if (
    mb_strlen($nome, "UTF-8") > 100 ||
    mb_strlen($sobrenome, "UTF-8") > 100
) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "Nome e sobrenome devem ter até 100 caracteres."
    ]);
}

if (
    mb_strlen($email, "UTF-8") > 150 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "Informe um endereço de e-mail válido."
    ]);
}

if (
    strlen($senha) < 8 ||
    strlen($senha) > 4096
) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "A senha deve ter pelo menos 8 caracteres."
    ]);
}

/*
 * O perfil não é aceito do cliente.
 * Contas comuns começam como visitantes.
 * Perfis privilegiados exigem autorização específica.
 */
$perfil = "visitante";

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

if ($senhaHash === false) {
    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível processar a senha."
    ]);
}

try {
    require_once __DIR__ . "/../../config/banco.php";

    $sql = "INSERT INTO usuarios
            (nome, sobrenome, email, senha, perfil, mensagem)
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "ssssss",
        $nome,
        $sobrenome,
        $email,
        $senhaHash,
        $perfil,
        $mensagem
    );

    $stmt->execute();
    $stmt->close();
    $conn->close();

    responderJson(201, [
        "sucesso" => true,
        "mensagem" => "Cadastro realizado com sucesso."
    ]);

} catch (mysqli_sql_exception $erro) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    if ((int) $erro->getCode() === 1062) {
        responderJson(409, [
            "sucesso" => false,
            "mensagem" => "Este e-mail já está cadastrado."
        ]);
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro no cadastro: " .
        $erro->getMessage()
    );

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Erro ao realizar o cadastro."
    ]);
}
