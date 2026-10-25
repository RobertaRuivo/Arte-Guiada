/* =========================================================
   ARTE GUIADA
   GALERIA EDITORIAL
   ========================================================= */


/* =========================================================
   1. DADOS DA GALERIA
   ========================================================= */

const galleryScriptUrl = document.currentScript?.src ? new URL(document.currentScript.src) : new URL("../public/js/galeria.js", window.location.href);

const galleryData = {

    id: 3,

    artist: "Anita Malfatti",

    title: "A Boba",

    image: "../public/img/a_boba.jpg",

    imageAlt:
        "A Boba, de Anita Malfatti, representada em uma composição de cores intensas.",

    details: [

        {
            title: "O rosto",

            description:
             "Podemos observar o rosto da jovem como um dos elementos de maior destaque da obra. Sua expressão transmite uma sensação de introspecção e, ao mesmo tempo, certo estranhamento. Isso é reforçado pelas formas marcadas do rosto, pelo olhar e pelo uso de cores intensas. A maneira como Anita Malfatti representa a figura não busca reproduzir fielmente a aparência de uma pessoa. As formas são simplificadas e expressivas, enquanto as cores e as pinceladas ajudam a transmitir uma sensação e não apenas a aparência física da personagem. Essas características revelam a influência do expressionismo, com o qual Malfatti teve contato durante seus estudos no exterior. A influência estrangeira, porém, não significa uma simples reprodução do que era produzido na Europa. A experiência de Malfatti contribuiu para que ela desenvolvesse uma linguagem própria e participasse do processo de renovação da arte brasileira que marcou o modernismo.",

            /*
               Região do rosto da personagem.
            */

            focusX: 70,
            focusY: 35,

            zoom: 2.0
        },

        {
            title: "A assinatura",

            description:
                "No canto da tela, encontramos também a assinatura da artista. Ela aparece de forma discreta, em letra cursiva, integrada à composição sem chamar mais atenção do que os elementos principais da pintura.",

            /*
               A assinatura está no canto inferior
               esquerdo da pintura.

               O zoom foi removido para preservar
               uma área maior da composição.
            */

            focusX: 0,
            focusY: 100,

            zoom: 1.0
        }

    ]

};


/* =========================================================
   2. ELEMENTOS
   ========================================================= */

const currentLayer =
    document.getElementById("artworkCurrent");

const nextLayer =
    document.getElementById("artworkNext");

const currentImage =
    document.getElementById("currentImage");

const nextImage =
    document.getElementById("nextImage");

const narrativeSection =
    document.querySelector(".narrative-section");

const artistName =
    document.getElementById("artistName");

const artworkTitle =
    document.getElementById("artworkTitle");

const artworkCaption =
    document.getElementById("artworkCaption");

const detailNumber =
    document.getElementById("detailNumber");

const detailTitle =
    document.getElementById("detailTitle");

const detailDescription =
    document.getElementById("detailDescription");

const slideCounter =
    document.getElementById("slideCounter");

const previousButton =
    document.getElementById("previousButton");

const nextButton =
    document.getElementById("nextButton");

const progressBar =
    document.getElementById("progressBar");

const progressFill =
    document.getElementById("progressFill");

const progressText =
    document.getElementById("progressText");

const viewCompleteButton =
    document.getElementById("viewCompleteButton");

const completeArtwork =
    document.getElementById("completeArtwork");

const completeImage =
    document.getElementById("completeImage");

const completeArtist =
    document.getElementById("completeArtist");

const completeTitle =
    document.getElementById("completeTitle");

const closeCompleteButton =
    document.getElementById("closeCompleteButton");

const fullscreenButton =
    document.getElementById("fullscreenButton");

const artworkFrame =
    document.querySelector(".artwork-frame");


/* =========================================================
   3. ESTADO
   ========================================================= */

let currentIndex = 0;

let isTransitioning = false;
let bibliotecaEstado = { favorito: false, quero_explorar: false, explorada: false };
let exploracaoRegistradaNestaSessao = false;
let bibliotecaToolbar = null;


/* =========================================================
   3A. BIBLIOTECA PESSOAL
   ========================================================= */

function urlApiBiblioteca(arquivo) {
    return new URL(`../../api/biblioteca/${arquivo}.php`, galleryScriptUrl).href;
}

function carregarEstiloBibliotecaGaleria() {
    if (document.getElementById("estilo-biblioteca-galeria")) return;
    const link = document.createElement("link");
    link.id = "estilo-biblioteca-galeria";
    link.rel = "stylesheet";
    link.href = new URL("../css/galeria-biblioteca.css", galleryScriptUrl).href;
    document.head.appendChild(link);
}

function mostrarFeedbackBiblioteca(mensagem, precisaLogin = false) {
    if (!bibliotecaToolbar) return;
    const area = bibliotecaToolbar.querySelector("[data-biblioteca-feedback]");
    if (!area) return;
    area.replaceChildren(document.createTextNode(mensagem));
    if (precisaLogin) {
        area.appendChild(document.createTextNode(" "));
        const link = document.createElement("a");
        link.className = "biblioteca-link-entrar";
        link.href = new URL("../../views/acesso.html", galleryScriptUrl).href;
        link.textContent = "Entrar";
        area.appendChild(link);
    }
}

function atualizarBotoesBiblioteca() {
    if (!bibliotecaToolbar) return;
    const favorito = bibliotecaToolbar.querySelector('[data-biblioteca-acao="favorito"]');
    const quero = bibliotecaToolbar.querySelector('[data-biblioteca-acao="quero_explorar"]');
    if (favorito) {
        favorito.setAttribute("aria-pressed", String(bibliotecaEstado.favorito));
        favorito.textContent = bibliotecaEstado.favorito ? "♥ Nos favoritos" : "♡ Favoritar";
    }
    if (quero) {
        quero.setAttribute("aria-pressed", String(bibliotecaEstado.quero_explorar));
        quero.textContent = bibliotecaEstado.quero_explorar ? "✓ Na lista Quero ver" : "+ Quero ver";
    }
}

async function consultarEstadoBiblioteca() {
    try {
        const resposta = await fetch(`${urlApiBiblioteca("estado")}?obra_id=${encodeURIComponent(galleryData.id)}`, {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Accept": "application/json" }
        });
        const dados = await resposta.json().catch(() => null);
        if (resposta.status === 401) {
            mostrarFeedbackBiblioteca("Entre na sua conta para salvar esta obra na biblioteca.", true);
            return;
        }
        if (!resposta.ok || !dados?.sucesso || !dados.estado) {
            throw new Error(dados?.mensagem || "Não foi possível consultar a biblioteca.");
        }
        bibliotecaEstado = {
            favorito: Boolean(dados.estado.favorito),
            quero_explorar: Boolean(dados.estado.quero_explorar),
            explorada: Boolean(dados.estado.explorada)
        };
        exploracaoRegistradaNestaSessao = bibliotecaEstado.explorada;
        atualizarBotoesBiblioteca();
        mostrarFeedbackBiblioteca(bibliotecaEstado.explorada
            ? "Você já concluiu esta obra. Ela está em Já explorei."
            : "Salve esta obra nos favoritos ou na sua lista Quero ver.");
    } catch (erro) {
        mostrarFeedbackBiblioteca("Não foi possível carregar o estado da biblioteca. Você ainda pode continuar explorando.");
    }
}

async function alterarColecaoBiblioteca(acao) {
    const ativo = !Boolean(bibliotecaEstado[acao]);
    const botoes = bibliotecaToolbar?.querySelectorAll("button[data-biblioteca-acao]") || [];
    botoes.forEach((botao) => { botao.disabled = true; });
    try {
        const resposta = await fetch(urlApiBiblioteca("atualizar"), {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ obra_id: galleryData.id, acao, ativo })
        });
        const dados = await resposta.json().catch(() => null);
        if (resposta.status === 401) {
            mostrarFeedbackBiblioteca("Entre na sua conta para guardar suas escolhas.", true);
            return;
        }
        if (!resposta.ok || !dados?.sucesso || !dados.estado) {
            throw new Error(dados?.mensagem || "Não foi possível atualizar sua biblioteca.");
        }
        bibliotecaEstado = {
            favorito: Boolean(dados.estado.favorito),
            quero_explorar: Boolean(dados.estado.quero_explorar),
            explorada: Boolean(dados.estado.explorada)
        };
        atualizarBotoesBiblioteca();
        mostrarFeedbackBiblioteca(ativo
            ? (acao === "favorito" ? "Obra adicionada aos favoritos." : "Obra adicionada à lista Quero ver.")
            : (acao === "favorito" ? "Obra removida dos favoritos." : "Obra removida da lista Quero ver."));
    } catch (erro) {
        mostrarFeedbackBiblioteca(erro.message || "Não foi possível salvar sua escolha.");
    } finally {
        botoes.forEach((botao) => { botao.disabled = false; });
        atualizarBotoesBiblioteca();
    }
}

function montarControlesBiblioteca() {
    if (bibliotecaToolbar || !narrativeSection) return;
    carregarEstiloBibliotecaGaleria();

    const caixa = document.createElement("section");
    caixa.className = "biblioteca-obra-acoes";
    caixa.setAttribute("aria-label", "Salvar obra na biblioteca pessoal");

    const texto = document.createElement("div");
    texto.className = "biblioteca-acoes-texto";
    const titulo = document.createElement("strong");
    titulo.textContent = "Guarde esta obra";
    const feedback = document.createElement("p");
    feedback.dataset.bibliotecaFeedback = "";
    feedback.setAttribute("role", "status");
    feedback.setAttribute("aria-live", "polite");
    texto.append(titulo, feedback);

    const botoes = document.createElement("div");
    botoes.className = "biblioteca-acoes-botoes";
    const favorito = document.createElement("button");
    favorito.type = "button";
    favorito.dataset.bibliotecaAcao = "favorito";
    favorito.setAttribute("aria-pressed", "false");
    favorito.textContent = "♡ Favoritar";
    favorito.addEventListener("click", () => alterarColecaoBiblioteca("favorito"));

    const quero = document.createElement("button");
    quero.type = "button";
    quero.dataset.bibliotecaAcao = "quero_explorar";
    quero.setAttribute("aria-pressed", "false");
    quero.textContent = "+ Quero ver";
    quero.addEventListener("click", () => alterarColecaoBiblioteca("quero_explorar"));

    const biblioteca = document.createElement("a");
    biblioteca.href = new URL("../../views/biblioteca.html", galleryScriptUrl).href;
    biblioteca.textContent = "Minha biblioteca";

    botoes.append(favorito, quero, biblioteca);
    caixa.append(texto, botoes);
    narrativeSection.insertAdjacentElement("afterend", caixa);
    bibliotecaToolbar = caixa;
    consultarEstadoBiblioteca();
}

async function registrarExploracaoConcluida() {
    if (exploracaoRegistradaNestaSessao || !galleryData.id) return;
    try {
        const resposta = await fetch(urlApiBiblioteca("concluir"), {
            method: "POST",
            credentials: "same-origin",
            cache: "no-store",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ obra_id: galleryData.id, etapas_concluidas: galleryData.details.length })
        });
        const dados = await resposta.json().catch(() => null);
        if (resposta.status === 401) {
            mostrarFeedbackBiblioteca("Você chegou ao final da obra. Entre para guardar essa conclusão em Já explorei.", true);
            return;
        }
        if (!resposta.ok || !dados?.sucesso) {
            throw new Error(dados?.mensagem || "Não foi possível registrar a conclusão.");
        }
        exploracaoRegistradaNestaSessao = true;
        bibliotecaEstado.explorada = true;
        mostrarFeedbackBiblioteca("Experiência concluída. A obra foi adicionada a Já explorei.");
    } catch (erro) {
        mostrarFeedbackBiblioteca(erro.message || "Você concluiu a obra, mas não foi possível salvar a conclusão agora.");
    }
}

/* =========================================================
   4. INICIALIZAÇÃO
   ========================================================= */

function initializeGallery() {

    montarControlesBiblioteca();

    artistName.textContent =
        galleryData.artist;

    artworkTitle.textContent =
        galleryData.title;


    currentImage.src =
        galleryData.image;

    currentImage.alt =
        galleryData.imageAlt;


    nextImage.src =
        galleryData.image;

    nextImage.alt =
        galleryData.imageAlt;


    completeImage.src =
        galleryData.image;

    completeImage.alt =
        galleryData.imageAlt;


    completeArtist.textContent =
        galleryData.artist;

    completeTitle.textContent =
        galleryData.title;


    applyImageFocus(
        currentImage,
        galleryData.details[currentIndex]
    );


    updateContent(false);

}


/* =========================================================
   5. ENQUADRAMENTO
   ========================================================= */

function applyImageFocus(image, detail) {

    if (!detail) {
        return;
    }


    image.style.setProperty(
        "--focus-x",
        `${detail.focusX}%`
    );


    image.style.setProperty(
        "--focus-y",
        `${detail.focusY}%`
    );


    image.style.setProperty(
        "--zoom",
        detail.zoom
    );

}


/* =========================================================
   6. ATUALIZAÇÃO DO CONTEÚDO
   ========================================================= */

function updateContent(animate = true) {

    const detail =
        galleryData.details[currentIndex];


    if (!detail) {
        return;
    }


    const number =
        String(currentIndex + 1)
            .padStart(2, "0");


    const total =
        String(galleryData.details.length)
            .padStart(2, "0");


    if (animate) {

        narrativeSection.classList.remove(
            "is-entering"
        );


        narrativeSection.classList.add(
            "is-changing"
        );

    }


    setTimeout(() => {

        detailNumber.textContent =
            number;


        detailTitle.textContent =
            detail.title;


        detailDescription.textContent =
            detail.description;


        slideCounter.textContent =
            `${number} / ${total}`;


        progressText.textContent =
            `${currentIndex + 1} de ${galleryData.details.length} detalhes`;


        const progress =
            (
                (currentIndex + 1) /
                galleryData.details.length
            ) * 100;


        progressFill.style.width =
            `${progress}%`;


        progressBar.setAttribute(
            "aria-valuemax",
            galleryData.details.length
        );


        progressBar.setAttribute(
            "aria-valuenow",
            currentIndex + 1
        );


        /*
           Durante o detalhe da assinatura,
           a legenda é ocultada porque ocupa
           a região inferior da pintura.
        */

        if (currentIndex === 1) {

            artworkCaption.style.display =
                "none";

        } else {

            artworkCaption.style.display =
                "flex";

        }


        updateNavigation();


        if (animate) {

            narrativeSection.classList.remove(
                "is-changing"
            );


            narrativeSection.classList.add(
                "is-entering"
            );

        }

    }, animate ? 220 : 0);

}


/* =========================================================
   7. NAVEGAÇÃO
   ========================================================= */

function updateNavigation() {

    previousButton.disabled =
        currentIndex === 0;


    nextButton.disabled =
        currentIndex ===
        galleryData.details.length - 1;

}


/* =========================================================
   8. MUDANÇA DE SLIDE
   ========================================================= */

function goToSlide(index) {

    if (isTransitioning) {
        return;
    }


    if (
        index < 0 ||
        index >= galleryData.details.length
    ) {
        return;
    }


    if (index === currentIndex) {
        return;
    }


    const nextDetail =
        galleryData.details[index];


    isTransitioning = true;


    /*
       Descobre qual camada está atualmente
       visível.
    */

    const visibleLayer =
        document.querySelector(
            ".artwork-layer-current"
        );


    const hiddenLayer =
        document.querySelector(
            ".artwork-layer-next"
        );


    const visibleImage =
        visibleLayer.querySelector("img");


    const hiddenImage =
        hiddenLayer.querySelector("img");


    /*
       Prepara a próxima imagem.
    */

    hiddenImage.src =
        galleryData.image;


    hiddenImage.alt =
        galleryData.imageAlt;


    /*
       Aplica o enquadramento do próximo detalhe.
    */

    applyImageFocus(
        hiddenImage,
        nextDetail
    );


    /*
       Inicia a transição.
    */

    artworkFrame.classList.add(
        "is-transitioning"
    );


    /*
       Atualiza o conteúdo textual.
    */

    currentIndex = index;

    updateContent(true);


    /*
       Finaliza a transição.
    */

    setTimeout(() => {

        /*
           A camada nova se torna a atual.
        */

        visibleLayer.classList.remove(
            "artwork-layer-current"
        );


        visibleLayer.classList.add(
            "artwork-layer-next"
        );


        hiddenLayer.classList.remove(
            "artwork-layer-next"
        );


        hiddenLayer.classList.add(
            "artwork-layer-current"
        );


        /*
           Remove o estado de transição.
        */

        artworkFrame.classList.remove(
            "is-transitioning"
        );


        /*
           Deixa a antiga camada pronta
           para receber o próximo slide.
        */

        visibleImage.style.removeProperty(
            "--focus-x"
        );


        visibleImage.style.removeProperty(
            "--focus-y"
        );


        visibleImage.style.removeProperty(
            "--zoom"
        );


        isTransitioning = false;

        if (currentIndex === galleryData.details.length - 1) {
            registrarExploracaoConcluida();
        }

    }, 900);

}


/* =========================================================
   9. BOTÕES
   ========================================================= */

previousButton.addEventListener(
    "click",
    () => {

        goToSlide(
            currentIndex - 1
        );

    }
);


nextButton.addEventListener(
    "click",
    () => {

        goToSlide(
            currentIndex + 1
        );

    }
);


/* =========================================================
   10. TECLADO
   ========================================================= */

document.addEventListener(
    "keydown",
    (event) => {

        /*
           Não intercepta as teclas enquanto
           o usuário estiver digitando.
        */

        const tag =
            document.activeElement.tagName;


        if (
            tag === "INPUT" ||
            tag === "TEXTAREA" ||
            tag === "SELECT"
        ) {
            return;
        }


        if (event.key === "ArrowRight") {

            event.preventDefault();


            goToSlide(
                currentIndex + 1
            );

        }


        if (event.key === "ArrowLeft") {

            event.preventDefault();


            goToSlide(
                currentIndex - 1
            );

        }


        if (event.key === "Escape") {

            closeCompleteArtwork();

        }

    }
);


/* =========================================================
   11. OBRA COMPLETA
   ========================================================= */

function openCompleteArtwork() {

    completeArtwork.classList.add(
        "is-open"
    );


    completeArtwork.setAttribute(
        "aria-hidden",
        "false"
    );


    document.body.style.overflow =
        "hidden";


    closeCompleteButton.focus();

}


function closeCompleteArtwork() {

    completeArtwork.classList.remove(
        "is-open"
    );


    completeArtwork.setAttribute(
        "aria-hidden",
        "true"
    );


    document.body.style.overflow =
        "";

}


viewCompleteButton.addEventListener(
    "click",
    openCompleteArtwork
);


closeCompleteButton.addEventListener(
    "click",
    closeCompleteArtwork
);


/*
   Clique no fundo também fecha.
*/

completeArtwork.addEventListener(
    "click",
    (event) => {

        if (
            event.target ===
            completeArtwork
        ) {

            closeCompleteArtwork();

        }

    }
);


/* =========================================================
   12. TELA CHEIA
   ========================================================= */

fullscreenButton.addEventListener(
    "click",
    async () => {

        try {

            if (!document.fullscreenElement) {

                await document.documentElement
                    .requestFullscreen();

            } else {

                await document.exitFullscreen();

            }

        } catch (error) {

            console.error(
                "Não foi possível alterar o modo de tela cheia.",
                error
            );

        }

    }
);


/* =========================================================
   13. INICIALIZA
   ========================================================= */

initializeGallery();