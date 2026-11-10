<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
ag_carregar_env(dirname(__DIR__) . '/.env');

if (!extension_loaded('mysqli')) {
    throw new RuntimeException('A extensão mysqli do PHP não está habilitada.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST');
$host = is_string($host) && $host !== '' ? $host : '127.0.0.1';

$usuario = getenv('DB_USER');
if (!is_string($usuario) || trim($usuario) === '') {
    throw new RuntimeException('Configure DB_USER no arquivo .env ou no ambiente do servidor.');
}

$senha = getenv('DB_PASSWORD');
$senha = $senha === false ? '' : $senha;

$portaBruta = getenv('DB_PORT');
$porta = $portaBruta === false || $portaBruta === ''
    ? 3306
    : filter_var($portaBruta, FILTER_VALIDATE_INT);

$banco = getenv('ARTE_GUIADA_DB_NAME');
if ($banco === false || $banco === '') {
    $banco = getenv('DB_NAME');
}
if (!is_string($banco) || trim($banco) === '') {
    throw new RuntimeException('Configure DB_NAME no arquivo .env ou no ambiente do servidor.');
}

if ($porta === false || $porta < 1 || $porta > 65535) {
    throw new RuntimeException('A porta configurada para o banco de dados é inválida.');
}

if (preg_match('/\A[a-zA-Z0-9_]+\z/', $banco) !== 1) {
    throw new RuntimeException('O nome configurado para o banco de dados é inválido.');
}

$conn = new mysqli($host, $usuario, $senha, $banco, (int) $porta);
$conn->set_charset('utf8mb4');
