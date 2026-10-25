/* Validação guiada da publicação: destaca campos e leva o foco ao primeiro problema.
   O salvamento continua sob responsabilidade de public/js/editor.js. */
(() => {
    "use strict";

    const formulario = document.getElementById("formPublicacao");
    if (!formulario) return;

    const resumo = document.getElementById("resumoErrosPublicacao");
    const feedback = document.getElementById("feedbackPublicacao");
    const entradaImagem = document.getElementById("imagemObra");
    const previewImagem = document.getElementById("imagemObraPreview");
    const etapasContainer = document.getElementById("etapasEditor");
    const tiposAceitos = new Set(["image/jpeg", "image/png", "image/webp"]);
    const tamanhoMaximo = 7 * 1024 * 1024;
    let tentativaPublicacaoFeita = false;
    let proximoIdMensagem = 1;

    function limparMarcacoes() {
        formulario.querySelectorAll(".campo-publicacao-invalido").forEach((label) => {
            label.classList.remove("campo-publicacao-invalido");
        });
        formulario.querySelectorAll(".etapa-editor-invalida").forEach((etapa) => {
            etapa.classList.remove("etapa-editor-invalida");
        });
        formulario.querySelectorAll(".seletor-zoom-invalido").forEach((seletor) => {
            seletor.classList.remove("seletor-zoom-invalido");
        });
        formulario.querySelectorAll("[data-mensagem-validacao]").forEach((mensagem) => mensagem.remove());
        formulario.querySelectorAll('[aria-invalid="true"]').forEach((campo) => {
            campo.removeAttribute("aria-invalid");
            if (campo.dataset.descricaoAnterior !== undefined) {
                if (campo.dataset.descricaoAnterior) campo.setAttribute("aria-describedby", campo.dataset.descricaoAnterior);
                else campo.removeAttribute("aria-describedby");
                delete campo.dataset.descricaoAnterior;
            }
        });
    }

    function adicionarMensagem(container, texto, classe = "mensagem-erro-campo") {
        const mensagem = document.createElement("span");
        mensagem.className = classe;
        mensagem.dataset.mensagemValidacao = "true";
        mensagem.id = `erro-publicacao-${proximoIdMensagem++}`;
        mensagem.textContent = texto;
        container.appendChild(mensagem);
        return mensagem;
    }

    function marcarCampo(campo, texto, erros) {
        if (!campo) return;
        const label = campo.closest(".campo-publicacao");
        if (!label) return;
        const etapa = campo.closest(".etapa-editor");
        if (etapa) etapa.classList.add("etapa-editor-invalida");
        label.classList.add("campo-publicacao-invalido");
        campo.setAttribute("aria-invalid", "true");
        if (campo.dataset.descricaoAnterior === undefined) {
            campo.dataset.descricaoAnterior = campo.getAttribute("aria-describedby") || "";
        }
        const mensagem = adicionarMensagem(label, texto);
        const ids = [campo.dataset.descricaoAnterior, mensagem.id].filter(Boolean);
        campo.setAttribute("aria-describedby", ids.join(" "));
        erros.push({ texto, alvo: campo, etapa });
    }

    function marcarZoom(etapa, texto, erros) {
        const seletor = etapa?.querySelector("[data-seletor-zoom]");
        if (!seletor) return;
        seletor.classList.add("seletor-zoom-invalido");
        etapa.classList.add("etapa-editor-invalida");
        const mensagem = adicionarMensagem(seletor, texto);
        mensagem.classList.add("mensagem-erro-zoom");
        seletor.tabIndex = -1;
        erros.push({ texto, alvo: seletor, etapa });
    }

    function atualizarResumo(erros) {
        if (!resumo) return;
        resumo.replaceChildren();
        if (!erros.length) {
            resumo.hidden = true;
            return;
        }

        const titulo = document.createElement("h3");
        titulo.textContent = `Antes de publicar, corrija ${erros.length === 1 ? "este ponto" : `estes ${erros.length} pontos`}:`;
        const lista = document.createElement("ul");
        erros.forEach((erro) => {
            const item = document.createElement("li");
            item.textContent = erro.texto;
            lista.appendChild(item);
        });
        resumo.append(titulo, lista);
        resumo.hidden = false;
    }

    function validarPublicacao() {
        limparMarcacoes();
        const erros = [];
        const titulo = document.getElementById("tituloObra");
        const autor = document.getElementById("autorObra");
        const textoAlternativo = document.getElementById("altObra");
        const arquivo = entradaImagem?.files?.[0] || null;
        const obraTemImagemSalva = Boolean(previewImagem && !previewImagem.hidden && previewImagem.getAttribute("src"));

        if (!titulo?.value.trim()) marcarCampo(titulo, "Informe o nome da obra para identificá-la na galeria.", erros);
        if (!autor?.value.trim()) marcarCampo(autor, "Informe o nome do artista ou autor da obra.", erros);
        if (!textoAlternativo?.value.trim()) marcarCampo(textoAlternativo, "Descreva os elementos visuais principais para que a imagem tenha uma descrição acessível.", erros);

        if (!arquivo && !obraTemImagemSalva) {
            marcarCampo(entradaImagem, "Selecione a imagem principal da obra antes de publicar.", erros);
        } else if (arquivo && !tiposAceitos.has(arquivo.type)) {
            marcarCampo(entradaImagem, "O formato desta imagem não é aceito. Use JPEG, PNG ou WebP.", erros);
        } else if (arquivo && arquivo.size > tamanhoMaximo) {
            marcarCampo(entradaImagem, "A imagem ultrapassa 7 MB. Escolha um arquivo menor.", erros);
        }

        const etapas = Array.from(etapasContainer?.querySelectorAll(".etapa-editor") || []);
        if (!etapas.length) {
            erros.push({ texto: "Adicione pelo menos uma etapa de exploração.", alvo: document.getElementById("adicionarEtapa"), etapa: null });
        }

        etapas.forEach((etapa, indice) => {
            const numero = indice + 1;
            const tituloEtapa = etapa.querySelector('input[name$="[titulo]"]');
            const textoEtapa = etapa.querySelector('textarea[name$="[texto]"]');
            const camposZoom = ["x", "y", "largura", "altura"].map((chave) =>
                etapa.querySelector(`[data-zoom-valor="${chave}"]`)?.value ?? ""
            );

            if (!tituloEtapa?.value.trim()) marcarCampo(tituloEtapa, `Informe um título para a etapa ${numero}, explicando qual detalhe será explorado.`, erros);
            if (!textoEtapa?.value.trim()) marcarCampo(textoEtapa, `Escreva a explicação da etapa ${numero}, dizendo o que o visitante deve observar.`, erros);

            const zoomCompleto = camposZoom.every((valor) => valor !== "" && Number.isFinite(Number(valor)));
            if (!zoomCompleto) {
                marcarZoom(etapa, `Selecione uma região de zoom para a etapa ${numero}: arraste sobre a imagem para desenhar um retângulo.`, erros);
            } else {
                const [x, y, largura, altura] = camposZoom.map(Number);
                const zoomValido = x >= 0 && y >= 0 && largura > 0 && altura > 0 &&
                    largura >= 0.02 && altura >= 0.02 && x + largura <= 1.000001 && y + altura <= 1.000001;
                if (!zoomValido) marcarZoom(etapa, `A região de zoom da etapa ${numero} é inválida. Redesenhe o retângulo dentro dos limites da imagem.`, erros);
            }
        });

        atualizarResumo(erros);
        if (feedback) {
            if (erros.length) {
                feedback.textContent = "A obra ainda não foi publicada. Os campos em destaque explicam o que precisa ser corrigido.";
                feedback.dataset.tipo = "erro";
                feedback.classList.add("erro");
                feedback.classList.remove("sucesso");
            } else {
                feedback.textContent = tentativaPublicacaoFeita ? "Os dados obrigatórios estão completos. Clique em Publicar obra novamente para concluir." : "";
                feedback.dataset.tipo = "info";
                feedback.classList.remove("erro", "sucesso");
            }
        }
        return erros;
    }

    formulario.addEventListener("submit", (evento) => {
        tentativaPublicacaoFeita = true;
        const erros = validarPublicacao();
        if (!erros.length) return;

        evento.preventDefault();
        evento.stopImmediatePropagation();

        const primeiro = erros[0].alvo;
        if (primeiro?.scrollIntoView) primeiro.scrollIntoView({ behavior: "smooth", block: "center" });
        if (primeiro instanceof HTMLElement) {
            if (primeiro.matches("input, textarea, select, button")) {
                primeiro.focus({ preventScroll: true });
            } else {
                primeiro.focus({ preventScroll: true });
            }
        }
    }, true);

    function validarDepoisDeAlterar() {
        if (tentativaPublicacaoFeita) validarPublicacao();
    }
    formulario.addEventListener("input", validarDepoisDeAlterar);
    formulario.addEventListener("change", validarDepoisDeAlterar);
    formulario.addEventListener("pointerup", validarDepoisDeAlterar);
    formulario.addEventListener("click", (evento) => {
        if (evento.target.closest("[data-limpar-zoom]")) validarDepoisDeAlterar();
    });
})();
