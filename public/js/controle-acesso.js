/* Proteção das páginas privadas. Erros de rede não são tratados como logout. */
(() => {
    "use strict";

    const corpo = document.body;
    if (!corpo?.hasAttribute("data-perfis-permitidos")) return;

    const script = document.currentScript;
    if (!script?.src) return;
    const raizAplicacao = new URL("../../", new URL(script.src));
    const urlSessao = new URL("api/login/sessao.php", raizAplicacao).href;
    const urlAcesso = new URL("views/acesso.html", raizAplicacao).href;
    const paginasPorPerfil = {
        visitante: "views/visitante.html",
        editor: "views/editor.html",
        admin: "views/admin.html"
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

    const permitidos = (corpo.dataset.perfisPermitidos || "")
        .split(",")
        .map(normalizarPerfil)
        .filter(Boolean);

    let status = document.getElementById("ag-status-acesso");
    if (!status) {
        status = document.createElement("section");
        status.id = "ag-status-acesso";
        status.className = "ag-access-status";
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        const main = document.getElementById("conteudo-principal") || document.querySelector("main");
        if (main) main.before(status);
        else corpo.prepend(status);
    }

    function mostrarErroComTentativa(mensagem) {
        corpo.classList.remove("acesso-verificado");
        status.replaceChildren();
        const titulo = document.createElement("strong");
        titulo.textContent = "Não foi possível confirmar o acesso";
        const texto = document.createElement("p");
        texto.textContent = mensagem || "Verifique sua conexão com o servidor e tente novamente. Sua sessão não foi encerrada por esta falha.";
        const tentar = document.createElement("button");
        tentar.type = "button";
        tentar.className = "ag-tentar-sessao";
        tentar.textContent = "Tentar novamente";
        tentar.addEventListener("click", verificarAcesso, { once: true });
        status.append(titulo, texto, tentar);
        status.hidden = false;
    }

    async function verificarAcesso() {
        status.hidden = false;
        status.replaceChildren(document.createTextNode("Verificando sua sessão e suas permissões…"));
        corpo.classList.remove("acesso-verificado");

        try {
            const resposta = await fetch(urlSessao, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401 || dados?.autenticado === false) {
                window.location.replace(urlAcesso);
                return;
            }
            if (!resposta.ok || !dados?.autenticado || !dados?.usuario) {
                throw new Error(dados?.mensagem || "O servidor não retornou uma confirmação válida.");
            }

            const perfil = normalizarPerfil(dados.usuario.perfil);
            if (!perfil) {
                window.location.replace(urlAcesso);
                return;
            }

            if (!permitidos.includes(perfil)) {
                const destino = paginasPorPerfil[perfil];
                window.location.replace(destino ? new URL(destino, raizAplicacao).href : urlAcesso);
                return;
            }

            document.querySelectorAll("[data-usuario-nome]").forEach((elemento) => {
                elemento.textContent = dados.usuario.nome || "Usuário";
            });
            document.querySelectorAll("[data-usuario-perfil]").forEach((elemento) => {
                elemento.textContent = perfil === "admin" ? "Administrador" : perfil === "editor" ? "Editor" : "Visitante";
            });

            corpo.dataset.usuarioPerfil = perfil;
            corpo.classList.add("acesso-verificado");
            status.hidden = true;
            document.dispatchEvent(new CustomEvent("arteguiada:acesso-verificado", {
                detail: { usuario: dados.usuario, perfil }
            }));
        } catch (erro) {
            mostrarErroComTentativa(erro.message);
        }
    }

    verificarAcesso();
})();
