# Arte Guiada

Aplicação web para apresentar obras de arte e orientar a observação por meio de etapas de exploração visual, texto interpretativo e regiões de zoom. O sistema possui catálogo público, galeria, biblioteca pessoal, contas com diferentes permissões, edição de obras, gestão administrativa e fluxo de relatos de problemas.

Projeto desenvolvido para a disciplina de **Sistemas Computacionais I (SCOM) — 2026**.

## Funcionalidades

- **Visitante:** consulta o catálogo, os destaques e as obras publicadas; pode criar uma conta.
- **Usuário autenticado:** organiza a biblioteca com favoritos, lista de obras que deseja explorar e histórico de explorações concluídas.
- **Editor:** cria obras, salva rascunhos, publica obras com etapas e regiões de zoom e exclui logicamente as próprias obras.
- **Acesso delegado:** o administrador pode conceder acesso de edição a outro editor sem trocar a autoria original (`obras.criada_por`). A concessão e a revogação ficam registradas em `obras_editores_acesso`.
- **Administrador:** gerencia obras, lixeira, perfis de editor, destaques e relatos. Pode encaminhar uma obra a outro editor, reforçar orientações e verificar a correção enviada.
- **Relatos:** visitantes podem registrar problemas em obras publicadas, inclusive sem conta. O editor responsável trata o problema e envia a correção para verificação administrativa. O histórico funcional do fluxo fica em `relatos_problema_eventos`; notificações são internas ao sistema.

A página inicial aceita até **três obras em destaque**. O cadastro público sempre cria uma conta `visitante`; o nível de acesso não é recebido do formulário.

## Requisitos

- PHP **8.2 ou superior**.
- MySQL **8.0 ou superior** ou MariaDB **10.5 ou superior**, com InnoDB e `utf8mb4`.
- Extensões PHP: `mysqli`, `mbstring` e `fileinfo`. O driver `mysqlnd` é necessário porque o código usa `mysqli_stmt::get_result()`.
- Apache com `mod_rewrite` e suporte a `.htaccess`, ou o servidor embutido do PHP para desenvolvimento local.
- Composer para instalar o PHPUnit.
- Node.js é opcional e serve apenas à verificação sintática dos arquivos JavaScript.

Abrir `index.html` diretamente pelo navegador não executa as APIs PHP. GitHub Pages também não executa PHP nem hospeda o banco de dados da aplicação.

## Instalação em um banco novo

### 1. Preparar o servidor

Instale PHP e MySQL/MariaDB. Em uma instalação XAMPP, inicie Apache e MySQL e coloque a pasta do projeto na raiz pública do Apache, por exemplo:

```text
C:\xampp\htdocs\Arte-Guiada
```

### 2. Criar o banco

Crie um banco vazio. No cliente MySQL:

```sql
CREATE DATABASE arte_guiada
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Selecione esse banco e importe **somente** `database/schema.sql`:

```bash
mysql -u SEU_USUARIO -p arte_guiada < database/schema.sql
```

Também é possível selecionar `arte_guiada` no phpMyAdmin e importar `database/schema.sql` pela aba **Importar**.

O arquivo cria as **12 tabelas** usadas pelo código atual, com chaves primárias, chaves estrangeiras, restrições únicas e índices. Ele cria a estrutura, não cria contas, obras nem dados de demonstração.

> **Importante:** `schema.sql` é um instalador para banco vazio. Não o execute sobre uma base existente. Para atualizar uma instalação com dados, faça backup e prepare uma migração específica a partir do esquema real dessa base. Não existe neste pacote uma migração automática e validada para bancos antigos.

### 3. Configurar a conexão

Na raiz do projeto, copie o arquivo de exemplo:

**PowerShell:**

```powershell
Copy-Item .env.example .env
```

**Linux/macOS:**

```bash
cp .env.example .env
```

Edite `.env` com os dados do seu MySQL/MariaDB:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=arte_guiada
DB_USER=seu_usuario
DB_PASSWORD=sua_senha
```

`config/banco.php` lê essas variáveis e abre a conexão `mysqli` com `utf8mb4`. Para executar testes, a variável `ARTE_GUIADA_DB_NAME` pode sobrescrever `DB_NAME` no processo correspondente. Não envie o arquivo `.env` ao Git: ele está no `.gitignore`.

### 4. Iniciar a aplicação

Com Apache e MySQL ativos, acesse:

```text
http://localhost/Arte-Guiada/
```

Para desenvolvimento sem Apache, na raiz do projeto:

```bash
php -S 127.0.0.1:8000 router.php
```

Acesse `http://127.0.0.1:8000/`. O `router.php` bloqueia o acesso HTTP a diretórios e arquivos internos, como `config/`, `database/`, `docs/`, `logs/`, `tests/`, `vendor/`, `.env` e `.git`. O `.htaccess` fornece regras equivalentes para Apache com `mod_rewrite` habilitado. O servidor embutido é destinado a desenvolvimento, não à publicação em produção.

### 5. Criar a primeira conta administrativa

Faça um cadastro normal pela interface. O endpoint público fixa o perfil inicial como `visitante`. Depois, promova somente a conta escolhida diretamente no banco:

```sql
UPDATE usuarios
SET perfil = 'admin'
WHERE email = 'seu-email@exemplo.com';
```

Confira se uma linha foi alterada, encerre a sessão e entre novamente. O administrador poderá promover outras contas pela seção **Gerenciar editores**. Não exponha a operação SQL de promoção ao público.

## Como usar os perfis

### Visitante

Consulte o catálogo, os destaques e a galeria. Para organizar obras na biblioteca ou enviar um relato identificado, crie uma conta e entre no sistema. O formulário de relato também aceita envio anônimo.

### Editor

O administrador promove uma conta existente. No **Espaço do editor**, crie uma obra, informe os dados e a descrição alternativa, envie uma imagem e cadastre as etapas. Cada etapa pode ter uma região de zoom. Salve como rascunho durante a edição ou publique quando os requisitos estiverem preenchidos.

O editor pode alterar suas próprias obras. Para editar uma obra criada por outra conta, precisa de concessão ativa de acesso delegado. A autoria original permanece preservada. Obras excluídas logicamente podem ser restauradas pela Lixeira enquanto o usuário tiver autorização.

Quando recebe um relato atribuído, o editor pode iniciar a correção e enviar uma descrição da solução para verificação do administrador.

### Administrador

Na área **Administração**, gerencie obras, restaure itens da Lixeira, promova ou revogue perfis de editor e selecione até três destaques. Na gestão de relatos, atribua ou transfira a responsabilidade para um editor, reforce orientações, aprove ou devolva correções e reabra relatos encerrados. A revogação do perfil Editor é bloqueada enquanto houver relatos ativos atribuídos à conta.

## Importação opcional da obra de demonstração

`database/importar_a_boba.php` é um utilitário de linha de comando. Ele importa a obra **A Boba**, de Anita Malfatti, e suas etapas. Não é executado automaticamente e não cria uma conta de editor.

Pré-requisitos:

- banco já criado com `database/schema.sql`;
- conta com perfil `Editor` já existente;
- arquivo `public/img/a_boba.jpg` presente;
- configurar o e-mail do editor pela variável `ARTE_GUIADA_EDITOR_EMAIL` ou pelo argumento `--editor-email`.

Exemplo no PowerShell, usando deliberadamente um banco de demonstração:

```powershell
$env:ARTE_GUIADA_EDITOR_EMAIL = "editor@exemplo.com"
php .\database\importar_a_boba.php
```

Ou diretamente:

```powershell
php .\database\importar_a_boba.php --editor-email=editor@exemplo.com
```

O script evita cadastrar uma segunda obra com o mesmo título e autor. **Não execute esse importador no banco de produção nem no banco reservado ao PHPUnit.**

## Testes automatizados

A suíte atual contém **81 testes**. O banco usado é exclusivamente `arte_guiada_test`; os testes criam contas e obras temporárias e removem os dados preparados por cada caso.

1. Crie um banco vazio e importe o mesmo esquema:

```sql
CREATE DATABASE arte_guiada_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

```bash
mysql -u SEU_USUARIO -p arte_guiada_test < database/schema.sql
```

2. Instale as dependências na raiz do projeto:

```bash
composer install
```

3. Em um terminal PowerShell, inicie o servidor com o banco de testes:

```powershell
$env:ARTE_GUIADA_DB_NAME = "arte_guiada_test"
php -S 127.0.0.1:8001 router.php
```

Mantenha esse terminal aberto. Em outro terminal, na raiz do projeto, execute:

```powershell
.\vendor\bin\phpunit.bat
```

O `phpunit.xml` também força `ARTE_GUIADA_DB_NAME=arte_guiada_test` no processo PHPUnit. A variável do servidor precisa ser definida separadamente porque o servidor PHP é outro processo.

**Nunca aponte PHPUnit para `arte_guiada` ou `arte_guiada_restore_test`.** Não use a base que contém seus dados reais. O detalhamento da cobertura, dos testes mantidos e dos removidos está em [`docs/MATRIZ_TESTES.md`](docs/MATRIZ_TESTES.md).

## Recuperação de senha

A recuperação atual gera um token de 30 minutos, armazena somente seu hash e devolve o token em uma resposta marcada para desenvolvimento local. A rota limita o uso ao host `localhost` recebido de um endereço de loopback. Não existe envio de e-mail implementado. Não publique essa funcionalidade como mecanismo de recuperação de produção sem integrar envio de token por canal seguro e revisar o fluxo.

## Logs e proteção de arquivos internos

A aplicação grava eventos de cadastro, login, criação/atualização de obras e erros das APIs do painel em `logs/app.log`, quando o diretório pode ser criado ou gravado pelo processo PHP. Senhas, tokens e cookies não devem ser adicionados ao contexto do log. A pasta `logs/` é ignorada pelo Git e bloqueada pelas regras de acesso HTTP.

O arquivo `.env`, configurações, SQL, documentação, testes e metadados Git não devem ser publicados como arquivos acessíveis pelo servidor web. Mantenha o `router.php` ou as regras `.htaccess` em uso conforme o servidor escolhido.

## Documentação complementar

- [Documentação das APIs](docs/API.md)
- [Matriz dos testes mantidos e removidos](docs/MATRIZ_TESTES.md)
- [Relatório técnico comparativo em PDF](docs/RELATORIO_TECNICO_COMPARATIVO.pdf)
- [Relatório técnico comparativo em Markdown](docs/RELATORIO_TECNICO_COMPARATIVO.md)
- [Fonte LaTeX para Overleaf](docs/RELATORIO_TECNICO_COMPARATIVO.tex)

## Limitações conhecidas

- Não há limite persistente de tentativas de login; o login não implementa bloqueio progressivo por conta ou endereço IP.
- Não há teste automatizado específico de XSS na suíte entregue. O uso de `innerHTML` localizado em `public/js/editor.js` insere apenas uma mensagem estática de carregamento, mas isso não substitui teste de segurança para dados não confiáveis.
- A suíte atual não comprova por teste automatizado a concessão e a revogação positiva do acesso delegado; comprova bloqueios sem concessão e permissões administrativas. Esse fluxo precisa de validação manual e, idealmente, de testes de integração dedicados.
- Os utilitários de sessão e resposta JSON em `api/relatos/comum.php` e `config/painel-api.php` ainda são separados. Não foram unificados porque atendem fluxos diferentes e a criação de relatos não possui cobertura automatizada nesta suíte.
- A instalação limpa do SQL, a suíte PHPUnit e os fluxos visuais devem ser executados no ambiente PHP/MySQL do projeto antes da entrega final. A verificação estática não substitui a execução.

## Autoria

**Roberta Ruivo Pompeu**

Sistemas Computacionais I — 2026
