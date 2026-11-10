<?php
declare(strict_types=1);

function normalizarOrigemCsrf(string $url): ?string
{
    $partes = parse_url($url);

    if (
        !is_array($partes) ||
        empty($partes['scheme']) ||
        empty($partes['host'])
    ) {
        return null;
    }

    $esquema = strtolower((string) $partes['scheme']);

    if (!in_array($esquema, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower((string) $partes['host']);
    $porta = isset($partes['port'])
        ? (int) $partes['port']
        : null;

    if (
        ($esquema === 'http' && $porta === 80) ||
        ($esquema === 'https' && $porta === 443)
    ) {
        $porta = null;
    }

    return $esquema . '://' . $host
        . ($porta === null ? '' : ':' . $porta);
}

function bloquearCsrf(): never
{
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Origem da requisição não autorizada.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

function exigirOrigemConfiavelCsrf(): void
{
    $metodo = strtoupper(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

    if (!in_array($metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $httpsAtivo = !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    $esquemaServidor = $httpsAtivo ? 'https' : 'http';
    $hostServidor = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    $origemEsperada = normalizarOrigemCsrf(
        $esquemaServidor . '://' . $hostServidor
    );

    $fetchSite = strtolower(
        trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))
    );

    if ($fetchSite === 'cross-site') {
        bloquearCsrf();
    }

    $origem = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    if ($origem !== '') {
        $partesOrigem = parse_url($origem);

        if (
            !is_array($partesOrigem) ||
            isset($partesOrigem['user']) ||
            isset($partesOrigem['pass']) ||
            isset($partesOrigem['query']) ||
            isset($partesOrigem['fragment']) ||
            (
                isset($partesOrigem['path']) &&
                !in_array($partesOrigem['path'], ['', '/'], true)
            )
        ) {
            bloquearCsrf();
        }

        $origemRecebida = normalizarOrigemCsrf($origem);

    } else {
        $referer = trim(
            (string) ($_SERVER['HTTP_REFERER'] ?? '')
        );

        if ($referer !== '') {
            $origemRecebida = normalizarOrigemCsrf($referer);
        } elseif ($fetchSite === 'same-origin') {
            $origemRecebida = $origemEsperada;
        } else {
            $origemRecebida = null;
        }
    }

    if (
        $origemEsperada === null ||
        $origemRecebida === null ||
        !hash_equals($origemEsperada, $origemRecebida)
    ) {
        bloquearCsrf();
    }
}