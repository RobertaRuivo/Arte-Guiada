<?php
declare(strict_types=1);

/*
 * Criação protegida do primeiro administrador.
 *
 * Uso:
 * php scripts/criar-admin-inicial.php
 *
 * O instalador:
 * - só pode ser executado pela CLI;
 * - utiliza as credenciais do .env;
 * - não cria outro administrador se já existir um;
 * - não modifica contas existentes;
 * - armazena somente o hash da senha.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/banco.php';

$nomeBloqueio = 'arte_guiada_admin_bootstrap';
$codigoSaida = 0;
$bloqueioObtido = false;
$transacaoAberta = false;

try {
    $resultadoBloqueio = $conn->query(
        "SELECT GET_LOCK('arte_guiada_admin_bootstrap', 10) AS obtido"
    );

    $bloqueio = $resultadoBloqueio->fetch_assoc();
    $resultadoBloqueio->free();

    if ((int) ($bloqueio['obtido'] ?? 0) !== 1) {
        throw new RuntimeException(
            'Não foi possível obter o bloqueio da instalação.'
        );
    }

    $bloqueioObtido = true;

    $conn->begin_transaction();
    $transacaoAberta = true;

    // Não permite recriar ou substituir um administrador existente.
    $resultadoAdmin = $conn->query(
        "SELECT id
         FROM usuarios
         WHERE LOWER(perfil) IN ('admin', 'administrador')
         LIMIT 1"
    );

    $adminExistente = $resultadoAdmin->fetch_assoc();
    $resultadoAdmin->free();

    if ($adminExistente !== null) {
        $conn->commit();
        $transacaoAberta = false;

        echo "Já existe um administrador. Nenhuma conta foi alterada."
            . PHP_EOL;
    } else {
        $nome = trim((string) (getenv('ADMIN_BOOTSTRAP_NOME') ?: ''));
        $sobrenome = trim(
            (string) (getenv('ADMIN_BOOTSTRAP_SOBRENOME') ?: '')
        );
        $email = strtolower(
            trim((string) (getenv('ADMIN_BOOTSTRAP_EMAIL') ?: ''))
        );

        $senha = getenv('ADMIN_BOOTSTRAP_SENHA');

        if (!is_string($senha) || $senha === '') {
            throw new RuntimeException(
                'Configure ADMIN_BOOTSTRAP_SENHA no arquivo .env.'
            );
        }

        $tamanho = static function (string $valor): int {
            return function_exists('mb_strlen')
                ? mb_strlen($valor, 'UTF-8')
                : strlen($valor);
        };

        if ($nome === '' || $sobrenome === '') {
            throw new RuntimeException(
                'Configure o nome e o sobrenome do administrador.'
            );
        }

        if (
            $tamanho($nome) > 100 ||
            $tamanho($sobrenome) > 100
        ) {
            throw new RuntimeException(
                'Nome ou sobrenome excede o limite de 100 caracteres.'
            );
        }

        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            $tamanho($email) > 150
        ) {
            throw new RuntimeException(
                'O e-mail administrativo é inválido.'
            );
        }

        if (strlen($senha) < 12) {
            throw new RuntimeException(
                'A senha inicial deve possuir pelo menos 12 caracteres.'
            );
        }

        // Não promove uma conta visitante ou editor já existente.
        $stmtEmail = $conn->prepare(
            'SELECT id FROM usuarios WHERE email = ? LIMIT 1'
        );

        $stmtEmail->bind_param('s', $email);
        $stmtEmail->execute();

        $usuarioExistente = $stmtEmail->get_result()->fetch_assoc();
        $stmtEmail->close();

        if ($usuarioExistente !== null) {
            throw new RuntimeException(
                'O e-mail informado já pertence a uma conta. '
                . 'Nenhum perfil foi alterado.'
            );
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        if ($senhaHash === false) {
            throw new RuntimeException(
                'Não foi possível gerar o hash da senha.'
            );
        }

        $mensagem = 'Administrador inicial criado durante a instalação.';

        $stmt = $conn->prepare(
            "INSERT INTO usuarios
                (nome, sobrenome, email, senha, perfil, mensagem)
             VALUES (?, ?, ?, ?, 'admin', ?)"
        );

        $stmt->bind_param(
            'sssss',
            $nome,
            $sobrenome,
            $email,
            $senhaHash,
            $mensagem
        );

        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        $conn->commit();
        $transacaoAberta = false;

        echo "Administrador inicial criado com sucesso."
            . PHP_EOL;
        echo "ID: {$id}" . PHP_EOL;
        echo "Perfil: admin" . PHP_EOL;
        echo "E-mail: {$email}" . PHP_EOL;
        echo "Senha: armazenada com hash." . PHP_EOL;
    }
} catch (Throwable $erro) {
    if ($transacaoAberta) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            // Preserva a mensagem da falha original.
        }
    }

    fwrite(
        STDERR,
        'Falha ao criar administrador: ' . $erro->getMessage() . PHP_EOL
    );

    $codigoSaida = 1;
}

if ($bloqueioObtido) {
    try {
        $conn->query(
            "SELECT RELEASE_LOCK('arte_guiada_admin_bootstrap')"
        );
    } catch (Throwable $erroLiberacao) {
        // O fechamento da conexão também libera o bloqueio.
    }
}

$conn->close();

exit($codigoSaida);