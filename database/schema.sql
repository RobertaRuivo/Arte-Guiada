-- ============================================================================
-- ARTE GUIADA | Esquema completo para instalação limpa
-- MySQL 8.0+ / MariaDB 10.5+ | InnoDB | utf8mb4
--
-- Crie e selecione o banco antes de importar este arquivo.
-- Exemplo:
--   CREATE DATABASE arte_guiada
--     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   USE arte_guiada;
--
-- Para PHPUnit, crie arte_guiada_test e importe exatamente este mesmo arquivo.
-- Este script cria estrutura, não usuários, obras ou dados pessoais.
-- NÃO importe sobre uma base que já contenha dados.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Contas e autenticação
CREATE TABLE usuarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    sobrenome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    perfil VARCHAR(50) NOT NULL DEFAULT 'visitante',
    mensagem TEXT DEFAULT NULL,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tokens_recuperacao (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    utilizado_em DATETIME DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokens_recuperacao_hash (token_hash),
    KEY idx_tokens_recuperacao_usuario (usuario_id, utilizado_em),
    KEY idx_tokens_recuperacao_expiracao (expira_em, utilizado_em),
    CONSTRAINT fk_tokens_recuperacao_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Obras e etapas de exploração
CREATE TABLE obras (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(200) DEFAULT NULL,
    autor VARCHAR(200) DEFAULT NULL,
    imagem MEDIUMBLOB DEFAULT NULL,
    imagem_mime VARCHAR(100) DEFAULT NULL,
    texto_alternativo VARCHAR(255) DEFAULT NULL,
    status ENUM('rascunho', 'publicada') NOT NULL DEFAULT 'rascunho',
    destaque TINYINT(1) NOT NULL DEFAULT 0,
    ordem_destaque INT DEFAULT NULL,
    criada_por INT UNSIGNED NOT NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    publicada_em DATETIME DEFAULT NULL,
    excluida_em DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_obras_status (status, excluida_em),
    KEY idx_obras_criador (criada_por, status, excluida_em),
    KEY idx_obras_destaques (destaque, ordem_destaque),
    CONSTRAINT fk_obras_criador
        FOREIGN KEY (criada_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE etapas_exploracao (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    obra_id INT UNSIGNED NOT NULL,
    ordem INT NOT NULL,
    titulo VARCHAR(150) DEFAULT NULL,
    texto LONGTEXT DEFAULT NULL,
    zoom_x DECIMAL(8,6) DEFAULT NULL,
    zoom_y DECIMAL(8,6) DEFAULT NULL,
    zoom_largura DECIMAL(8,6) DEFAULT NULL,
    zoom_altura DECIMAL(8,6) DEFAULT NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_etapa_obra_ordem (obra_id, ordem),
    CONSTRAINT fk_etapas_exploracao_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Biblioteca pessoal
CREATE TABLE biblioteca_usuario (
    usuario_id INT UNSIGNED NOT NULL,
    obra_id INT UNSIGNED NOT NULL,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    quero_explorar TINYINT(1) NOT NULL DEFAULT 0,
    explorada_em DATETIME DEFAULT NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, obra_id),
    KEY idx_biblioteca_usuario_favoritos (usuario_id, favorito),
    KEY idx_biblioteca_usuario_planejadas (usuario_id, quero_explorar),
    KEY idx_biblioteca_usuario_exploradas (usuario_id, explorada_em),
    CONSTRAINT fk_biblioteca_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_biblioteca_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Relatos de problemas e fluxo de verificação administrativa
-- Estados usados pela aplicação: pendente, em_analise, atribuido,
-- em_correcao, aguardando_verificacao, reaberto, resolvido, improcedente.
CREATE TABLE relatos_problema (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED DEFAULT NULL,
    obra_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    descricao TEXT NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pendente',
    prioridade VARCHAR(20) NOT NULL DEFAULT 'media',
    editor_responsavel_id INT UNSIGNED DEFAULT NULL,
    resposta_editor TEXT DEFAULT NULL,
    corrigido_em DATETIME DEFAULT NULL,
    verificado_por INT UNSIGNED DEFAULT NULL,
    verificado_em DATETIME DEFAULT NULL,
    enviado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_relatos_status_data (status, enviado_em),
    KEY idx_relatos_prioridade_data (prioridade, enviado_em),
    KEY idx_relatos_obra (obra_id, enviado_em),
    KEY idx_relatos_usuario (usuario_id),
    KEY idx_relatos_editor_status (editor_responsavel_id, status),
    KEY idx_relatos_verificador (verificado_por),
    CONSTRAINT fk_relatos_problema_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_relatos_problema_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_relatos_editor_responsavel
        FOREIGN KEY (editor_responsavel_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_relatos_verificador
        FOREIGN KEY (verificado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relatos_problema_tipos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    relato_id INT UNSIGNED NOT NULL,
    tipo ENUM(
        'imagem', 'informacoes', 'texto', 'etapa_zoom',
        'navegacao', 'acessibilidade', 'outro'
    ) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_relato_problema_tipo (relato_id, tipo),
    KEY idx_relato_problema_tipo (tipo),
    CONSTRAINT fk_relato_tipo_relato
        FOREIGN KEY (relato_id) REFERENCES relatos_problema(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relatos_problema_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    relato_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED DEFAULT NULL,
    acao VARCHAR(80) NOT NULL,
    status_anterior VARCHAR(40) DEFAULT NULL,
    status_novo VARCHAR(40) DEFAULT NULL,
    prioridade_anterior VARCHAR(20) DEFAULT NULL,
    prioridade_nova VARCHAR(20) DEFAULT NULL,
    editor_anterior_id INT UNSIGNED DEFAULT NULL,
    editor_novo_id INT UNSIGNED DEFAULT NULL,
    observacao TEXT DEFAULT NULL,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_relato_eventos_data (relato_id, id),
    KEY idx_relato_eventos_usuario (usuario_id, criada_em),
    KEY idx_relato_eventos_editores (editor_anterior_id, editor_novo_id),
    CONSTRAINT fk_relato_evento_relato
        FOREIGN KEY (relato_id) REFERENCES relatos_problema(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_relato_evento_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_relato_evento_editor_anterior
        FOREIGN KEY (editor_anterior_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_relato_evento_editor_novo
        FOREIGN KEY (editor_novo_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Acesso delegado: a autoria original continua em obras.criada_por.
CREATE TABLE obras_editores_acesso (
    obra_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    concedido_por INT UNSIGNED DEFAULT NULL,
    motivo VARCHAR(1000) DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (obra_id, usuario_id),
    KEY idx_obras_editores_usuario_ativo (usuario_id, ativo),
    KEY idx_obras_editores_principal (obra_id, principal, ativo),
    CONSTRAINT fk_obras_editores_acesso_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_obras_editores_acesso_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_obras_editores_acesso_concedido_por
        FOREIGN KEY (concedido_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Notificações internas
CREATE TABLE notificacoes_editor (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    obra_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    mensagem VARCHAR(500) NOT NULL,
    criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lida_em DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_notificacoes_editor_usuario_lida_data (usuario_id, lida_em, criada_em),
    KEY idx_notificacoes_editor_obra (obra_id),
    CONSTRAINT fk_notificacoes_editor_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notificacoes_editor_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificacoes_admin (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    relato_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    mensagem VARCHAR(500) NOT NULL,
    criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lida_em DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_notificacoes_admin_usuario_lida_data (usuario_id, lida_em, criada_em),
    KEY idx_notificacoes_admin_relato (relato_id),
    CONSTRAINT fk_notificacoes_admin_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notificacoes_admin_relato
        FOREIGN KEY (relato_id) REFERENCES relatos_problema(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Auditoria de gerenciamento de editores. Identificadores ficam nulos
-- quando as contas relacionadas deixam de existir, preservando o registro.
CREATE TABLE gerenciamento_editores_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    administrador_id INT UNSIGNED DEFAULT NULL,
    usuario_id INT UNSIGNED DEFAULT NULL,
    perfil_anterior VARCHAR(50) DEFAULT NULL,
    perfil_novo VARCHAR(50) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gerenciamento_editores_usuario (usuario_id, criado_em),
    KEY idx_gerenciamento_editores_admin (administrador_id, criado_em),
    CONSTRAINT fk_gerenciamento_editores_admin
        FOREIGN KEY (administrador_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_gerenciamento_editores_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fim do esquema. O cadastro público sempre cria perfil visitante.
-- Cadastre a conta inicial e promova-a manualmente para admin.
