/* Mantém a navegação pública coerente com a sessão PHP existente.
 * A navegação entre páginas não encerra a sessão. Só o endpoint de logout faz isso.
 */
(() => {
    "use strict";

    const scriptAtual = document.currentScript;
    if (!scriptAtual?.src) return;

    const raizAplicacao = new URL("../../", scriptAtual.src);
    const endpoint = (nome) => new URL(`api/login/${nome}.php`, raizAplicacao).href;
    const destinos = {
        visitante: { arquivo: "visitante.html", rotulo: "Minha biblioteca" },
        editor: { arquivo: "editor.html", rotulo: "Espaço do editor" },
        admin: { arquivo: "admin.html", rotulo: "Administração" }
    };

    async function encerrarSessao() {
        const botaoSair = document.querySelector("[data-sessao-logout]");
        if (botaoSair) {
            botaoSair.setAttribute("aria-disabled", "true");
            botaoSair.textContent = "Saindo…";
        }

        try {
            const resposta = await fetch(endpoint("logout"), {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(dados?.mensagem || "Não foi possível encerrar a sessão.");
            }
            window.location.replace(new URL("views/acesso.html", raizAplicacao).href);
        } catch (erro) {
            if (botaoSair) {
                botaoSair.removeAttribute("aria-disabled");
                botaoSair.textContent = "Sair";
            }
            window.alert(erro.message || "Não foi possível encerrar a sessão. Tente novamente.");
        }
    }

    async function atualizarNavegacao() {
        let resposta;
        let dados;
        try {
            resposta = await fetch(endpoint("sessao"), {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });
            dados = await resposta.json().catch(() => null);
        } catch (erro) {
            // Falha de rede não apaga nem encerra a sessão. Mantemos a navegação pública normal.
            return;
        }

        if (!resposta.ok || !dados?.autenticado || !dados?.usuario) return;

        const perfil = String(dados.usuario.perfil || "").trim().toLowerCase();
        const destino = destinos[perfil];
        const estaNaTelaDeAcesso = /\/views\/acesso\.html$/.test(window.location.pathname);

        if (estaNaTelaDeAcesso) {
            if (destino) {
                window.location.replace(new URL(`views/${destino.arquivo}`, raizAplicacao).href);
            }
            return;
        }

        const linkAcesso = document.querySelector('.nav-links a[href*="acesso.html"]');
        if (!linkAcesso || !destino) return;

        linkAcesso.href = new URL(`views/${destino.arquivo}`, raizAplicacao).href;
        linkAcesso.textContent = destino.rotulo;
        linkAcesso.setAttribute("aria-label", `Acessar ${destino.rotulo}`);

        const navegacao = linkAcesso.closest(".nav-links");
        if (!navegacao || navegacao.querySelector("[data-sessao-logout]")) return;

        const sair = document.createElement("a");
        sair.href = new URL("views/acesso.html", raizAplicacao).href;
        sair.textContent = "Sair";
        sair.setAttribute("data-sessao-logout", "");
        sair.addEventListener("click", (evento) => {
            evento.preventDefault();
            encerrarSessao();
        });
        navegacao.appendChild(sair);
    }

    document.addEventListener("DOMContentLoaded", atualizarNavegacao, { once: true });
})();
