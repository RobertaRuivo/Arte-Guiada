<?php
declare(strict_types=1);

$caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$caminho = is_string($caminho) ? rawurldecode($caminho) : '/';

if ($caminho === '/router.php') {
    http_response_code(404);
    exit;
}

if (str_contains($caminho, "\0") || str_contains($caminho, '\\')) {
    http_response_code(400);
    exit;
}

$segmentos = array_values(array_filter(explode('/', $caminho), static fn(string $parte): bool => $parte !== ''));
foreach ($segmentos as $segmento) {
    if ($segmento === '.' || $segmento === '..' || str_starts_with($segmento, '.')) {
        http_response_code(404);
        exit;
    }
}

$primeiro = strtolower($segmentos[0] ?? '');
$diretoriosPrivados = ['config', 'database', 'docs', 'logs', 'tests', 'vendor', '.git'];
if (in_array($primeiro, $diretoriosPrivados, true)) {
    http_response_code(404);
    exit;
}

$arquivo = __DIR__ . $caminho;
if (is_file($arquivo)) {
    return false;
}

// Deixa o servidor embutido resolver diretórios e a página inicial.
return false;
