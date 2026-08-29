/* =========================================================
   ARTE GUIADA
   GALERIA EDITORIAL
   ========================================================= */


/* =========================================================
   1. DADOS DA GALERIA
   ========================================================= */

/*
   Para adicionar outra obra:

   1. altere artist
   2. altere title
   3. altere image
   4. substitua os detalhes

   Não é necessário alterar o HTML.
   Não é necessário alterar o CSS.
   Não é necessário criar novos hotspots.
*/

const galleryData = {

    artist: "Anita Malfatti",

    title: "A Boba",

    image: "../public/img/a_boba.jpg",

    imageAlt:
        "A Boba, de Anita Malfatti, representada em uma composição de cores intensas.",

    details: [

        {
            title: "O rosto",

            description:
                "Uma técnica de pinceladas fortes e cores vivas aparece com destaque na região dos olhos e da expressão.",

            /*
               Posição do enquadramento.

               50 = centro
               0  = esquerda / topo
               100 = direita / baixo
            */

            focusX: 48,
            focusY: 35,

            zoom: 1.65
        },

        {

            title: "A assinatura",

            description:
                "Na região inferior da pintura, a assinatura da artista aparece integrada aos tons mais escuros da composição. Observe como ela se relaciona visualmente com o restante da obra.",

            focusX: 52,
            focusY: 88,

            zoom: 1.8

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


/* =========================================================
   4. INICIALIZAÇÃO
   ========================================================= */

function initializeGallery() {

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
        "";

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
        String(currentIndex + 1).padStart(2, "0");

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
            ((currentIndex + 1) /
                galleryData.details.length) *
            100;


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
        "";


    applyImageFocus(
        hiddenImage,
        nextDetail
    );


    /*
       A nova camada começa a aparecer.
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
       Finaliza o morph.
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
           Não interceptamos teclas enquanto
           o usuário está digitando.
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