document.addEventListener("DOMContentLoaded", () => {
    const hotspots = document.querySelectorAll('.hotspot');
    const zoomContainer = document.getElementById('zoom-container'); // Pegamos o container com os hotspots e imagem
    const titleEl = document.getElementById('detail-title');
    const textEl = document.getElementById('detail-text');
    const btnVoltar = document.getElementById('btn-voltar');

    // Salva os textos originais
    const originalTitle = titleEl.textContent;
    const originalText = textEl.textContent;

    hotspots.forEach(hotspot => {
        hotspot.addEventListener('click', () => {
            const title = hotspot.getAttribute('data-title');
            const text = hotspot.getAttribute('data-text');
            const x = hotspot.getAttribute('data-zoom-x');
            const y = hotspot.getAttribute('data-zoom-y');
            const scale = hotspot.getAttribute('data-scale');

            // Troca o texto
            titleEl.textContent = title;
            textEl.textContent = text;

            // Faz o zoom acontecer no pacote completo (imagem + hotspots)
            zoomContainer.style.transformOrigin = `${x}% ${y}%`;
            zoomContainer.style.transform = `scale(${scale})`;

            // Mostra o botão "Ver obra completa"
            btnVoltar.style.display = 'block';
        });
    });

    // Lógica para voltar ao estado inicial
    btnVoltar.addEventListener('click', () => {
        // Tira o zoom
        zoomContainer.style.transform = 'scale(1)';

        // Retorna textos originais
        titleEl.textContent = originalTitle;
        textEl.textContent = originalText;

        // Esconde o botão de voltar
        btnVoltar.style.display = 'none';
    });
});