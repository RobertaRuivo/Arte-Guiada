<?php
declare(strict_types=1);

/**
 * Registra eventos da aplicação em logs/app.log, sem expor detalhes ao cliente.
 * O contexto deve conter apenas identificadores e dados não sensíveis.
 */
function registrarLog(string $nivel, string $evento, array $contexto = []): void
{
    $nivel = strtoupper(trim($nivel));
    $evento = trim($evento);
    if ($evento === '') {
        return;
    }

    $contextoSeguro = [];
    foreach ($contexto as $chave => $valor) {
        $chave = (string) $chave;
        if (preg_match('/pass(word)?|senha|token|secret|authorization|cookie/i', $chave) === 1) {
            continue;
        }
        if (is_scalar($valor) || $valor === null) {
            $contextoSeguro[$chave] = is_string($valor)
                ? preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $valor)
                : $valor;
        }
    }

    $registro = [
        'timestamp' => date(DATE_ATOM),
        'nivel' => $nivel,
        'evento' => preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $evento),
        'contexto' => $contextoSeguro
    ];

    $linha = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($linha)) {
        error_log('[Arte Guiada] Falha ao serializar evento de log.');
        return;
    }

    $diretorio = dirname(__DIR__) . '/logs';
    if (!is_dir($diretorio) && !@mkdir($diretorio, 0750, true) && !is_dir($diretorio)) {
        error_log('[Arte Guiada] ' . $linha);
        return;
    }

    $gravado = @file_put_contents($diretorio . '/app.log', $linha . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($gravado === false) {
        error_log('[Arte Guiada] ' . $linha);
    }
}
