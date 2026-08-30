const {
    useEffect,
    useState
} = React;


/* ============================================================
   COMPONENTE PRINCIPAL
   ============================================================ */

function AsyncGalleryStatus() {

    const [
        status,
        setStatus
    ] = useState("idle");


    const [
        message,
        setMessage
    ] = useState("");


    /* =========================================================
       EVENTOS DA GALERIA
       ========================================================= */

    useEffect(() => {

        function handleGalleryReady(event) {

            /*
               Promise simulando uma preparação assíncrona.
            */

            setStatus("loading");


            prepareGalleryAsync(
                event.detail
            )
                .then(
                    (result) => {

                        setMessage(
                            result.message
                        );

                        setStatus(
                            "ready"
                        );

                    }
                );

        }


        function handleDetailSelected(event) {

            /*
               Outra operação assíncrona.
            */

            setStatus("loading");


            prepareDetailAsync(
                event.detail
            )
                .then(
                    (result) => {

                        setMessage(
                            result.message
                        );

                        setStatus(
                            "ready"
                        );

                    }
                );

        }


        window.addEventListener(
            "galleryReady",
            handleGalleryReady
        );


        window.addEventListener(
            "detailSelected",
            handleDetailSelected
        );


        return () => {

            window.removeEventListener(
                "galleryReady",
                handleGalleryReady
            );


            window.removeEventListener(
                "detailSelected",
                handleDetailSelected
            );

        };

    }, []);


    /* =========================================================
       ESTADO INICIAL
       ========================================================= */

    if (
        status === "idle"
    ) {

        return null;

    }


    /* =========================================================
       CARREGAMENTO
       ========================================================= */

    if (
        status === "loading"
    ) {

        return (
            <div
                className="react-tour-container"
                role="status"
                aria-live="polite"
            >

                <div className="react-loading">

                    <span
                        className="react-spinner"
                        aria-hidden="true"
                    ></span>

                    <span>
                        Preparando conteúdo...
                    </span>

                </div>

            </div>
        );

    }


    /* =========================================================
       CONTEÚDO PRONTO
       ========================================================= */

    return (
        <div
            className="react-tour-container"
        >

            <div
                className="react-tour-content"
            >

                <p className="react-ready-message">
                    {message}
                </p>

            </div>

        </div>
    );

}


/* ============================================================
   PROMISE + CLOSURE
   ============================================================ */

/*
   Closure:
   o contador permanece preservado entre chamadas.
*/

function createAsyncEventHandler(context) {

    let eventCount = 0;


    return function dispatchAsyncEvent(
        eventName,
        data
    ) {

        return new Promise(
            (resolve) => {

                setTimeout(
                    () => {

                        eventCount++;


                        resolve({
                            context,
                            eventName,
                            data,
                            eventCount
                        });

                    },
                    300
                );

            }
        );

    };

}


/* ============================================================
   INSTÂNCIA DA CLOSURE
   ============================================================ */

const asyncGalleryEvent =
    createAsyncEventHandler(
        "Arte Guiada"
    );


/* ============================================================
   PREPARAÇÃO DA GALERIA
   ============================================================ */

function prepareGalleryAsync(
    galleryData
) {

    return asyncGalleryEvent(
        "galleryReady",
        galleryData
    )
        .then(
            (result) => {

                return {
                    message:
                        "Conteúdo da obra preparado."
                };

            }
        );

}


/* ============================================================
   PREPARAÇÃO DO DETALHE
   ============================================================ */

function prepareDetailAsync(
    detailData
) {

    return asyncGalleryEvent(
        "detailSelected",
        detailData
    )
        .then(
            (result) => {

                return {
                    message:
                        "Detalhe carregado."
                };

            }
        );

}


/* ============================================================
   MONTAGEM DO REACT
   ============================================================ */

const rootElement =
    document.getElementById(
        "react-async-tour-root"
    );


if (
    rootElement
) {

    const root =
        ReactDOM.createRoot(
            rootElement
        );


    root.render(
        <AsyncGalleryStatus />
    );

}