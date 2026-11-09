-- Notificações internas enviadas ao editor quando o administrador
-- altera ou exclui logicamente uma obra de sua autoria.
-- Executar primeiro no banco arte_guiada_test.

CREATE TABLE IF NOT EXISTS notificacoes_editor (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    obra_id INT NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    mensagem VARCHAR(500) NOT NULL,
    criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lida_em DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_notificacoes_editor_usuario_lida_data
        (usuario_id, lida_em, criada_em),
    KEY idx_notificacoes_editor_obra (obra_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
