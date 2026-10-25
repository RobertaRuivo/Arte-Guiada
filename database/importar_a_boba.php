<?php
declare(strict_types=1);

/**
 * Importa a obra estática "A Boba" da antiga galeria para as tabelas obras
 * e etapas_exploracao. Execute somente pela linha de comando, uma vez.
 *
 * Uso na raiz do projeto:
 *   C:\\xampp\\php\\php.exe database/importar_a_boba.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Não encontrado.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../config/banco.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Erro: config/banco.php não disponibilizou a conexão mysqli em \$conn.\n");
    exit(1);
}

$conn->set_charset('utf8mb4');

$titulo = 'A Boba';
$autor = 'Anita Malfatti';
$emailEditor = 'robertarupompeu@gmail.com';
$caminhoImagem = __DIR__ . '/../public/img/a_boba.jpg';
$textoAlternativo = 'A Boba, de Anita Malfatti, representada em uma composição de cores intensas.';

$etapas = [
    [
        'ordem' => 1,
        'titulo' => 'O rosto',
        'texto' => 'Podemos observar o rosto da jovem como um dos elementos de maior destaque da obra. Sua expressão transmite uma sensação de introspecção e, ao mesmo tempo, certo estranhamento. Isso é reforçado pelas formas marcadas do rosto, pelo olhar e pelo uso de cores intensas. A maneira como Anita Malfatti representa a figura não busca reproduzir fielmente a aparência de uma pessoa. As formas são simplificadas e expressivas, enquanto as cores e as pinceladas ajudam a transmitir uma sensação e não apenas a aparência física da personagem. Essas características revelam a influência do expressionismo, com o qual Malfatti teve contato durante seus estudos no exterior. A influência estrangeira, porém, não significa uma simples reprodução do que era produzido na Europa. A experiência de Malfatti contribuiu para que ela desenvolvesse uma linguagem própria e participasse do processo de renovação da arte brasileira que marcou o modernismo.'
    ],
    [
        'ordem' => 2,
        'titulo' => 'A assinatura',
        'texto' => 'No canto da tela, encontramos também a assinatura da artista. Ela aparece de forma discreta, em letra cursiva, integrada à composição sem chamar mais atenção do que os elementos principais da pintura.'
    ]
];

$mimePermitidos = ['image/jpeg', 'image/png', 'image/webp'];

try {
    if (!is_file($caminhoImagem) || !is_readable($caminhoImagem)) {
        throw new RuntimeException('A imagem public/img/a_boba.jpg não foi encontrada ou não pode ser lida.');
    }

    $tamanhoImagem = filesize($caminhoImagem);
    if ($tamanhoImagem === false || $tamanhoImagem <= 0 || $tamanhoImagem > 16777215) {
        throw new RuntimeException('A imagem está vazia ou excede o limite do campo MEDIUMBLOB (16 MB).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($caminhoImagem);
    if (!is_string($mime) || !in_array($mime, $mimePermitidos, true)) {
        throw new RuntimeException('O arquivo da imagem não está em um formato aceito (JPEG, PNG ou WebP).');
    }

    $imagem = file_get_contents($caminhoImagem);
    if ($imagem === false) {
        throw new RuntimeException('Não foi possível ler o conteúdo da imagem.');
    }

    // Busca a conta responsável pelo cadastro. Nenhum usuário é criado ou alterado.
    $stmtUsuario = $conn->prepare(
        'SELECT id FROM usuarios WHERE email = ? AND LOWER(perfil) = ? LIMIT 1'
    );
    $perfilEditor = 'editor';
    $stmtUsuario->bind_param('ss', $emailEditor, $perfilEditor);
    $stmtUsuario->execute();
    $usuario = $stmtUsuario->get_result()->fetch_assoc();
    $stmtUsuario->close();

    if (!$usuario) {
        throw new RuntimeException('Não encontrei uma conta com o e-mail configurado e perfil Editor. Confira o e-mail em database/importar_a_boba.php.');
    }
    $criadaPor = (int) $usuario['id'];

    // Não cria outra cópia caso exista uma obra com o mesmo título e autor,
    // inclusive se ela tiver sido marcada como excluída.
    $stmtExistente = $conn->prepare(
        'SELECT id, status, excluida_em FROM obras WHERE LOWER(titulo) = LOWER(?) AND LOWER(autor) = LOWER(?) LIMIT 1'
    );
    $stmtExistente->bind_param('ss', $titulo, $autor);
    $stmtExistente->execute();
    $existente = $stmtExistente->get_result()->fetch_assoc();
    $stmtExistente->close();

    if ($existente) {
        $estado = $existente['excluida_em'] !== null ? 'marcada como excluída' : 'já cadastrada';
        fwrite(STDOUT, sprintf(
            "Nenhuma alteração realizada: a obra %s (ID %d, status %s). Não foi criada duplicata.\n",
            $estado,
            (int) $existente['id'],
            (string) $existente['status']
        ));
        exit(0);
    }

    $conn->begin_transaction();

    $status = 'publicada';
    $destaque = 0;
    $stmtObra = $conn->prepare(
        "INSERT INTO obras (titulo, autor, imagem, imagem_mime, texto_alternativo, status, destaque, criada_por, publicada_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmtObra->bind_param(
        'ssbsssii',
        $titulo,
        $autor,
        $imagem,
        $mime,
        $textoAlternativo,
        $status,
        $destaque,
        $criadaPor
    );
    $stmtObra->send_long_data(2, $imagem);
    $stmtObra->execute();
    $obraId = (int) $conn->insert_id;
    $stmtObra->close();

    // A antiga galeria usa focusX/focusY/zoom, não retângulos normalizados.
    // Por isso as coordenadas retangulares ficam NULL, em vez de inventar valores.
    $stmtEtapa = $conn->prepare(
        'INSERT INTO etapas_exploracao (obra_id, ordem, titulo, texto, zoom_x, zoom_y, zoom_largura, zoom_altura)
         VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL)'
    );

    foreach ($etapas as $etapa) {
        $ordem = (int) $etapa['ordem'];
        $tituloEtapa = $etapa['titulo'];
        $textoEtapa = $etapa['texto'];
        $stmtEtapa->bind_param('iiss', $obraId, $ordem, $tituloEtapa, $textoEtapa);
        $stmtEtapa->execute();
    }
    $stmtEtapa->close();

    $conn->commit();

    fwrite(STDOUT, "Obra importada com sucesso.\n");
    fwrite(STDOUT, "ID da obra: {$obraId}\n");
    fwrite(STDOUT, "Etapas cadastradas: " . count($etapas) . "\n");
    fwrite(STDOUT, "Imagem armazenada no banco: sim ({$mime}).\n");
    fwrite(STDOUT, "As coordenadas de zoom ficaram NULL porque o formato antigo da galeria não usa o retângulo do editor.\n");
} catch (Throwable $erro) {
    if ($conn->errno === 0) {
        // A transação pode ainda não ter sido iniciada; rollback é tolerado pelo mysqli.
    }
    try {
        $conn->rollback();
    } catch (Throwable $ignorado) {
        // Não havia transação ativa para reverter.
    }

    fwrite(STDERR, "Falha ao importar A Boba: " . $erro->getMessage() . "\n");
    exit(1);
} finally {
    $conn->close();
}
