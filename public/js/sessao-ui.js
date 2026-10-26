/*
 * Arte Guiada | Navegação global e estado da sessão
 *
 * Padroniza a navegação e a identificação da sessão.
 * Preserva os componentes específicos de cada página,
 * incluindo a busca do catálogo e o rodapé original.
 */

(() => {
    "use strict";

    if (window.__arteGuiadaShellInicializado) {
        return;
    }

    window.__arteGuiadaShellInicializado = true;

    const scriptAtual = document.currentScript;

    if (!scriptAtual?.src) {
        return;
    }

    const urlScript = new URL(scriptAtual.src);
    const raizAplicacao = new URL("../../", urlScript);

    const urlApi = (arquivo) =>
        new URL(`api/login/${arquivo}.php`, raizAplicacao).href;

    const urlPagina = (arquivo) =>
        new URL(arquivo, raizAplicacao).href;

    const rotas = {
        inicio: "index.html",
        galeria: "views/galeria.html",
        catalogo: "views/catalogo.html",
        biblioteca: "views/biblioteca.html",
        acesso: "views/acesso.html",
        comoFunciona: "index.html#como-funciona"
    };

    const perfis = {
        visitante: {
            nome: "Visitante",
            destino: "views/visitante.html",
            link: "Minha área"
        },

        editor: {
            nome: "Editor",
            destino: "views/editor.html",
            link: "Espaço do editor"
        },

        admin: {
            nome: "Administrador",
            destino: "views/admin.html",
            link: "Administração"
        }
    };

    /*
     * Identifica o perfil recebido do servidor.
     */

    function normalizarPerfil(valor) {
        const perfil = String(valor || "")
            .trim()
            .toLocaleLowerCase("pt-BR")
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "");

        if (
            [
                "admin",
                "administrador",
                "administradora",
                "administrator"
            ].includes(perfil)
        ) {
            return "admin";
        }

        if (["editor", "editora"].includes(perfil)) {
            return "editor";
        }

        if (
            [
                "visitante",
                "visitor",
                "usuario",
                "user"
            ].includes(perfil)
        ) {
            return "visitante";
        }

        return "";
    }

    /*
     * Informações da página atual.
     */

    function caminhoAtual() {
        return window.location.pathname.replace(/\/+$/, "") || "/";
    }

    function ePaginaAcesso() {
        return /\/views\/acesso\.html$/i.test(caminhoAtual());
    }

    function ePaginaCatalogo() {
        return document.body.classList.contains("pagina-catalogo");
    }

    /*
     * Cria elementos sem inserir HTML externo.
     */

    function criarElemento(tag, classe, texto) {
        const elemento = document.createElement(tag);

        if (classe) {
            elemento.className = classe;
        }

        if (texto !== undefined) {
            elemento.textContent = texto;
        }

        return elemento;
    }

    /*
     * Carrega o CSS da navegação global.
     */

    function adicionarEstilos() {
        if (
            document.getElementById("arte-guiada-navegacao-css")
        ) {
            return;
        }

        const link = document.createElement("link");

        link.id = "arte-guiada-navegacao-css";
        link.rel = "stylesheet";
        link.href = new URL(
            "../css/navegacao-global.css",
            urlScript
        ).href;

        document.head.appendChild(link);
    }

    /*
     * Garante que o catálogo tenha os elementos da busca.
     *
     * A função reutiliza os elementos originais quando existem.
     * Caso estejam ausentes, cria elementos com os IDs esperados
     * pelo catalogo.js.
     */

    function prepararBuscaCatalogo(cabecalho) {
        let botao = document.getElementById(
            "toggleBuscaCatalogo"
        );

        let painel = document.getElementById(
            "painelBuscaCatalogo"
        );

        /*
         * Botão de busca.
         */

        if (!botao) {
            botao = criarElemento(
                "button",
                "catalogo-busca-toggle"
            );

            botao.type = "button";
            botao.id = "toggleBuscaCatalogo";

            botao.setAttribute("aria-expanded", "false");
            botao.setAttribute(
                "aria-controls",
                "painelBuscaCatalogo"
            );
            botao.setAttribute(
                "aria-label",
                "Abrir busca de obras"
            );

            const icone = criarElemento("span");
            icone.setAttribute("aria-hidden", "true");
            icone.textContent = "⌕";

            const texto = criarElemento("span", "", "Buscar");

            botao.append(icone, texto);
        }

        /*
         * Painel de busca.
         */

        if (!painel) {
            painel = criarElemento(
                "div",
                "catalogo-busca-popover"
            );

            painel.id = "painelBuscaCatalogo";
            painel.hidden = true;

            painel.setAttribute("role", "search");
            painel.setAttribute(
                "aria-label",
                "Buscar obras no catálogo"
            );
        }

        /*
         * Campo de pesquisa.
         */

        let campo = painel.querySelector("#buscaObra") ||
            document.getElementById("buscaObra");

        if (!campo) {
            const rotulo = criarElemento(
                "label",
                "",
                "Buscar por título ou artista"
            );

            rotulo.htmlFor = "buscaObra";

            campo = document.createElement("input");
            campo.id = "buscaObra";
            campo.name = "buscaObra";
            campo.type = "search";
            campo.placeholder = "Digite o título ou artista...";
            campo.autocomplete = "off";

            painel.append(rotulo, campo);

        } else if (!painel.contains(campo)) {
            /*
             * Se o campo existia fora do painel, preserva o próprio
             * elemento e o move para o painel de pesquisa.
             */

            const rotuloExistente = document.querySelector(
                'label[for="buscaObra"]'
            );

            if (rotuloExistente) {
                painel.appendChild(rotuloExistente);
            }

            painel.appendChild(campo);
        }

        /*
         * Botão para fechar o painel.
         */

        let fechar = painel.querySelector(
            "#fecharBuscaCatalogo"
        );

        if (!fechar) {
            fechar = criarElemento(
                "button",
                "catalogo-busca-fechar",
                "Fechar"
            );

            fechar.id = "fecharBuscaCatalogo";
            fechar.type = "button";

            painel.appendChild(fechar);
        }

        /*
         * Reaplica os atributos esperados pela interface.
         */

        botao.setAttribute(
            "aria-controls",
            "painelBuscaCatalogo"
        );

        if (!painel.contains(campo)) {
            painel.appendChild(campo);
        }

        /*
         * Os elementos são devolvidos para que sejam inseridos
         * no cabeçalho depois da reconstrução.
         */

        return {
            botao,
            painel
        };
    }

    /*
     * Constrói a navegação global.
     *
     * Na página do catálogo, preserva a busca antes de executar
     * replaceChildren() e reinsere os componentes em seguida.
     */

    function montarCabecalho() {
        const paginaCatalogo = ePaginaCatalogo();

        let cabecalho = document.querySelector(
            "[data-site-header], header.cabecalho, body > header, header"
        );

        if (!cabecalho) {
            cabecalho = document.createElement("header");

            const principal = document.querySelector("main");

            if (principal) {
                principal.before(cabecalho);
            } else {
                document.body.prepend(cabecalho);
            }
        }

        /*
         * Guarda a busca antes de remover os filhos do cabeçalho.
         */

        const busca = paginaCatalogo
            ? prepararBuscaCatalogo(cabecalho)
            : null;

        /*
         * Reconstrói somente o cabeçalho.
         * O main e o rodapé não são modificados.
         */

        cabecalho.className = "cabecalho ag-global-header";
        cabecalho.dataset.siteHeader = "";

        if (paginaCatalogo) {
            cabecalho.classList.add("catalogo-cabecalho");
        }

        cabecalho.replaceChildren();

        /*
         * Navegação.
         */

        const classeNav = paginaCatalogo
            ? "nav-principal ag-global-nav catalogo-nav"
            : "nav-principal ag-global-nav";

        const nav = criarElemento("nav", classeNav);

        nav.setAttribute(
            "aria-label",
            "Navegação principal"
        );

        /*
         * Marca do site.
         */

        const marca = criarElemento(
            "a",
            "logo ag-global-logo",
            "Arte Guiada"
        );

        marca.href = urlPagina(rotas.inicio);
        marca.setAttribute(
            "aria-label",
            "Arte Guiada, página inicial"
        );

        nav.appendChild(marca);

        /*
         * Links da navegação.
         */

        const classeLinks = paginaCatalogo
            ? "nav-links ag-global-links catalogo-nav-links"
            : "nav-links ag-global-links";

        const links = criarElemento("div", classeLinks);

        links.id = "ag-global-links";

        /*
         * Botão de menu responsivo.
         */

        const controleMenu = criarElemento(
            "button",
            "ag-menu-toggle",
            "Menu"
        );

        controleMenu.type = "button";
        controleMenu.setAttribute(
            "aria-expanded",
            "false"
        );
        controleMenu.setAttribute(
            "aria-controls",
            "ag-global-links"
        );

        controleMenu.addEventListener("click", () => {
            const aberto =
                controleMenu.getAttribute("aria-expanded") !== "true";

            controleMenu.setAttribute(
                "aria-expanded",
                String(aberto)
            );

            links.classList.toggle(
                "ag-menu-aberto",
                aberto
            );
        });

        nav.appendChild(controleMenu);

        /*
         * Links principais.
         *
         * "Galeria" aponta para o catálogo quando a página atual
         * também é o catálogo; nas demais páginas mantém a galeria guiada.
         */

        const rotaGaleria = paginaCatalogo
            ? rotas.catalogo
            : rotas.galeria;

        const chaveGaleria = paginaCatalogo
            ? "catalogo"
            : "galeria";

        const itens = [
            ["Início", rotas.inicio, "inicio"],
            ["Galeria", rotaGaleria, chaveGaleria],
            ["Como funciona", rotas.comoFunciona, "como-funciona"],
            ["Minha biblioteca", rotas.biblioteca, "biblioteca"]
        ];

        for (const [rotulo, rota, chave] of itens) {
            const link = criarElemento(
                "a",
                "ag-global-link",
                rotulo
            );

            link.href = urlPagina(rota);
            link.dataset.navGlobal = chave;

            const destino = new URL(
                link.href
            ).pathname.replace(/\/+$/, "");

            if (
                chave !== "como-funciona" &&
                caminhoAtual() === destino
            ) {
                link.setAttribute("aria-current", "page");
            }

            links.appendChild(link);
        }

        /*
         * Link para a área do usuário.
         */

        const linkPainel = criarElemento(
            "a",
            "ag-global-link ag-link-painel",
            "Minha área"
        );

        linkPainel.hidden = true;
        linkPainel.dataset.navPerfil = "";

        links.appendChild(linkPainel);

        /*
         * Link de acesso.
         */

        const linkAcesso = criarElemento(
            "a",
            "ag-global-link ag-link-acesso",
            "Entrar"
        );

        linkAcesso.href = urlPagina(rotas.acesso);
        linkAcesso.dataset.navAcesso = "";
        linkAcesso.hidden = true;

        links.appendChild(linkAcesso);

        /*
         * CORREÇÃO: reinsere o botão de busca no menu do catálogo.
         */

        if (busca) {
            links.appendChild(busca.botao);
        }

        nav.appendChild(links);

        /*
         * Identificação da conta.
         */

        const areaConta = criarElemento(
            "div",
            "ag-conta-area"
        );

        areaConta.setAttribute("aria-live", "polite");
        areaConta.setAttribute("aria-atomic", "true");

        areaConta.appendChild(
            criarElemento(
                "span",
                "ag-sessao-status",
                "Verificando sessão…"
            )
        );

        nav.appendChild(areaConta);

        cabecalho.appendChild(nav);

        /*
         * CORREÇÃO: reinsere o painel de pesquisa depois da navegação.
         */

        if (busca) {
            cabecalho.appendChild(busca.painel);
        }

        return {
            cabecalho,
            links,
            linkPainel,
            linkAcesso,
            areaConta,
            controleMenu
        };
    }

    /*
     * Inicializa o cabeçalho e os estilos.
     */

    const shell = montarCabecalho();

    adicionarEstilos();

    /*
     * Informa o estado da sessão para os demais scripts.
     */

    function publicarEstado(
        autenticado,
        usuario = null,
        perfil = "",
        erro = false
    ) {
        document.dispatchEvent(
            new CustomEvent("arteguiada:estado-sessao", {
                detail: {
                    autenticado,
                    usuario,
                    perfil,
                    erro
                }
            })
        );
    }

    /*
     * Interface para usuários desconectados.
     */

    function mostrarDesconectado() {
        shell.linkPainel.hidden = true;
        shell.linkAcesso.hidden = false;

        shell.areaConta.replaceChildren();

        shell.areaConta.appendChild(
            criarElemento(
                "span",
                "ag-sessao-status ag-sessao-anonima",
                "Você não está conectado"
            )
        );

        publicarEstado(false);
    }

    /*
     * Interface quando não é possível consultar a sessão.
     */

    function mostrarFalhaConsulta() {
        shell.linkPainel.hidden = true;
        shell.linkAcesso.hidden = true;

        shell.areaConta.replaceChildren();

        const texto = criarElemento(
            "span",
            "ag-sessao-status ag-sessao-erro",
            "Não foi possível verificar a sessão"
        );

        const tentar = criarElemento(
            "button",
            "ag-tentar-sessao",
            "Tentar novamente"
        );

        tentar.type = "button";

        tentar.addEventListener(
            "click",
            verificarSessao
        );

        shell.areaConta.append(texto, tentar);

        publicarEstado(false, null, "", true);
    }

    /*
     * Interface para usuários autenticados.
     */

    function mostrarConectado(usuario, perfil) {
        const dadosPerfil = perfis[perfil];

        shell.linkAcesso.hidden = true;
        shell.linkPainel.hidden = !dadosPerfil;

        if (dadosPerfil) {
            shell.linkPainel.href = urlPagina(
                dadosPerfil.destino
            );

            shell.linkPainel.textContent = dadosPerfil.link;

            shell.linkPainel.setAttribute(
                "aria-label",
                `Acessar ${dadosPerfil.link}`
            );

            const caminhoPainel = new URL(
                shell.linkPainel.href
            ).pathname.replace(/\/+$/, "");

            if (caminhoAtual() === caminhoPainel) {
                shell.linkPainel.setAttribute(
                    "aria-current",
                    "page"
                );
            } else {
                shell.linkPainel.removeAttribute(
                    "aria-current"
                );
            }
        }

        const identidade = criarElemento(
            "span",
            "ag-identidade-conta"
        );

        const ponto = criarElemento(
            "span",
            "ag-sessao-ponto"
        );

        ponto.setAttribute(
            "aria-hidden",
            "true"
        );

        const texto = criarElemento(
            "span",
            "ag-identidade-texto"
        );

        const nome = String(
            usuario?.nome || "Usuário"
        ).trim() || "Usuário";

        const rotuloPerfil =
            dadosPerfil?.nome || "Perfil não reconhecido";

        texto.appendChild(
            criarElemento(
                "strong",
                "ag-identidade-nome",
                nome
            )
        );

        texto.appendChild(
            criarElemento(
                "small",
                "ag-identidade-perfil",
                `Conectado como ${rotuloPerfil}`
            )
        );

        identidade.append(ponto, texto);

        const sair = criarElemento(
            "button",
            "ag-sair",
            "Sair"
        );

        sair.type = "button";
        sair.dataset.siteLogout = "";

        sair.addEventListener(
            "click",
            encerrarSessao
        );

        shell.areaConta.replaceChildren(
            identidade,
            sair
        );

        publicarEstado(true, usuario, perfil);

        if (ePaginaAcesso() && dadosPerfil) {
            window.location.replace(
                urlPagina(dadosPerfil.destino)
            );
        }
    }

    /*
     * Consulta a sessão no servidor.
     */

    async function verificarSessao() {
        shell.areaConta.replaceChildren(
            criarElemento(
                "span",
                "ag-sessao-status",
                "Verificando sessão…"
            )
        );

        shell.linkAcesso.hidden = true;
        shell.linkPainel.hidden = true;

        try {
            const resposta = await fetch(
                urlApi("sessao"),
                {
                    method: "GET",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {
                        Accept: "application/json"
                    }
                }
            );

            const dados = await resposta
                .json()
                .catch(() => null);

            if (
                resposta.status === 401 ||
                dados?.autenticado === false
            ) {
                mostrarDesconectado();
                return;
            }

            if (
                !resposta.ok ||
                !dados?.autenticado ||
                !dados?.usuario
            ) {
                throw new Error(
                    "A resposta do servidor não confirmou a sessão."
                );
            }

            const perfil = normalizarPerfil(
                dados.usuario.perfil
            );

            mostrarConectado(dados.usuario, perfil);

        } catch (erro) {
            /*
             * Falha de rede não significa logout.
             */

            console.error(
                "Falha ao verificar a sessão:",
                erro
            );

            mostrarFalhaConsulta();
        }
    }

    /*
     * Encerra a sessão.
     */

    async function encerrarSessao(evento) {
        const botao =
            evento?.currentTarget ||
            shell.areaConta.querySelector("[data-site-logout]");

        if (botao) {
            botao.disabled = true;
            botao.textContent = "Saindo…";
        }

        try {
            const resposta = await fetch(
                urlApi("logout"),
                {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {
                        Accept: "application/json"
                    }
                }
            );

            const dados = await resposta
                .json()
                .catch(() => null);

            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(
                    dados?.mensagem ||
                    "O servidor não confirmou o encerramento da sessão."
                );
            }

            window.location.replace(
                urlPagina(rotas.acesso)
            );

        } catch (erro) {
            if (botao) {
                botao.disabled = false;
                botao.textContent = "Sair";
            }

            const aviso = criarElemento(
                "span",
                "ag-sessao-erro",
                erro.message ||
                "Não foi possível encerrar a sessão."
            );

            shell.areaConta.appendChild(aviso);
        }
    }

    /*
     * Inicia a verificação.
     */

    verificarSessao();

})();