-- ============================================================
-- Arte Guiada - Script de instalação do banco de dados
-- MariaDB/MySQL compatível com XAMPP
-- Cria a estrutura do banco sem inserir dados pessoais ou reais.
-- Execute em uma instalação nova ou em um banco vazio.
-- ============================================================

CREATE DATABASE IF NOT EXISTS arte_guiada
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE arte_guiada;

-- ------------------------------------------------------------
-- Usuários
-- Tabela-base: deve ser criada antes das tabelas dependentes.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    sobrenome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    perfil VARCHAR(50) DEFAULT NULL,
    mensagem TEXT DEFAULT NULL,
    data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Obras dinâmicas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS obras (
    id INT NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(200) DEFAULT NULL,
    autor VARCHAR(200) DEFAULT NULL,
    imagem MEDIUMBLOB DEFAULT NULL,
    imagem_mime VARCHAR(100) DEFAULT NULL,
    texto_alternativo VARCHAR(255) DEFAULT NULL,
    status ENUM('rascunho', 'publicada') NOT NULL DEFAULT 'rascunho',
    destaque TINYINT(1) NOT NULL DEFAULT 0,
    ordem_destaque INT DEFAULT NULL,
    criada_por INT NOT NULL,
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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Convites para editores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS convites_editor (
    id INT NOT NULL AUTO_INCREMENT,
    email_autorizado VARCHAR(150) NOT NULL,
    chave_hash CHAR(64) NOT NULL,
    criado_por INT NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME DEFAULT NULL,
    utilizado_em DATETIME DEFAULT NULL,
    utilizado_por INT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_convites_chave_hash (chave_hash),
    KEY idx_convites_email (email_autorizado),
    KEY idx_convites_estado (utilizado_em, expira_em),
    CONSTRAINT fk_convites_editor_criador
        FOREIGN KEY (criado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_convites_editor_utilizado_por
        FOREIGN KEY (utilizado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Etapas da exploração de uma obra
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etapas_exploracao (
    id INT NOT NULL AUTO_INCREMENT,
    obra_id INT NOT NULL,
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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Biblioteca pessoal dos usuários
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS biblioteca_usuario (
    usuario_id INT NOT NULL,
    obra_id INT NOT NULL,
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
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_biblioteca_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Relatos de problemas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS relatos_problema (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    obra_id INT NOT NULL,
    tipo VARCHAR(80) NOT NULL,
    descricao TEXT NOT NULL,
    status ENUM('pendente', 'em_analise', 'resolvido') NOT NULL DEFAULT 'pendente',
    enviado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    atendido_por INT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_relatos_status_data (status, enviado_em),
    KEY idx_relatos_obra (obra_id, enviado_em),
    KEY idx_relatos_usuario (usuario_id),
    KEY idx_relatos_atendido_por (atendido_por),
    CONSTRAINT fk_relatos_problema_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_relatos_problema_obra
        FOREIGN KEY (obra_id) REFERENCES obras(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_relatos_problema_atendido_por
        FOREIGN KEY (atendido_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Fim da estrutura. Nenhum registro de usuário, obra ou outro dado
-- foi inserido por este script.
