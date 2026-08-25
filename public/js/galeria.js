document.addEventListener("DOMContentLoaded", () => {

    // ========================================================
    // ELEMENTOS DA INTERFACE
    // PRESERVADO
    // ========================================================

    const hotspots =
        Array.from(
            document.querySelectorAll('.hotspot')
        );

    const zoomContainer =
        document.getElementById(
            'zoom-container'
        );

    const tourPanel =
        document.getElementById(
            'tour-panel'
        );

    const tourIntro =
        document.getElementById(
            'tour-intro'
        );


    const titleEl =
        document.getElementById(
            'detail-title'
        );

    const textEl =
        document.getElementById(
            'detail-text'
        );

    const counterEl =
        document.getElementById(
            'tour-counter'
        );

    const conclusionEl =
        document.getElementById(
            'tour-conclusion'
        );


    const btnIniciar =
        document.getElementById(
            'btn-iniciar-visita'
        );

    const btnSair =
        document.getElementById(
            'btn-sair-visita'
        );

    const btnPrev =
        document.getElementById(
            'btn-prev'
        );

    const btnNext =
        document.getElementById(
            'btn-next'
        );

    const btnVoltar =
        document.getElementById(
            'btn-voltar'
        );


    const progressBar =
        document.getElementById(
            'tour-progress-bar'
        );

    const progressText =
        document.getElementById(
            'progress-text'
        );


    // ========================================================
    // ESTADO DA APLICAÇÃO
    // PRESERVADO
    // ========================================================

    let isTourMode = false;

    let currentDetailIndex = -1;

    const exploredDetails =
        new Set();

    const totalDetails =
        hotspots.length;


    // ========================================================
    // TEXTOS INICIAIS
    // PRESERVADO
    // ========================================================

    const initialTitle =
        "A Boba";

    const initialText =
        "Selecione os pontos pulsantes ou utilize os controles abaixo para explorar os detalhes.";


    // ========================================================
    // EVENTOS INICIAIS
    // PRESERVADO
    // ========================================================

    btnIniciar.addEventListener(
        'click',
        startTour
    );


    btnSair.addEventListener(
        'click',
        exitTour
    );


    btnPrev.addEventListener(
        'click',
        () => {
            goToDetail(
                currentDetailIndex - 1
            );
        }
    );


    btnNext.addEventListener(
        'click',
        () => {

            goToDetail(
                currentDetailIndex === -1
                    ? 0
                    : currentDetailIndex + 1
            );

        }
    );


    btnVoltar.addEventListener(
        'click',
        showFullArtwork
    );


    // ========================================================
    // HOTSPOTS
    // PRESERVADO
    // ========================================================

    hotspots.forEach(
        (hotspot) => {

            hotspot.addEventListener(
                'click',
                (e) => {

                    const index =
                        parseInt(
                            e.target.getAttribute(
                                'data-index'
                            )
                        );


                    if (!isTourMode) {

                        startTour();

                    }


                    goToDetail(index);

                }
            );

        }
    );


    // ========================================================
    // ESC — SAIR DA VISITA
    // PRESERVADO
    // ========================================================

    document.addEventListener(
        'keydown',
        (e) => {

            if (
                e.key === 'Escape' &&
                isTourMode
            ) {

                exitTour();

            }

        }
    );


    // ========================================================
    // START TOUR
    //
    // PRESERVADO:
    // Toda a lógica original continua.
    //
    // ADICIONADO:
    // CustomEvent("tourStarted")
    //
    // Esse evento apenas avisa ao React que a visita começou.
    // ========================================================

    function startTour() {

        isTourMode = true;

        document.body.classList.add(
            'modo-visita'
        );

        tourIntro.style.display =
            'none';

        tourPanel.style.display =
            'flex';

        showFullArtwork();


        // ----------------------------------------------------
        // Foco inicial.
        // PRESERVADO.
        // ----------------------------------------------------

        setTimeout(
            () => {
                counterEl.focus();
            },
            100
        );


        // ====================================================
        // ADICIONADO — COMUNICAÇÃO COM REACT
        // ====================================================

        window.dispatchEvent(
            new CustomEvent(
                'tourStarted'
            )
        );

    }


    // ========================================================
    // EXIT TOUR
    //
    // PRESERVADO:
    // Toda a lógica original continua.
    //
    // ADICIONADO:
    // CustomEvent("tourExited")
    // ========================================================

    function exitTour() {

        isTourMode = false;

        document.body.classList.remove(
            'modo-visita'
        );

        tourPanel.style.display =
            'none';

        tourIntro.style.display =
            'flex';


        // ----------------------------------------------------
        // Remove zoom.
        // PRESERVADO.
        // ----------------------------------------------------

        zoomContainer.style.transform =
            'translate(0px, 0px) scale(1)';

        zoomContainer.style.setProperty(
            '--current-scale',
            '1'
        );


        // ====================================================
        // ADICIONADO — COMUNICAÇÃO COM REACT
        // ====================================================

        window.dispatchEvent(
            new CustomEvent(
                'tourExited'
            )
        );


        // ----------------------------------------------------
        // Retorno de foco.
        // PRESERVADO.
        // ----------------------------------------------------

        btnIniciar.focus();

    }


    // ========================================================
    // NAVEGAÇÃO
    // PRESERVADO
    // ========================================================

    function goToDetail(index) {

        if (
            index < 0 ||
            index >= totalDetails
        ) {

            return;

        }


        currentDetailIndex =
            index;


        const hotspot =
            hotspots[index];


        // ----------------------------------------------------
        // Atualiza progresso.
        // ----------------------------------------------------

        exploredDetails.add(
            index
        );


        hotspot.classList.add(
            'explored'
        );


        updateProgress();


        // ----------------------------------------------------
        // Atualiza hotspot ativo.
        // ----------------------------------------------------

        hotspots.forEach(
            (h) => {

                h.classList.remove(
                    'active-detail'
                );

            }
        );


        hotspot.classList.add(
            'active-detail'
        );


        // ----------------------------------------------------
        // Atualiza texto.
        // ----------------------------------------------------

        titleEl.textContent =
            hotspot.getAttribute(
                'data-title'
            );


        textEl.textContent =
            hotspot.getAttribute(
                'data-text'
            );


        counterEl.textContent =
            `Detalhe ${index + 1} de ${totalDetails}`;


        // ----------------------------------------------------
        // Atualiza navegação.
        // ----------------------------------------------------

        btnPrev.disabled =
            index === 0;


        btnNext.disabled =
            index === totalDetails - 1;


        btnVoltar.style.display =
            'inline-block';


        // ----------------------------------------------------
        // Aplica zoom.
        // ----------------------------------------------------

        applyZoom(
            hotspot
        );


        // ----------------------------------------------------
        // Foco no título.
        // ----------------------------------------------------

        titleEl.focus();

    }


    // ========================================================
    // OBRA COMPLETA
    // PRESERVADO
    // ========================================================

    function showFullArtwork() {

        currentDetailIndex =
            -1;


        zoomContainer.style.transform =
            'translate(0px, 0px) scale(1)';


        zoomContainer.style.setProperty(
            '--current-scale',
            '1'
        );


        hotspots.forEach(
            (h) => {

                h.classList.remove(
                    'active-detail'
                );

            }
        );


        titleEl.textContent =
            initialTitle;


        textEl.textContent =
            initialText;


        counterEl.textContent =
            "Visão Geral";


        btnVoltar.style.display =
            'none';


        btnPrev.disabled =
            true;


        btnNext.disabled =
            false;

    }


    // ========================================================
    // ZOOM
    // PRESERVADO
    // ========================================================

    function applyZoom(hotspot) {

        const cw =
            zoomContainer.offsetWidth;


        const ch =
            zoomContainer.offsetHeight;


        const vw =
            window.innerWidth;


        const vh =
            window.innerHeight;


        const originX =
            parseFloat(
                hotspot.getAttribute(
                    'data-zoom-x'
                )
            );


        const originY =
            parseFloat(
                hotspot.getAttribute(
                    'data-zoom-y'
                )
            );


        const scale =
            parseFloat(
                hotspot.getAttribute(
                    'data-scale'
                )
            ) || 2;


        const unscaledHx =
            (vw - cw) / 2 +
            (cw * originX / 100);


        const unscaledHy =
            (vh - ch) / 2 +
            (ch * originY / 100);


        const panelHeight =
            tourPanel.offsetHeight || 300;


        const targetX =
            vw / 2;


        const targetY =
            (vh - panelHeight) / 2;


        const tx =
            targetX -
            unscaledHx;


        const ty =
            targetY -
            unscaledHy;


        zoomContainer.style.transformOrigin =
            `${originX}% ${originY}%`;


        zoomContainer.style.transform =
            `translate(${tx}px, ${ty}px) scale(${scale})`;


        zoomContainer.style.setProperty(
            '--current-scale',
            scale
        );

    }


    // ========================================================
    // PROGRESSO
    // PRESERVADO
    // ========================================================

    function updateProgress() {

        const percent =
            Math.round(
                (
                    exploredDetails.size /
                    totalDetails
                ) * 100
            );


        progressBar.style.width =
            `${percent}%`;


        progressBar.setAttribute(
            'aria-valuenow',
            percent
        );


        progressText.textContent =
            `${percent}%`;


        if (
            exploredDetails.size ===
            totalDetails
        ) {

            conclusionEl.style.display =
                'block';

        }

    }

});