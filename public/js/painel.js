/* Navegação das seções dos painéis e comportamentos visuais compartilhados. */
(() => {
    "use strict";

    const secoes = Array.from(document.querySelectorAll("[data-painel-secao]"));
    const botoesPainel = Array.from(document.querySelectorAll("[data-painel-target]"));

    function abrirSecao(nome) {
        const alvo = secoes.find((secao) => secao.dataset.painelSecao === nome);
        if (!alvo) return false;

        secoes.forEach((secao) => {
            const ativa = secao === alvo;
            secao.classList.toggle("ativa", ativa);
            secao.hidden = !ativa;
        });

        document.querySelectorAll(".painel-nav-item").forEach((botao) => {
            const ativo = botao.dataset.painelTarget === nome ||
                (nome === "publicar" && botao.hasAttribute("data-nova-obra"));
            botao.classList.toggle("ativa", ativo);
            if (ativo) botao.setAttribute("aria-current", "page");
            else botao.removeAttribute("aria-current");
        });

        const tituloPainel = document.getElementById("dashboardPageTitle");
        const titulos = {
            resumo: "Visão geral",
            "minhas-obras": "Minhas obras",
            publicar: document.getElementById("titulo-publicar")?.textContent || "Nova obra",
            biblioteca: "Minha biblioteca"
        };
        if (tituloPainel) tituloPainel.textContent = titulos[nome] || "Espaço do editor";

        if (!alvo.contains(document.activeElement)) {
            alvo.scrollIntoView({ behavior: "smooth", block: "start" });
        }
        document.dispatchEvent(new CustomEvent("painel:secao-aberta", {
            detail: { nome }
        }));
        return true;
    }

    // Permite que editor.js abra uma seção após salvar ou carregar uma obra.
    window.abrirSecaoPainel = abrirSecao;

    botoesPainel.forEach((botao) => {
        botao.addEventListener("click", () => abrirSecao(botao.dataset.painelTarget));
    });

    document.querySelectorAll("[data-dashboard-filter]").forEach((atalho) => {
        atalho.addEventListener("click", () => {
            abrirSecao("minhas-obras");
            const filtro = atalho.dataset.dashboardFilter;
            const botaoFiltro = document.querySelector(`[data-obras-filtro="${filtro}"]`);
            if (botaoFiltro) botaoFiltro.click();
        });
    });

    const mensagensColecao = {
        favoritos: {
            titulo: "Seus favoritos começam aqui",
            texto: "Quando você favoritar uma obra na galeria, ela aparecerá nesta coleção.",
            simbolo: "♡",
            acao: "Encontrar uma obra"
        },
        "quero-explorar": {
            titulo: "Sua próxima exploração começa aqui",
            texto: "As obras que você marcar para explorar futuramente aparecerão nesta coleção.",
            simbolo: "◷",
            acao: "Encontrar uma obra"
        },
        exploradas: {
            titulo: "Suas experiências concluídas",
            texto: "Uma obra aparecerá aqui automaticamente quando você concluir a última etapa de Explorar.",
            simbolo: "✓",
            acao: "Explorar uma obra"
        }
    };

    function trocarColecao(botao, seletorBotoes, atributo, mapa) {
        const estado = mapa[botao.dataset[atributo]];
        if (!estado) return;

        document.querySelectorAll(seletorBotoes).forEach((item) => {
            const ativo = item === botao;
            item.classList.toggle("ativa", ativo);
            item.setAttribute("aria-selected", String(ativo));
        });

        const conteudo = botao.closest("section")?.querySelector(".colecao-conteudo");
        if (!conteudo) return;
        const titulo = conteudo.querySelector(".estado-vazio h3");
        const texto = conteudo.querySelector(".estado-vazio p");
        const simbolo = conteudo.querySelector(".estado-vazio-simbolo");
        const link = conteudo.querySelector(".estado-vazio a");
        if (titulo) titulo.textContent = estado.titulo;
        if (texto) texto.textContent = estado.texto;
        if (simbolo) simbolo.textContent = estado.simbolo;
        if (link) link.textContent = estado.acao;
    }

    document.querySelectorAll("[data-colecao]").forEach((botao) => {
        botao.addEventListener("click", () =>
            trocarColecao(botao, "[data-colecao]", "colecao", mensagensColecao)
        );
    });

    const mensagensBibliotecaEditor = {
        favoritos: { titulo: "Seus favoritos", texto: "As obras que você favoritar aparecerão aqui.", simbolo: "♡" },
        "quero-explorar": { titulo: "Quero explorar", texto: "As obras salvas para uma próxima visita aparecerão nesta coleção.", simbolo: "◷" },
        exploradas: { titulo: "Já explorei", texto: "As experiências concluídas aparecerão aqui automaticamente.", simbolo: "✓" }
    };

    document.querySelectorAll("[data-biblioteca-editor]").forEach((botao) => {
        botao.addEventListener("click", () =>
            trocarColecao(botao, "[data-biblioteca-editor]", "bibliotecaEditor", mensagensBibliotecaEditor)
        );
    });

    document.querySelectorAll("[data-filtro-relato]").forEach((botao) => {
        botao.addEventListener("click", () => {
            document.querySelectorAll("[data-filtro-relato]").forEach((item) =>
                item.classList.toggle("ativa", item === botao)
            );
        });
    });

    document.querySelectorAll("[data-acao-admin]").forEach((botao) => {
        botao.addEventListener("click", () => {
            const mensagem = botao.dataset.acaoAdmin === "convite"
                ? "A criação e gravação do convite serão conectadas à API administrativa na próxima etapa."
                : "A consulta e alteração das permissões serão conectadas à API administrativa na próxima etapa.";
            window.alert(mensagem);
        });
    });

    // O cabeçalho global (sessao-ui.js) é o único responsável pelo logout.
    // Isso evita dois handlers concorrentes nas páginas de painel.

})();
