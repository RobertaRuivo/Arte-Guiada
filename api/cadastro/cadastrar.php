<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

/**
 * Retorna uma resposta JSON e encerra a execução.
 */
function responderJson(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
 * 1. Verifica o método HTTP.
 */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
 * 2. Verifica a origem da requisição.
 */

require_once __DIR__ . "/../../config/log.php";
require_once __DIR__ . "/../../config/protecao-csrf.php";

exigirOrigemConfiavelCsrf();

/*
 * 3. Valida o JSON recebido.
 */

$corpo = file_get_contents("php://input");

try {
    $dados = json_decode(
        $corpo,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $erro) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O corpo da requisição contém JSON inválido."
    ]);
}

if (!is_array($dados)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Os dados enviados são inválidos."
    ]);
}

/*
 * 4. Verifica os campos obrigatórios.
 */

foreach (["nome", "sobrenome", "email", "senha"] as $campo) {
    if (
        !isset($dados[$campo]) ||
        !is_string($dados[$campo])
    ) {
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

/*
 * 5. Valida a mensagem opcional.
 */

$mensagemRecebida = $dados["mensagem"] ?? "";

if (!is_string($mensagemRecebida)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O campo de mensagem é inválido."
    ]);
}

$mensagem = trim($mensagemRecebida);

/*
 * 6. Verifica campos vazios.
 */

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

/*
 * 7. Valida nome e sobrenome.
 */

if (
    mb_strlen($nome, "UTF-8") > 100 ||
    mb_strlen($sobrenome, "UTF-8") > 100
) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "Nome e sobrenome devem ter até 100 caracteres."
    ]);
}

/*
 * 8. Valida o e-mail.
 */

if (
    mb_strlen($email, "UTF-8") > 150 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "Informe um endereço de e-mail válido."
    ]);
}

/*
 * 9. Valida a senha.
 */

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
 * 10. O perfil não é recebido do cliente.
 *
 * Contas comuns sempre começam como visitantes.
 */

$perfil = "visitante";

/*
 * 11. Gera o hash da senha.
 */

$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

if ($senhaHash === false) {
    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível processar a senha."
    ]);
}

/*
 * 12. Insere o usuário no banco.
 */

$conn = null;
$stmt = null;

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

    $usuarioId = (int) $conn->insert_id;

    $stmt->close();
    $stmt = null;

    $conn->close();
    $conn = null;

    /*
     * Registra a criação da entidade.
     */

    registrarLog('INFO', 'cadastro_realizado', [
        'usuario_id' => $usuarioId
    ]);

    responderJson(201, [
        "sucesso" => true,
        "mensagem" => "Cadastro realizado com sucesso."
    ]);

} catch (Throwable $erro) {

    /*
     * Fecha os recursos, caso ainda estejam abertos.
     */

    if ($stmt instanceof mysqli_stmt) {
        try {
            $stmt->close();
        } catch (Throwable $ignorado) {
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $ignorado) {
        }
    }

    /*
     * Trata especificamente o e-mail duplicado.
     */

    if (
        $erro instanceof mysqli_sql_exception &&
        (int) $erro->getCode() === 1062
    ) {
        registrarLog('WARNING', 'cadastro_email_duplicado');
        responderJson(409, [
            "sucesso" => false,
            "mensagem" => "Este e-mail já está cadastrado."
        ]);
    }

    /*
     * Registra erros inesperados.
     */

    registrarLog('ERROR', 'cadastro_falhou', [
        'tipo' => get_class($erro),
        'codigo' => (int) $erro->getCode()
    ]);

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Erro ao realizar o cadastro."
    ]);
}
