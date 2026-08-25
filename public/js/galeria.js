document.addEventListener("DOMContentLoaded", () => {
    // ----------------------------------------------------
    // Elementos da Interface
    // ----------------------------------------------------
    const hotspots = Array.from(document.querySelectorAll('.hotspot'));
    const zoomContainer = document.getElementById('zoom-container');
    const tourPanel = document.getElementById('tour-panel');
    const tourIntro = document.getElementById('tour-intro');
    
    const titleEl = document.getElementById('detail-title');
    const textEl = document.getElementById('detail-text');
    const counterEl = document.getElementById('tour-counter');
    const conclusionEl = document.getElementById('tour-conclusion');
    
    const btnIniciar = document.getElementById('btn-iniciar-visita');
    const btnSair = document.getElementById('btn-sair-visita');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const btnVoltar = document.getElementById('btn-voltar');
    
    const progressBar = document.getElementById('tour-progress-bar');
    const progressText = document.getElementById('progress-text');

    // ----------------------------------------------------
    // Estado da Aplicação
    // ----------------------------------------------------
    let isTourMode = false;
    let currentDetailIndex = -1; // -1 significa "Visão Geral / Obra Completa"
    const exploredDetails = new Set();
    const totalDetails = hotspots.length;

    // Textos iniciais do modo visita (Obra completa)
    const initialTitle = "A Boba";
    const initialText = "Selecione os pontos pulsantes ou utilize os controles abaixo para explorar os detalhes.";

    // ----------------------------------------------------
    // Eventos Iniciais
    // ----------------------------------------------------
    btnIniciar.addEventListener('click', startTour);
    btnSair.addEventListener('click', exitTour);
    btnPrev.addEventListener('click', () => goToDetail(currentDetailIndex - 1));
    btnNext.addEventListener('click', () => goToDetail(currentDetailIndex === -1 ? 0 : currentDetailIndex + 1));
    btnVoltar.addEventListener('click', showFullArtwork);

    hotspots.forEach(hotspot => {
        hotspot.addEventListener('click', (e) => {
            const index = parseInt(e.target.getAttribute('data-index'));
            if (!isTourMode) {
                // Se clicar no hotspot fora do modo visita, inicia automaticamente
                startTour();
            }
            goToDetail(index);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isTourMode) {
            exitTour();
        }
    });

    // ----------------------------------------------------
    // Lógica da Visita Guiada
    // ----------------------------------------------------
    function startTour() {
        isTourMode = true;
        document.body.classList.add('modo-visita');
        tourIntro.style.display = 'none';
        tourPanel.style.display = 'flex';
        showFullArtwork();
        
        // Foca no painel logicamente para leitores de tela
        setTimeout(() => counterEl.focus(), 100);
    }

    function exitTour() {
        isTourMode = false;
        document.body.classList.remove('modo-visita');
        tourPanel.style.display = 'none';
        tourIntro.style.display = 'flex';
        
        // Remove zoom e formatações
        zoomContainer.style.transform = 'translate(0px, 0px) scale(1)';
        zoomContainer.style.setProperty('--current-scale', '1');
        
        btnIniciar.focus();
    }

    function goToDetail(index) {
        if (index < 0 || index >= totalDetails) return;
        
        currentDetailIndex = index;
        const hotspot = hotspots[index];
        
        // Atualiza progresso e marca como explorado
        exploredDetails.add(index);
        hotspot.classList.add('explored');
        updateProgress();

        // Estilos visuais dos hotspots (Qual está ativo)
        hotspots.forEach(h => h.classList.remove('active-detail'));
        hotspot.classList.add('active-detail');

        // Atualiza Textos
        titleEl.textContent = hotspot.getAttribute('data-title');
        textEl.textContent = hotspot.getAttribute('data-text');
        counterEl.textContent = `Detalhe ${index + 1} de ${totalDetails}`;

        // Atualiza Botões
        btnPrev.disabled = index === 0;
        btnNext.disabled = index === totalDetails - 1;
        btnVoltar.style.display = 'inline-block';

        // Lógica Matemática do Zoom Correto
        applyZoom(hotspot);

        // Direciona foco pro título para garantir acessibilidade contínua
        titleEl.focus();
    }

    function showFullArtwork() {
        currentDetailIndex = -1;
        
        // Remove Zoom e centraliza nativamente
        zoomContainer.style.transform = 'translate(0px, 0px) scale(1)';
        zoomContainer.style.setProperty('--current-scale', '1');

        hotspots.forEach(h => h.classList.remove('active-detail'));

        // Restaura UI
        titleEl.textContent = initialTitle;
        textEl.textContent = initialText;
        counterEl.textContent = "Visão Geral";
        
        btnVoltar.style.display = 'none';
        btnPrev.disabled = true; // Não há anterior se já está na visão geral
        btnNext.disabled = false; // Próximo leva ao detalhe 0
    }

    // ----------------------------------------------------
    // Matemática do Deslocamento e Zoom
    // ----------------------------------------------------
    function applyZoom(hotspot) {
        // Para calcular sem bugs de tela cheia, utilizamos as proporções relativas:
        const cw = zoomContainer.offsetWidth;
        const ch = zoomContainer.offsetHeight;
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        const originX = parseFloat(hotspot.getAttribute('data-zoom-x'));
        const originY = parseFloat(hotspot.getAttribute('data-zoom-y'));
        const scale = parseFloat(hotspot.getAttribute('data-scale')) || 2;

        // Posição original em pixels na tela (caso a imagem não tivesse nenhum zoom)
        // Considerando que a galeria está perfeitamente alinhada ao centro com flexbox
        const unscaledHx = (vw - cw) / 2 + (cw * originX / 100);
        const unscaledHy = (vh - ch) / 2 + (ch * originY / 100);

        // Onde queremos que o hotspot fique? (Centro da área visualizável)
        const panelHeight = tourPanel.offsetHeight || 300;
        const targetX = vw / 2;
        const targetY = (vh - panelHeight) / 2;

        // O vetor de translação necessário para mover o hotspot até o alvo
        const tx = targetX - unscaledHx;
        const ty = targetY - unscaledHy;

        // Ao setar o transformOrigin para a posição do hotspot, garantir que
        // o `scale` em si não mude o ponto de ancoragem, e sim "inche" a partir dele.
        zoomContainer.style.transformOrigin = `${originX}% ${originY}%`;
        
        // Transladamos o container e em seguida aplicamos a escala!
        zoomContainer.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
        
        // Atualiza a propriedade customizada para os botões ajustarem a própria escala inversa
        zoomContainer.style.setProperty('--current-scale', scale);
    }

    // ----------------------------------------------------
    // Lógica da Barra de Progresso
    // ----------------------------------------------------
    function updateProgress() {
        const percent = Math.round((exploredDetails.size / totalDetails) * 100);
        
        progressBar.style.width = `${percent}%`;
        progressBar.setAttribute('aria-valuenow', percent);
        progressText.textContent = `${percent}%`;
        
        if (exploredDetails.size === totalDetails) {
            conclusionEl.style.display = 'block';
        }
    }
});