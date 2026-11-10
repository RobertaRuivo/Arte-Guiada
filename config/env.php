<?php
declare(strict_types=1);

/**
 * Carrega variáveis de um arquivo .env simples sem sobrescrever variáveis
 * já definidas no processo (por exemplo, as injetadas pelo PHPUnit).
 */
function ag_carregar_env(string $caminho): void
{
    if (!is_file($caminho) || !is_readable($caminho)) {
        return;
    }

    $linhas = file($caminho, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) {
        throw new RuntimeException('Não foi possível ler o arquivo de ambiente.');
    }

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        if (str_starts_with($linha, 'export ')) {
            $linha = trim(substr($linha, 7));
        }

        $separador = strpos($linha, '=');
        if ($separador === false) {
            continue;
        }

        $nome = trim(substr($linha, 0, $separador));
        $valor = trim(substr($linha, $separador + 1));

        if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $nome) !== 1) {
            continue;
        }

        if (strlen($valor) >= 2) {
            $primeiro = $valor[0];
            $ultimo = $valor[strlen($valor) - 1];
            if (($primeiro === '"' && $ultimo === '"') || ($primeiro === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }

        if (getenv($nome) === false) {
            putenv($nome . '=' . $valor);
            $_ENV[$nome] = $valor;
        }
    }
}
