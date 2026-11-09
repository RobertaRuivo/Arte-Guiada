<?php
declare(strict_types=1);

/*
 * Endpoint legado desativado para impedir que a antiga tela administrativa
 * encerre relatos sem a etapa de correção pelo editor e verificação final.
 */
require_once __DIR__ . '/../../config/painel-api.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
ag_exigir_perfil(['admin']);
ag_responder_json(410, [
    'sucesso' => false,
    'mensagem' => 'Este fluxo antigo foi substituído. Utilize api/relatos/gerenciar-admin.php.'
]);
