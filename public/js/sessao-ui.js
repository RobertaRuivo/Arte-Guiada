/*
 * Arte Guiada | Navegação global e estado da sessão
 * Inclua este arquivo nas páginas HTML para padronizar somente o cabeçalho,
 * a identificação da sessão e o logout. O rodapé original de cada página é preservado.
 */
(() => {
    "use strict";

    if (window.__arteGuiadaShellInicializado) return;
    window.__arteGuiadaShellInicializado = true;

    const scriptAtual = document.currentScript;
    if (!scriptAtual?.src) return;

    const urlScript = new URL(scriptAtual.src);
    const raizAplicacao = new URL("../../", urlScript);
    const urlApi = (arquivo) => new URL(`api/login/${arquivo}.php`, raizAplicacao).href;
    const urlPagina = (arquivo) => new URL(arquivo, raizAplicacao).href;
    const rotas = {
        inicio: "index.html",
        galeria: "views/galeria.html",
        biblioteca: "views/biblioteca.html",
        acesso: "views/acesso.html",
        comoFunciona: "index.html#como-funciona"
    };
    const perfis = {
        visitante: { nome: "Visitante", destino: "views/visitante.html", link: "Minha área" },
        editor: { nome: "Editor", destino: "views/editor.html", link: "Espaço do editor" },
        admin: { nome: "Administrador", destino: "views/admin.html", link: "Administração" }
    };

    function normalizarPerfil(valor) {
        const perfil = String(valor || "")
            .trim()
            .toLocaleLowerCase("pt-BR")
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "");

        if (["admin", "administrador", "administradora", "administrator"].includes(perfil)) return "admin";
        if (["editor", "editora"].includes(perfil)) return "editor";
        if (["visitante", "visitor", "usuario", "user"].includes(perfil)) return "visitante";
        return "";
    }

    function caminhoAtual() {
        return window.location.pathname.replace(/\/+$/, "") || "/";
    }

    function ePaginaAcesso() {
        return /\/views\/acesso\.html$/i.test(caminhoAtual());
    }

    function criarElemento(tag, classe, texto) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
    }

    function adicionarEstilos() {
        if (document.getElementById("arte-guiada-navegacao-css")) return;
        const link = document.createElement("link");
        link.id = "arte-guiada-navegacao-css";
        link.rel = "stylesheet";
        link.href = new URL("../css/navegacao-global.css", urlScript).href;
        document.head.appendChild(link);
    }

    function montarCabecalho() {
        let cabecalho = document.querySelector("[data-site-header], header.cabecalho, body > header, header");
        if (!cabecalho) {
            cabecalho = document.createElement("header");
            const principal = document.querySelector("main");
            if (principal) principal.before(cabecalho);
            else document.body.prepend(cabecalho);
        }

        cabecalho.className = "cabecalho ag-global-header";
        cabecalho.dataset.siteHeader = "";
        cabecalho.replaceChildren();

        const nav = criarElemento("nav", "nav-principal ag-global-nav");
        nav.setAttribute("aria-label", "Navegação principal");

        const marca = criarElemento("a", "logo ag-global-logo", "Arte Guiada");
        marca.href = urlPagina(rotas.inicio);
        marca.setAttribute("aria-label", "Arte Guiada, página inicial");
        nav.appendChild(marca);

        const controleMenu = criarElemento("button", "ag-menu-toggle", "Menu");
        controleMenu.type = "button";
        controleMenu.setAttribute("aria-expanded", "false");
        controleMenu.setAttribute("aria-controls", "ag-global-links");
        controleMenu.addEventListener("click", () => {
            const aberto = controleMenu.getAttribute("aria-expanded") !== "true";
            controleMenu.setAttribute("aria-expanded", String(aberto));
            links.classList.toggle("ag-menu-aberto", aberto);
        });
        nav.appendChild(controleMenu);

        const links = criarElemento("div", "nav-links ag-global-links");
        links.id = "ag-global-links";
        const itens = [
            ["Início", rotas.inicio, "inicio"],
            ["Galeria", rotas.galeria, "galeria"],
            ["Como funciona", rotas.comoFunciona, "como-funciona"],
            ["Minha biblioteca", rotas.biblioteca, "biblioteca"]
        ];
        for (const [rotulo, rota, chave] of itens) {
            const link = criarElemento("a", "ag-global-link", rotulo);
            link.href = urlPagina(rota);
            link.dataset.navGlobal = chave;
            if (chave !== "como-funciona" && caminhoAtual() === new URL(link.href).pathname.replace(/\/+$/, "")) {
                link.setAttribute("aria-current", "page");
            }
            links.appendChild(link);
        }

        const linkPainel = criarElemento("a", "ag-global-link ag-link-painel", "Minha área");
        linkPainel.hidden = true;
        linkPainel.dataset.navPerfil = "";
        links.appendChild(linkPainel);

        const linkAcesso = criarElemento("a", "ag-global-link ag-link-acesso", "Entrar");
        linkAcesso.href = urlPagina(rotas.acesso);
        linkAcesso.dataset.navAcesso = "";
        linkAcesso.hidden = true;
        links.appendChild(linkAcesso);
        nav.appendChild(links);

        const areaConta = criarElemento("div", "ag-conta-area");
        areaConta.setAttribute("aria-live", "polite");
        areaConta.setAttribute("aria-atomic", "true");
        areaConta.appendChild(criarElemento("span", "ag-sessao-status", "Verificando sessão…"));
        nav.appendChild(areaConta);
        cabecalho.appendChild(nav);

        return { cabecalho, links, linkPainel, linkAcesso, areaConta, controleMenu };
    }


    const shell = montarCabecalho();
    adicionarEstilos();

    function publicarEstado(autenticado, usuario = null, perfil = "", erro = false) {
        document.dispatchEvent(new CustomEvent("arteguiada:estado-sessao", {
            detail: { autenticado, usuario, perfil, erro }
        }));
    }

    function mostrarDesconectado() {
        shell.linkPainel.hidden = true;
        shell.linkAcesso.hidden = false;
        shell.areaConta.replaceChildren();
        shell.areaConta.appendChild(criarElemento("span", "ag-sessao-status ag-sessao-anonima", "Você não está conectado"));
        publicarEstado(false);
    }

    function mostrarFalhaConsulta() {
        shell.linkPainel.hidden = true;
        shell.linkAcesso.hidden = true;
        shell.areaConta.replaceChildren();
        const texto = criarElemento("span", "ag-sessao-status ag-sessao-erro", "Não foi possível verificar a sessão");
        const tentar = criarElemento("button", "ag-tentar-sessao", "Tentar novamente");
        tentar.type = "button";
        tentar.addEventListener("click", verificarSessao);
        shell.areaConta.append(texto, tentar);
        publicarEstado(false, null, "", true);
    }

    function mostrarConectado(usuario, perfil) {
        const dadosPerfil = perfis[perfil];
        shell.linkAcesso.hidden = true;
        shell.linkPainel.hidden = !dadosPerfil;

        if (dadosPerfil) {
            shell.linkPainel.href = urlPagina(dadosPerfil.destino);
            shell.linkPainel.textContent = dadosPerfil.link;
            shell.linkPainel.setAttribute("aria-label", `Acessar ${dadosPerfil.link}`);
            if (caminhoAtual() === new URL(shell.linkPainel.href).pathname.replace(/\/+$/, "")) {
                shell.linkPainel.setAttribute("aria-current", "page");
            }
        }

        const identidade = criarElemento("span", "ag-identidade-conta");
        const ponto = criarElemento("span", "ag-sessao-ponto");
        ponto.setAttribute("aria-hidden", "true");
        const texto = criarElemento("span", "ag-identidade-texto");
        const nome = String(usuario?.nome || "Usuário").trim() || "Usuário";
        const rotuloPerfil = dadosPerfil?.nome || "Perfil não reconhecido";
        texto.appendChild(criarElemento("strong", "ag-identidade-nome", nome));
        texto.appendChild(criarElemento("small", "ag-identidade-perfil", `Conectado como ${rotuloPerfil}`));
        identidade.append(ponto, texto);

        const sair = criarElemento("button", "ag-sair", "Sair");
        sair.type = "button";
        sair.dataset.siteLogout = "";
        sair.addEventListener("click", encerrarSessao);
        shell.areaConta.replaceChildren(identidade, sair);
        publicarEstado(true, usuario, perfil);

        if (ePaginaAcesso() && dadosPerfil) {
            window.location.replace(urlPagina(dadosPerfil.destino));
        }
    }

    async function verificarSessao() {
        shell.areaConta.replaceChildren(criarElemento("span", "ag-sessao-status", "Verificando sessão…"));
        shell.linkAcesso.hidden = true;
        shell.linkPainel.hidden = true;
        try {
            const resposta = await fetch(urlApi("sessao"), {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401 || dados?.autenticado === false) {
                mostrarDesconectado();
                return;
            }
            if (!resposta.ok || !dados?.autenticado || !dados?.usuario) {
                throw new Error("A resposta do servidor não confirmou a sessão.");
            }

            const perfil = normalizarPerfil(dados.usuario.perfil);
            mostrarConectado(dados.usuario, perfil);
        } catch (erro) {
            // Falha de rede não é logout. O cabeçalho informa o problema sem mostrar um falso estado desconectado.
            mostrarFalhaConsulta();
        }
    }

    async function encerrarSessao(evento) {
        const botao = evento?.currentTarget || shell.areaConta.querySelector("[data-site-logout]");
        if (botao) {
            botao.disabled = true;
            botao.textContent = "Saindo…";
        }
        try {
            const resposta = await fetch(urlApi("logout"), {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(dados?.mensagem || "O servidor não confirmou o encerramento da sessão.");
            }
            window.location.replace(urlPagina(rotas.acesso));
        } catch (erro) {
            if (botao) {
                botao.disabled = false;
                botao.textContent = "Sair";
            }
            const aviso = criarElemento("span", "ag-sessao-erro", erro.message || "Não foi possível encerrar a sessão.");
            shell.areaConta.appendChild(aviso);
        }
    }

    verificarSessao();
})();
