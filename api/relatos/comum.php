<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * Funções compartilhadas pelas APIs de relatos.
 */

/*
 * Envia uma resposta JSON e encerra a execução.
 *
 * @param int $status Código HTTP.
 * @param array $dados Dados da resposta.
 */
function responderRelato(int $status, array $dados): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $json = json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);

        $json = '{"sucesso":false,"mensagem":"Erro ao gerar a resposta do servidor."}';
    }

    echo $json;
    exit;
}

/*
 * Recebe e interpreta os dados JSON enviados pelo JavaScript.
 *
 * @return array Dados recebidos.
 */
function receberJsonRelato(): array
{
    $corpo = file_get_contents('php://input');

    if ($corpo === false || trim($corpo) === '') {
        responderRelato(400, [
            'sucesso' => false,
            'mensagem' => 'Nenhum dado foi recebido.'
        ]);
    }

    try {
        $dados = json_decode(
            $corpo,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $erro) {
        responderRelato(400, [
            'sucesso' => false,
            'mensagem' => 'Os dados enviados possuem um formato inválido.'
        ]);
    }

    if (!is_array($dados)) {
        responderRelato(400, [
            'sucesso' => false,
            'mensagem' => 'O conteúdo enviado é inválido.'
        ]);
    }

    return $dados;
}

/*
 * Identifica o usuário autenticado pela sessão.
 *
 * Retorna:
 * - O ID do usuário, quando autenticado.
 * - NULL, quando o visitante é anônimo.
 *
 * Observação:
 * Os nomes das chaves precisam corresponder
 * aos utilizados pelo sistema de autenticação.
 */
function usuarioRelatoAtual(): ?int
{
    $possiveisIdentificadores = [
        $_SESSION['usuario_id'] ?? null,
        $_SESSION['id_usuario'] ?? null,
        $_SESSION['usuario']['id'] ?? null,
        $_SESSION['usuario']['id_usuario'] ?? null,
        $_SESSION['user']['id'] ?? null
    ];

    foreach ($possiveisIdentificadores as $identificador) {

        if (
            is_int($identificador) ||
            (is_string($identificador) && ctype_digit($identificador))
        ) {
            $id = (int) $identificador;

            if ($id > 0) {
                return $id;
            }
        }
    }

    return null;
}

/*
 * Configura e inicia a sessão caso ainda não esteja ativa.
 *
 * As configurações são alinhadas às utilizadas
 * pelo endpoint de autenticação.
 */
if (session_status() === PHP_SESSION_NONE) {

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
            throw new RuntimeException(
                "Falha ao iniciar sessão."
            );
        }

    } catch (Throwable $erro) {

        error_log(
            sprintf(
                "[Arte Guiada][relatos] %s Falha ao iniciar sessão: %s",
                date(DATE_ATOM),
                $erro->getMessage()
            )
        );

        responderRelato(500, [
            'sucesso' => false,
            'mensagem' => 'Não foi possível iniciar a sessão.'
        ]);
    }
}
