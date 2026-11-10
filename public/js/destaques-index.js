/*
 * Atualiza a página inicial com a seleção definida pelo administrador.
 * A mesma lista alimenta a composição do topo e a seção "Obras".
 */
(() => {
    "use strict";

    const composicao = document.querySelector(".obras-destaque");
    const gradeObras = document.querySelector(".obras-grid");
    const urlApi = "api/obras/destaques.php";
    const urlImagem = "api/obras/imagem-publica.php";

    if (!composicao && !gradeObras) return;

    function criarEstado(mensagem, classe) {
        const estado = document.createElement("p");
        estado.className = classe;
        estado.textContent = mensagem;
        return estado;
    }

    function normalizarObras(obras) {
        const ids = new Set();
        return obras.slice(0, 3).filter((obra) => {
            const id = Number(obra?.id);
            if (!Number.isInteger(id) || id < 1 || ids.has(id)) return false;
            ids.add(id);
            return true;
        }).map((obra) => ({
            id: Number(obra.id),
            titulo: String(obra.titulo || "Obra sem título"),
            autor: String(obra.autor || "Autoria não informada"),
            textoAlternativo: String(obra.texto_alternativo || ""),
            totalEtapas: Number(obra.total_etapas) || 0
        }));
    }

    function criarImagem(obra) {
        const imagem = document.createElement("img");
        imagem.src = `${urlImagem}?id=${encodeURIComponent(obra.id)}`;
        imagem.alt = obra.textoAlternativo || `${obra.titulo}, de ${obra.autor}.`;
        imagem.loading = "lazy";
        imagem.decoding = "async";
        imagem.addEventListener("error", () => {
            imagem.removeAttribute("src");
            imagem.alt = `Imagem indisponível: ${obra.titulo}.`;
            imagem.classList.add("imagem-obra-indisponivel");
        }, { once: true });
        return imagem;
    }

    function criarLegenda(obra) {
        const legenda = document.createElement("div");
        legenda.className = "obra-legenda";

        const titulo = document.createElement("strong");
        titulo.textContent = obra.titulo;

        const autor = document.createElement("span");
        autor.textContent = obra.autor;

        legenda.append(titulo, autor);
        return legenda;
    }

    function renderizarComposicao(obras) {
        if (!composicao) return;

        composicao.replaceChildren();
        composicao.setAttribute("aria-busy", "false");
        composicao.classList.toggle("sem-obras-destaque", obras.length === 0);

        if (obras.length === 0) {
            composicao.appendChild(criarEstado(
                "Nenhuma obra foi selecionada para os destaques.",
                "obras-destaque-vazio"
            ));
            return;
        }

        const posicoes = obras.length === 1
            ? ["obra-centro"]
            : obras.length === 2
                ? ["obra-esquerda", "obra-direita"]
                : ["obra-esquerda", "obra-centro", "obra-direita"];

        obras.forEach((obra, indice) => {
            const cartao = document.createElement("a");
            cartao.className = `obra ${posicoes[indice]} obra-com-legenda`;
            cartao.href = `views/galeria.html?id=${encodeURIComponent(obra.id)}`;
            cartao.setAttribute("aria-label", `Explorar ${obra.titulo}, de ${obra.autor}`);
            cartao.append(criarImagem(obra), criarLegenda(obra));
            composicao.appendChild(cartao);
        });
    }

    function criarCartaoCatalogo(obra) {
        const cartao = document.createElement("article");
        cartao.className = "obra-card";
        cartao.dataset.obraId = String(obra.id);

        const areaImagem = document.createElement("div");
        areaImagem.className = "obra-card-imagem";

        const linkImagem = document.createElement("a");
        linkImagem.href = `views/galeria.html?id=${encodeURIComponent(obra.id)}`;
        linkImagem.setAttribute("aria-label", `Explorar ${obra.titulo}, de ${obra.autor}`);
        linkImagem.appendChild(criarImagem(obra));
        areaImagem.appendChild(linkImagem);

        const conteudo = document.createElement("div");
        conteudo.className = "obra-card-conteudo";

        const autor = document.createElement("span");
        autor.className = "obra-artista";
        autor.textContent = obra.autor;

        const titulo = document.createElement("h3");
        titulo.textContent = obra.titulo;

        const etapas = document.createElement("p");
        etapas.className = "obra-card-etapas";
        etapas.textContent = `${obra.totalEtapas} ${obra.totalEtapas === 1 ? "etapa" : "etapas"} para explorar`;

        const linkExplorar = document.createElement("a");
        linkExplorar.className = "obra-link";
        linkExplorar.href = `views/galeria.html?id=${encodeURIComponent(obra.id)}`;
        linkExplorar.textContent = "Explorar obra →";

        conteudo.append(autor, titulo, etapas, linkExplorar);
        cartao.append(areaImagem, conteudo);
        return cartao;
    }

    function renderizarCatalogo(obras) {
        if (!gradeObras) return;

        gradeObras.replaceChildren();
        gradeObras.setAttribute("aria-busy", "false");
        gradeObras.dataset.totalObras = String(obras.length);

        if (obras.length === 0) {
            gradeObras.appendChild(criarEstado(
                "As obras escolhidas pelo administrador aparecerão aqui.",
                "obras-index-vazio"
            ));
            return;
        }

        obras.forEach((obra) => gradeObras.appendChild(criarCartaoCatalogo(obra)));
    }

    function renderizarFalha() {
        const mensagem = "Não foi possível carregar as obras neste momento. Atualize a página para tentar novamente.";
        if (composicao) {
            composicao.replaceChildren(criarEstado(mensagem, "obras-destaque-vazio"));
            composicao.setAttribute("aria-busy", "false");
        }
        if (gradeObras) {
            gradeObras.replaceChildren(criarEstado(mensagem, "obras-index-vazio"));
            gradeObras.setAttribute("aria-busy", "false");
        }
    }

    async function carregarObrasSelecionadas() {
        if (composicao) composicao.setAttribute("aria-busy", "true");
        if (gradeObras) gradeObras.setAttribute("aria-busy", "true");

        try {
            const resposta = await fetch(urlApi, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" }
            });

            if (!resposta.ok) throw new Error(`HTTP ${resposta.status}`);
            const dados = await resposta.json();
            if (dados.sucesso !== true || !Array.isArray(dados.obras)) {
                throw new Error("A API retornou uma lista de obras inválida.");
            }

            const obras = normalizarObras(dados.obras);
            renderizarComposicao(obras);
            renderizarCatalogo(obras);
        } catch (erro) {
            console.error("Não foi possível atualizar as obras da página inicial:", erro);
            renderizarFalha();
        }
    }

    carregarObrasSelecionadas();
})();
