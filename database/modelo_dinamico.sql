-- Arte Guiada - migração 002
-- Acrescenta suporte a convites de editor, obras dinâmicas,
-- biblioteca pessoal e relatos de problemas.
-- Não remove nem recria a tabela usuarios existente.

CREATE TABLE IF NOT EXISTS convites_editor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_autorizado VARCHAR(150) NOT NULL,
    chave_hash CHAR(64) NOT NULL UNIQUE,
    criado_por INT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NULL,
    utilizado_em DATETIME NULL,
    utilizado_por INT NULL,
    INDEX idx_convites_email (email_autorizado),
    INDEX idx_convites_estado (utilizado_em, expira_em),
    CONSTRAINT fk_convites_editor_criador
        FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_convites_editor_utilizado_por
        FOREIGN KEY (utilizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(200) NULL,
    autor VARCHAR(200) NULL,
    imagem MEDIUMBLOB NULL,
    imagem_mime VARCHAR(100) NULL,
    texto_alternativo VARCHAR(255) NULL,
    status ENUM('rascunho', 'publicada') NOT NULL DEFAULT 'rascunho',
    destaque TINYINT(1) NOT NULL DEFAULT 0,
    ordem_destaque INT NULL,
    criada_por INT NOT NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    publicada_em DATETIME NULL,
    excluida_em DATETIME NULL,
    INDEX idx_obras_status (status, excluida_em),
    INDEX idx_obras_criador (criada_por, status, excluida_em),
    INDEX idx_obras_destaques (destaque, ordem_destaque),
    CONSTRAINT fk_obras_criador
        FOREIGN KEY (criada_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etapas_exploracao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obra_id INT NOT NULL,
    ordem INT NOT NULL,
    titulo VARCHAR(150) NULL,
    texto LONGTEXT NULL,
    zoom_x DECIMAL(8,6) NULL,
    zoom_y DECIMAL(8,6) NULL,
    zoom_largura DECIMAL(8,6) NULL,
    zoom_altura DECIMAL(8,6) NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_etapa_obra_ordem (obra_id, ordem),
    CONSTRAINT fk_etapas_exploracao_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biblioteca_usuario (
    usuario_id INT NOT NULL,
    obra_id INT NOT NULL,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    quero_explorar TINYINT(1) NOT NULL DEFAULT 0,
    explorada_em DATETIME NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, obra_id),
    INDEX idx_biblioteca_usuario_favoritos (usuario_id, favorito),
    INDEX idx_biblioteca_usuario_planejadas (usuario_id, quero_explorar),
    INDEX idx_biblioteca_usuario_exploradas (usuario_id, explorada_em),
    CONSTRAINT fk_biblioteca_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_biblioteca_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS relatos_problema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    obra_id INT NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    descricao TEXT NOT NULL,
    status ENUM('pendente', 'em_analise', 'resolvido') NOT NULL DEFAULT 'pendente',
    enviado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    atendido_por INT NULL,
    CONSTRAINT fk_relatos_problema_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_relatos_problema_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_relatos_problema_atendido_por
        FOREIGN KEY (atendido_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_relatos_status_data (status, enviado_em),
    INDEX idx_relatos_obra (obra_id, enviado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
