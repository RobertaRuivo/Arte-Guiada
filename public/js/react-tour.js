/* ============================================================
   ARTE GUIADA
   EVENTOS ASSÍNCRONOS COM REACT

   Este arquivo adiciona somente as funcionalidades assíncronas
   exigidas pela atividade.

   O JavaScript original continua responsável por:

   - hotspots;
   - zoom;
   - navegação;
   - progresso;
   - texto principal;
   - entrada e saída da visita.

   ============================================================ */


/* ============================================================
   FUNÇÃO AUXILIAR DE DELAY

   Utiliza Promise + setTimeout.

   Também permite cancelar o timer usando AbortController.
   ============================================================ */

const delay = (
    milliseconds,
    value,
    signal
) => {

    return new Promise(
        (resolve, reject) => {

            if (signal.aborted) {

                reject(
                    new DOMException(
                        'Operação cancelada.',
                        'AbortError'
                    )
                );

                return;
            }


            const timer =
                setTimeout(
                    () => {

                        signal.removeEventListener(
                            'abort',
                            handleAbort
                        );

                        resolve(value);

                    },
                    milliseconds
                );


            const handleAbort = () => {

                clearTimeout(
                    timer
                );


                reject(
                    new DOMException(
                        'Operação cancelada.',
                        'AbortError'
                    )
                );

            };


            signal.addEventListener(
                'abort',
                handleAbort,
                {
                    once: true
                }
            );

        }
    );

};


/* ============================================================
   EVENTO 5 — TAREFA 1

   Preparação das informações da obra.
   ============================================================ */

const prepareArtworkInformation = (
    signal
) => {

    return delay(
        500,
        {
            title: 'A Boba',
            ready: true
        },
        signal
    );

};


/* ============================================================
   EVENTO 5 — TAREFA 2

   Preparação dos detalhes.

   Os detalhes são lidos dos HOTSPOTS EXISTENTES.

   Não existe uma segunda fonte de dados.
   ============================================================ */

const prepareArtworkDetails = async (
    signal
) => {

    await delay(
        700,
        null,
        signal
    );


    const hotspots =
        Array.from(
            document.querySelectorAll(
                '.hotspot'
            )
        );


    return hotspots.map(
        (hotspot) => {

            return {

                index:
                    Number(
                        hotspot.getAttribute(
                            'data-index'
                        )
                    ),

                title:
                    hotspot.getAttribute(
                        'data-title'
                    ) || '',

                text:
                    hotspot.getAttribute(
                        'data-text'
                    ) || ''

            };

        }
    );

};


/* ============================================================
   EVENTO 5 — TAREFA 3

   Preparação da interface complementar.
   ============================================================ */

const prepareTourInterface = (
    signal
) => {

    return delay(
        400,
        {
            ready: true
        },
        signal
    );

};


/* ============================================================
   COMPONENTE REACT
   ============================================================ */

const AsyncTourComponent = () => {


    /* ========================================================
       ESTADO — EVENTO 1

       Controla o spinner.
       ======================================================== */

    const [
        isLoading,
        setIsLoading
    ] = React.useState(false);


    /* ========================================================
       ESTADO — EVENTO 4

       Controla os pontos do texto:

       ""
       "."
       ".."
       "..."
       ======================================================== */

    const [
        loadingDots,
        setLoadingDots
    ] = React.useState('');


    /* ========================================================
       ESTADO — EVENTO 3

       Guarda os detalhes encontrados nos hotspots.
       ======================================================== */

    const [
        details,
        setDetails
    ] = React.useState([]);


    /* ========================================================
       ESTADO — EVENTO 5

       Indica se as tarefas paralelas terminaram.
       ======================================================== */

    const [
        tasksCompleted,
        setTasksCompleted
    ] = React.useState(false);


    /* ========================================================
       ESTADO — EVENTO 2

       Controla a entrada do conteúdo por fade-in.
       ======================================================== */

    const [
        showContent,
        setShowContent
    ] = React.useState(false);


    /* ========================================================
       EVENTO 4 — LOADING COM TEXTO DINÂMICO

       Usa:

       useState()
       useEffect()
       setInterval()
       clearInterval()
       ======================================================== */

    React.useEffect(
        () => {

            if (!isLoading) {

                setLoadingDots('');

                return;

            }


            let dots = 0;


            const interval =
                setInterval(
                    () => {

                        dots =
                            (dots + 1) % 4;


                        setLoadingDots(
                            '.'.repeat(dots)
                        );

                    },
                    400
                );


            /* =================================================
               CLEANUP DO INTERVAL
               ================================================= */

            return () => {

                clearInterval(
                    interval
                );

            };

        },
        [isLoading]
    );


    /* ========================================================
       EVENTOS 1, 2 E 5

       Preparação assíncrona da visita.
       ======================================================== */

    React.useEffect(
        () => {

            let cancelled = false;


            let preparationController =
                null;


            /* =================================================
               INICIA A PREPARAÇÃO
               ================================================= */

            const startPreparation =
                async () => {


                    /* =========================================
                       EVENTO 1 — MOSTRA SPINNER
                       ========================================= */

                    setIsLoading(
                        true
                    );


                    /* =========================================
                       RESET DOS ESTADOS
                       ========================================= */

                    setTasksCompleted(
                        false
                    );


                    setShowContent(
                        false
                    );


                    setDetails(
                        []
                    );


                    /* =========================================
                       CONTROLLER DOS TIMERS
                       ========================================= */

                    preparationController =
                        new AbortController();


                    const signal =
                        preparationController.signal;


                    try {


                        /* =====================================
                           EVENTO 5 — Promise.all()

                           As três tarefas começam em paralelo.
                           ===================================== */

                        const [
                            artworkInformation,
                            loadedDetails,
                            interfaceInformation
                        ] = await Promise.all(

                            [

                                prepareArtworkInformation(
                                    signal
                                ),

                                prepareArtworkDetails(
                                    signal
                                ),

                                prepareTourInterface(
                                    signal
                                )

                            ]

                        );


                        /* =====================================
                           Verifica se a operação foi cancelada.
                           ===================================== */

                        if (cancelled) {

                            return;

                        }


                        /* =====================================
                           Guarda os detalhes obtidos.
                           ===================================== */

                        setDetails(
                            loadedDetails
                        );


                        /* =====================================
                           Confirma conclusão das tarefas.
                           ===================================== */

                        if (
                            artworkInformation.ready &&
                            interfaceInformation.ready
                        ) {

                            setTasksCompleted(
                                true
                            );

                        }


                        /* =====================================
                           EVENTO 1 — ESCONDE SPINNER
                           ===================================== */

                        setIsLoading(
                            false
                        );


                        /* =====================================
                           EVENTO 2 — ATIVA FADE-IN
                           ===================================== */

                        setShowContent(
                            true
                        );


                    } catch (error) {


                        /* =====================================
                           AbortError significa cancelamento,
                           não uma falha da aplicação.
                           ===================================== */

                        if (
                            error.name ===
                            'AbortError'
                        ) {

                            return;

                        }


                        console.error(
                            'Erro durante a preparação da visita:',
                            error
                        );


                        if (!cancelled) {

                            setIsLoading(
                                false
                            );

                        }

                    }

                };


            /* =================================================
               EVENTO RECEBIDO QUANDO A VISITA COMEÇA
               ================================================= */

            const handleTourStarted =
                () => {

                    startPreparation();

                };


            /* =================================================
               EVENTO RECEBIDO QUANDO A VISITA TERMINA
               ================================================= */

            const handleTourExited =
                () => {

                    cancelled =
                        true;


                    if (
                        preparationController
                    ) {

                        preparationController.abort();

                    }


                    setIsLoading(
                        false
                    );


                    setLoadingDots(
                        ''
                    );


                    setTasksCompleted(
                        false
                    );


                    setShowContent(
                        false
                    );


                    setDetails(
                        []
                    );

                };


            /* =================================================
               LISTENERS
               ================================================= */

            window.addEventListener(
                'tourStarted',
                handleTourStarted
            );


            window.addEventListener(
                'tourExited',
                handleTourExited
            );


            /* =================================================
               CLEANUP DOS LISTENERS

               Remove os eventos quando o componente React
               deixar de existir.
               ================================================= */

            return () => {

                cancelled =
                    true;


                if (
                    preparationController
                ) {

                    preparationController.abort();

                }


                window.removeEventListener(
                    'tourStarted',
                    handleTourStarted
                );


                window.removeEventListener(
                    'tourExited',
                    handleTourExited
                );

            };

        },
        []
    );


    /* ========================================================
       EVENTO 3 — SELEÇÃO DO DETALHE

       O React não implementa:

       - zoom;
       - progresso;
       - navegação;
       - atualização dos textos principais.

       Ele somente aciona o hotspot original.

       Assim o JavaScript existente continua fazendo o trabalho.
       ======================================================== */

    const handleDetailClick = (
        index
    ) => {

        const hotspots =
            document.querySelectorAll(
                '.hotspot'
            );


        const hotspot =
            hotspots[index];


        if (hotspot) {

            hotspot.click();

        }

    };


    /* ========================================================
       RENDERIZAÇÃO
       ======================================================== */

    return (

        <div
            className="react-tour-container"
        >


            {/* ==================================================
                EVENTO 1 — SPINNER
                ================================================== */}

            {isLoading && (

                <div
                    className="react-loading"
                    role="status"
                    aria-live="polite"
                >

                    <div
                        className="react-spinner"
                        aria-hidden="true"
                    ></div>


                    <p>
                        Preparando sua visita
                        {loadingDots}
                    </p>

                </div>

            )}


            {/* ==================================================
                EVENTO 2 — FADE-IN

                O conteúdo aparece somente após o carregamento.
                ================================================== */}

            {!isLoading &&
                showContent && (

                    <section
                        className="react-tour-content fade-in"
                        aria-label="Detalhes para explorar"
                    >


                        <h3>
                            Detalhes para explorar
                        </h3>


                        {/* ======================================
                            EVENTO 3 — LISTA DOS DETALHES
                            ====================================== */}

                        <div
                            className="react-detail-list"
                        >

                            {details.map(
                                (detail) => (

                                    <button
                                        key={
                                            detail.index
                                        }
                                        type="button"
                                        className="react-detail-item slide-in"
                                        style={{
                                            '--item-delay':
                                                `${detail.index * 120}ms`
                                        }}
                                        onClick={() =>
                                            handleDetailClick(
                                                detail.index
                                            )
                                        }
                                        aria-label={
                                            `Explorar ${detail.title}`
                                        }
                                    >

                                        <span
                                            className="react-detail-title"
                                        >

                                            {
                                                detail.title
                                            }

                                        </span>

                                    </button>

                                )
                            )}

                        </div>


                        {/* ======================================
                            MENSAGEM DE PREPARAÇÃO CONCLUÍDA
                            ====================================== */}

                        {tasksCompleted && (

                            <p
                                className="react-ready-message"
                                aria-live="polite"
                            >
                                A visita está pronta para ser
                                explorada.
                            </p>

                        )}

                    </section>

                )}

        </div>

    );

};


/* ============================================================
   MONTAGEM DO REACT

   O React será montado exclusivamente no elemento:

   #react-async-tour-root
   ============================================================ */

const reactAsyncTourRoot =
    ReactDOM.createRoot(
        document.getElementById(
            'react-async-tour-root'
        )
    );


reactAsyncTourRoot.render(
    <AsyncTourComponent />
);