<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

header("Content-Type: application/json; charset=UTF-8");

if (
    !isset($_SESSION["usuario_id"]) ||
    !isset($_SESSION["perfil"])
) {
    http_response_code(401);

    echo json_encode([
        "autenticado" => false,
        "mensagem" => "Usuário não autenticado."
    ]);

    exit;
}

echo json_encode([
    "autenticado" => true,
    "usuario" => [
        "id" => $_SESSION["usuario_id"],
        "nome" => $_SESSION["nome"] ?? "",
        "perfil" => $_SESSION["perfil"]
    ]
]);