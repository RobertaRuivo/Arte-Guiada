/*
 * Verificação de sessão para as páginas internas.
 * Isto melhora o fluxo de navegação, mas não substitui a validação
 * de perfil que cada endpoint PHP protegido deverá executar.
 */
(() => {
    const corpo = document.body;
    const perfisPermitidos = (corpo.dataset.perfisPermitidos || "")
        .split(",")
        .map((perfil) => perfil.trim().toLowerCase())
        .filter(Boolean);

    const paginasPorPerfil = {
        visitante: "visitante.html",
        editor: "editor.html",
        admin: "admin.html"
    };

    async function verificarAcesso() {
        try {
            const resposta = await fetch("../api/login/sessao.php", {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Accept": "application/json" }
            });

            const dados = await resposta.json();

            if (!resposta.ok || !dados.autenticado || !dados.usuario) {
                window.location.replace("acesso.html");
                return;
            }

            const perfil = String(dados.usuario.perfil || "").trim().toLowerCase();

            if (!perfisPermitidos.includes(perfil)) {
                const destino = paginasPorPerfil[perfil];
                window.location.replace(destino || "acesso.html");
                return;
            }

            document.querySelectorAll("[data-usuario-nome]").forEach((elemento) => {
                elemento.textContent = dados.usuario.nome || "usuário";
            });

            corpo.dataset.usuarioPerfil = perfil;
            corpo.classList.add("acesso-verificado");
        } catch (erro) {
            // Em caso de falha de rede, não deixamos a tela interna aberta.
            window.location.replace("acesso.html");
        }
    }

    verificarAcesso();
})();
