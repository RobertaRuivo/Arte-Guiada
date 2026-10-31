<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * Funções compartilhadas pelas APIs de relatos.
 */

/*
 * Inicia a sessão caso ainda não esteja ativa.
 * Necessário para identificar usuários e controlar
 * a frequência de envio dos relatos.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
