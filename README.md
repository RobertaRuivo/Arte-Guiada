# Arte Guiada

Aplicação web para exploração orientada de obras de arte. O visitante consulta o catálogo e percorre etapas de observação; usuários autenticados podem organizar uma biblioteca pessoal; editores criam e atualizam obras; administradores gerenciam publicações, permissões, destaques e relatos de problemas.

Projeto desenvolvido para a disciplina de Sistemas Computacionais, 2026. Esta documentação descreve a versão do repositório `main` enviada para revisão em 10/11/2026.

## Arquitetura em uma visão

[![Architecture diagram of robertaruivo/arte-guiada](https://gitdiagram.com/robertaruivo/arte-guiada/diagram.png)](https://gitdiagram.com/robertaruivo/arte-guiada?utm_source=readme&utm_medium=picture)

O diagrama é uma visão simplificada gerada pelo GitDiagram. Ele identifica módulos centrais, mas não substitui a relação completa de endpoints nem representa todas as verificações de autorização. A aplicação segue, em termos práticos, esta divisão:

1. **Interface:** páginas HTML em `index.html` e `views/`, com estilos em `public/css/` e comportamento em `public/js/`.
2. **APIs:** endpoints PHP agrupados por domínio em `api/`. O JavaScript faz requisições com `fetch`; a maioria das APIs devolve JSON. O envio da imagem da obra usa formulário multipart.
3. **Serviços compartilhados:** `config/` concentra conexão, ambiente, logs, sessão, respostas JSON e proteção de origem/CSRF.
4. **Persistência:** MySQL ou MariaDB guarda usuários, obras, etapas, biblioteca, relatos, histórico e notificações.

Não há framework de frontend, framework PHP, bundler ou `package.json`. O backend usa PHP sem framework e `mysqli`.

## Funcionalidades por perfil

| Perfil | O que pode fazer |
|---|---|
| Visitante sem conta | Consultar destaques, catálogo e obras publicadas; percorrer a galeria; enviar relato anônimo sobre uma obra publicada. |
| Visitante autenticado | Além das funções públicas, manter favoritos, lista de obras que deseja explorar e histórico de explorações concluídas; enviar relatos identificados. O cadastro público sempre cria esse perfil. |
| Editor | Criar obras, salvar rascunhos, publicar obras com etapas e regiões de zoom, editar as próprias obras, consultar notificações e tratar relatos atribuídos. Pode editar obra de outro autor quando recebe acesso delegado ativo. |
| Administrador | Gerenciar obras de todos os editores, restaurar itens da lixeira, promover/revogar editores, escolher até três destaques, acompanhar relatos, atribuir ou transferir responsabilidade, reforçar orientações e verificar correções. |

**Atenção à diferença entre autoria e acesso delegado:** a concessão de edição não altera `obras.criada_por`. Um editor delegado pode editar a obra enquanto a concessão estiver ativa. A exclusão e a restauração por editor continuam limitadas às obras de sua própria autoria; o administrador pode atuar sobre todas.

## Estrutura principal

```text
Arte-Guiada/
├── index.html
├── 404.html
├── router.php
├── .htaccess
├── .env.example
├── composer.json
├── phpunit.xml
├── api/
│   ├── admin/
│   ├── biblioteca/
│   ├── cadastro/
│   ├── login/
│   ├── notificacoes/
│   ├── obras/
│   ├── relatos/
│   └── senha/
├── config/
│   ├── banco.php
│   ├── env.php
│   ├── log.php
│   ├── painel-api.php
│   └── protecao-csrf.php
├── database/
│   ├── schema.sql
│   └── importar_a_boba.php
├── public/
│   ├── css/
│   ├── img/
│   └── js/
├── scripts/
│   └── criar-admin-inicial.php
├── tests/
│   ├── ApiPublicaTest.php
│   ├── CadastroApiTest.php
│   ├── LoginApiTest.php
│   └── Support/ApiTestCase.php
└── views/
    ├── acesso.html
    ├── admin.html
    ├── biblioteca.html
    ├── catalogo.html
    ├── editor.html
    ├── galeria.html
    ├── relatar-problema.html
    └── visitante.html
```

## Requisitos

- PHP **8.2 ou superior**.
- Extensões PHP: `mysqli`, `mbstring` e `fileinfo`; o código também depende de `mysqlnd` para `mysqli_stmt::get_result()`.
- MySQL **8.0 ou superior** ou MariaDB **10.5 ou superior**, com InnoDB e `utf8mb4`.
- Apache com `mod_rewrite` e suporte a `.htaccess`, ou o servidor embutido do PHP para desenvolvimento.
- Composer para instalar o PHPUnit, que é dependência de desenvolvimento.
- Node.js é opcional; não é necessário para executar o sistema.

A aplicação web não funciona ao abrir `index.html` diretamente pelo navegador, porque as páginas dependem das APIs PHP. GitHub Pages também não executa PHP nem disponibiliza o MySQL necessário para a versão atual.

## Como baixar o projeto

### Opção A: Git

Instale o Git e execute no PowerShell ou terminal:

```powershell
git clone https://github.com/robertaruivo/arte-guiada.git
cd arte-guiada
```

### Opção B: ZIP pelo GitHub

1. Abra `https://github.com/robertaruivo/arte-guiada`.
2. Clique em **Code** e depois em **Download ZIP**.
3. Extraia o arquivo.
4. Use a pasta extraída como raiz do projeto.

Para usar XAMPP, coloque a pasta do projeto em `C:\xampp\htdocs\Arte-Guiada`. Se escolher outro local, ajuste o endereço no navegador e os caminhos nos comandos abaixo.

## Instalação local no Windows com XAMPP

### 1. Inicie os serviços

Abra o **XAMPP Control Panel** e inicie:

- **Apache**, para servir o site e abrir o phpMyAdmin;
- **MySQL**, para disponibilizar o banco de dados.

Na configuração padrão, Apache atende em `80` e MySQL em `3306`. Se essas portas estiverem ocupadas, resolva o conflito no serviço correspondente antes de iniciar a demonstração.

### 2. Crie o banco de dados

Abra `http://localhost/phpmyadmin`, entre na aba **SQL** e execute:

```sql
CREATE DATABASE arte_guiada
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Selecione o banco `arte_guiada`, abra a aba **Importar** e envie o arquivo `database/schema.sql`.

O schema cria as 12 tabelas do sistema. Ele cria estrutura, não cria usuários, obras nem dados de demonstração. Execute-o somente em um banco vazio. Não existe migração automática validada para bancos antigos.

As tabelas principais são `usuarios`, `tokens_recuperacao`, `obras`, `etapas_exploracao`, `biblioteca_usuario`, `relatos_problema`, `relatos_problema_tipos`, `relatos_problema_eventos`, `obras_editores_acesso`, `notificacoes_editor`, `notificacoes_admin` e `gerenciamento_editores_log`.

### 3. Configure o ambiente

Na raiz do projeto, copie o exemplo:

```powershell
Copy-Item .env.example .env
```

Edite `.env` com as credenciais reais do MySQL/MariaDB. Exemplo local:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=arte_guiada
DB_USER=seu_usuario
DB_PASSWORD=sua_senha

ADMIN_BOOTSTRAP_NOME=Nome
ADMIN_BOOTSTRAP_SOBRENOME=Sobrenome
ADMIN_BOOTSTRAP_EMAIL=admin@exemplo.com
ADMIN_BOOTSTRAP_SENHA=UmaSenhaLocalCom12Caracteres
```

A senha do administrador inicial precisa ter pelo menos 12 caracteres. Em uma instalação XAMPP sem senha configurada para `root`, `DB_USER=root` e `DB_PASSWORD=` podem funcionar, mas use os valores definidos na sua própria instalação. Não publique nem envie o arquivo `.env` ao Git.

`config/banco.php` lê essas variáveis, valida a porta e o nome do banco e abre a conexão `mysqli` com `utf8mb4`. Se `ARTE_GUIADA_DB_NAME` estiver definida no processo, ela tem prioridade sobre `DB_NAME`; essa variável é usada para separar o banco de testes.

### 4. Crie o primeiro administrador

Na raiz do projeto, execute pelo terminal:

```powershell
php scripts/criar-admin-inicial.php
```

Se o comando `php` não estiver no PATH do Windows, use o executável do XAMPP:

```powershell
& "C:\xampp\php\php.exe" scripts/criar-admin-inicial.php
```

O script só funciona pela CLI. Ele usa as variáveis `ADMIN_BOOTSTRAP_*`, calcula o hash da senha, utiliza transação e bloqueio nomeado para evitar duas inicializações simultâneas. Se já existir um administrador, não cria outro nem modifica contas existentes.

O cadastro público não cria administradores: toda conta nova recebe o perfil `visitante`. Depois de entrar como administrador, use **Gerenciar editores** para promover outras contas.

### 5. Abra a aplicação

Com Apache e MySQL ativos, e o projeto em `C:\xampp\htdocs\Arte-Guiada`, acesse:

```text
http://localhost/Arte-Guiada/
```

Como alternativa de desenvolvimento, na raiz do projeto execute:

```powershell
php -S 127.0.0.1:8000 router.php
```

Acesse `http://127.0.0.1:8000/`. Se não houver Apache, basta manter o MySQL ativo e utilizar o servidor embutido do PHP. O `router.php` bloqueia diretórios e arquivos internos. O servidor embutido serve para desenvolvimento local, não para produção.

## Primeiro uso e dados de demonstração

### Criar uma conta de editor

1. Abra **Login** e faça um cadastro normal.
2. Entre com a conta administrativa.
3. Abra **Gerenciar editores** e promova a conta recém-criada.
4. Encerre a sessão administrativa e entre como editor.

### Criar uma obra para demonstrar a visita guiada

No **Espaço do editor**:

1. Abra **Nova obra**.
2. Informe título, artista e descrição alternativa da imagem.
3. Selecione uma imagem JPEG, PNG ou WebP de até **7 MB**.
4. Adicione pelo menos uma etapa, com título e explicação.
5. Arraste sobre a imagem para desenhar a região de zoom da etapa. Ajuste o retângulo, se necessário.
6. Salve como rascunho ou publique. A publicação exige todos os campos e regiões de zoom válidos.

A área de zoom é armazenada como coordenadas normalizadas entre 0 e 1. Isso permite que a mesma região seja recalculada na galeria, independentemente das dimensões exibidas na tela.

### Importar a obra opcional “A Boba”

O utilitário `database/importar_a_boba.php` importa **A Boba**, de Anita Malfatti, com duas etapas textuais. Não cria a conta do editor e não é executado automaticamente.

Pré-requisitos: schema instalado, conta com perfil `editor`, arquivo `public/img/a_boba.jpg` disponível e e-mail do editor conhecido. Execute:

```powershell
php .\database\importar_a_boba.php --editor-email=editor@exemplo.com
```

O importador evita duplicar obra com o mesmo título e autor. As coordenadas normalizadas de zoom são gravadas como `NULL`, pois o conteúdo vem da antiga galeria e não possui os retângulos do editor atual. Para demonstrar o recurso de zoom do editor, crie uma obra de demonstração pelo formulário e marque os retângulos manualmente.

## Como usar o sistema

### Visitante

- Na página inicial, consulte os destaques e acesse o catálogo.
- No catálogo, pesquise por título ou artista e abra uma obra publicada.
- Na galeria, avance e retorne entre as etapas, acompanhe o texto explicativo e visualize a obra completa.
- Use **Relatar problema** para informar inconsistências. O formulário aceita relatos anônimos e identificados.

### Usuário autenticado

- Na galeria, marque a obra como favorita ou adicione-a à lista de obras que deseja explorar.
- Ao concluir a visita, o sistema registra a exploração no histórico.
- Consulte e organize esses registros em **Minha biblioteca**.

### Editor

- Em **Minhas obras**, consulte as publicações e os rascunhos aos quais possui acesso.
- Em **Nova obra**, cadastre os dados, a imagem, as etapas e as regiões de zoom.
- Salve um rascunho para continuar depois ou publique uma obra completa.
- Use a **Lixeira** para restaurar obras próprias excluídas logicamente.
- Em **Problemas das minhas obras**, consulte relatos atribuídos, inicie a correção e envie uma explicação ao administrador para verificação.
- Consulte as notificações internas para acompanhar atribuições, avisos e mudanças.

Um editor com acesso delegado pode editar a obra concedida, mas não passa a ser o autor original. A exclusão e a restauração pelo perfil Editor permanecem limitadas à autoria própria.

### Administrador

- Em **Gerenciar obras**, consulte e edite publicações de todos os editores.
- Use a **Lixeira** para restaurar obras excluídas.
- Em **Gerenciar editores**, promova contas de visitante e revogue perfis de editor. A revogação é impedida enquanto houver relatos ativos atribuídos ao editor; primeiro transfira ou conclua esses relatos.
- Em **Galeria e destaques**, escolha e ordene até três obras publicadas.
- Em **Obras com problemas**, acompanhe os relatos, altere prioridade, atribua ou transfira a responsabilidade, reforce orientações, aprove uma correção, devolva para novo ajuste, marque como improcedente ou reabra um relato encerrado.

O fluxo de relatos segue, em linhas gerais: envio do relato → atribuição ao editor responsável (ou estado pendente, quando não há editor responsável) → início da correção → envio para verificação → aprovação ou devolução pelo administrador. As ações são registradas em `relatos_problema_eventos` e as notificações são internas ao sistema.

## Portas e endereços locais

| Serviço | Porta usada nesta documentação | Endereço típico |
|---|---:|---|
| Apache/XAMPP | 80 | `http://localhost/Arte-Guiada/` |
| MySQL/MariaDB | 3306 | `127.0.0.1:3306` |
| PHP embutido da aplicação, alternativa ao Apache | 8000 | `http://127.0.0.1:8000/` |
| PHP embutido para servidor dos testes | 8001 | `http://127.0.0.1:8001/` |
| phpMyAdmin (servido pelo Apache) | 80 | `http://localhost/phpmyadmin` |

A suíte de testes espera a API em `127.0.0.1:8001`. Essa porta é para o servidor separado dos testes, não para a demonstração normal do site. O MySQL permanece na porta `3306`.

## Testes automatizados (PHPUnit)

A suíte atual possui **15 métodos de teste** em três classes. A autora informou que os 15 testes passaram após o merge para `main` em 10/11/2026. O resultado corresponde à execução reportada pela autora; não significa que todos os módulos do sistema estejam cobertos.

| Classe | Quantidade | Cobertura principal |
|---|---:|---|
| `tests/ApiPublicaTest.php` | 6 | Entrega de imagem pública, bloqueio de obra não publicada, MIME incompatível, exigência de sessão para rascunho e tratamento de título malicioso no catálogo. |
| `tests/CadastroApiTest.php` | 5 | Campos obrigatórios, cadastro válido e hash da senha, e-mail duplicado, bloqueio de criação pública de administrador e entrada de SQL injection tratada como texto. |
| `tests/LoginApiTest.php` | 4 | Origem não autorizada, senha incorreta, login/cookie de sessão, persistência da sessão e encerramento por logout. |
| **Total** | **15** | Testes de API pública, cadastro e login. |

### Preparar o banco exclusivo de testes

Não use o banco principal `arte_guiada` para PHPUnit. Crie outro banco vazio:

```sql
CREATE DATABASE arte_guiada_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Selecione `arte_guiada_test` no phpMyAdmin e importe novamente `database/schema.sql`.

### Instalar a dependência de desenvolvimento

Na raiz do projeto:

```powershell
composer install
```

O `composer.json` declara `phpunit/phpunit` na faixa `^11`. O repositório enviado para esta análise não contém `composer.lock`; por isso, a versão exata instalada pode variar dentro dessa faixa.

### Ligar o servidor para os testes

Abra um terminal PowerShell na raiz do projeto e execute:

```powershell
$env:ARTE_GUIADA_DB_NAME = "arte_guiada_test"
php -S 127.0.0.1:8001 router.php
```

Mantenha esse terminal aberto. Se `php` não estiver no PATH:

```powershell
$env:ARTE_GUIADA_DB_NAME = "arte_guiada_test"
& "C:\xampp\php\php.exe" -S 127.0.0.1:8001 router.php
```

O servidor dos testes precisa receber a variável `ARTE_GUIADA_DB_NAME` porque ele é um processo separado. O `phpunit.xml` também define essa variável dentro do próprio processo PHPUnit, mas isso não configura automaticamente o servidor HTTP.

### Executar a suíte

Em um segundo terminal, também na raiz do projeto:

```powershell
.\vendor\bin\phpunit.bat --testdox
```

O resumo esperado quando tudo passa é `OK` e a indicação de 15 testes. Não encerre o servidor na porta `8001` antes do fim da execução.

Os testes preparam contas temporárias, usam o banco `arte_guiada_test` e fazem limpeza dos dados criados. Não execute a suíte apontando para o banco de demonstração ou para dados reais.

### O que os testes não comprovam

Os 15 testes não cobrem integralmente a administração, o fluxo completo de relatos, a redistribuição de obras, notificações ou todos os caminhos da biblioteca. Esses módulos existem no código, mas não devem ser apresentados como cobertos pela suíte atual. Também não existe teste automatizado abrangente de XSS para toda a aplicação.

## Segurança e limitações conhecidas

- As APIs usam consultas preparadas `mysqli` nos pontos de entrada de dados e validações no servidor.
- Senhas são armazenadas com `password_hash()` e verificadas com `password_verify()`.
- O login regenera o identificador de sessão. Os cookies são `HttpOnly`, `SameSite=Lax` e `Secure` quando a aplicação usa HTTPS.
- Os endpoints protegidos verificam sessão e perfil no backend. `controle-acesso.js` também protege a navegação da interface, mas essa verificação no navegador não substitui a autorização da API.
- A proteção contra requisições de outra origem valida informações como `Origin`, `Referer` e `Sec-Fetch-Site`; o projeto não usa token CSRF.
- Operações compostas usam transações. Algumas operações críticas também bloqueiam registros com `FOR UPDATE`.
- A exclusão de obra é lógica, por `excluida_em`; a imagem e as etapas permanecem disponíveis para restauração.
- Imagens ficam em colunas `MEDIUMBLOB` e são entregues por endpoints PHP. Isso simplifica o controle de acesso, mas aumenta o tamanho do banco e exige atenção aos backups e ao `max_allowed_packet`.
- O upload normal da aplicação aceita JPEG, PNG e WebP, com limite de 7 MB. Ajuste `upload_max_filesize` e `post_max_size` do PHP para comportar esse limite e o multipart.
- A recuperação de senha gera token aleatório, guarda apenas o hash e define validade de 30 minutos. Não há envio de e-mail; o token é exposto para desenvolvimento local. Não apresente esse fluxo como recuperação pronta para produção.
- Não há limite persistente de tentativas de login nem bloqueio progressivo por conta/IP.
- `config/painel-api.php` e `api/relatos/comum.php` possuem utilitários de sessão/resposta separados. Essa duplicação permanece na versão analisada.
- O schema não impõe `CHECK` ou `TRIGGER` para todos os domínios de perfil, estado e prioridade; várias regras dependem do PHP.
- A pasta `docs/` e os documentos complementares citados pelo README anterior não aparecem na árvore `main` enviada para esta revisão. Não mantenha links para arquivos que não estejam efetivamente no repositório.


## Autoria

**Roberta Ruivo Pompeu**  
UNESP — Campus Sorocaba  
Sistemas Computacionais — 2026
