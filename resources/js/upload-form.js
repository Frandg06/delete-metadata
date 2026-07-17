import { optimizeImageForUpload } from './image-optimizer';

const form = document.getElementById('upload-form');
if (form) {
    const button = document.getElementById('submit-btn');
    const status = document.getElementById('status');
    const results = document.getElementById('results');
    const actions = document.getElementById('actions');
    const downloadAllBtn = document.getElementById('download-all-btn');
    const zipForm = document.getElementById('zip-form');
    const downloadRoute = form.dataset.downloadRoute;

    let currentImages = [];
    let activeChannel = null;

    function downloadUrl(path) {
        return `${downloadRoute}?path=${encodeURIComponent(path)}`;
    }

    function renderResults(images) {
        results.innerHTML = '';
        for (const image of images) {
            const figure = document.createElement('figure');
            figure.innerHTML = `
                <img src="${image.url}" alt="${image.original_name}">
                <figcaption>${image.original_name}</figcaption>
                <a class="btn" href="${downloadUrl(image.path)}">Descargar</a>
            `;
            results.appendChild(figure);
        }
    }

    async function fetchBatchResult(batchId) {
        const response = await fetch(`/api/images/batches/${batchId}`, {
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const files = Array.from(document.getElementById('images').files);
        if (files.length === 0) return;

        button.disabled = true;
        results.innerHTML = '';
        actions.style.display = 'none';
        currentImages = [];

        if (activeChannel) {
            window.Echo.leave(activeChannel);
            activeChannel = null;
        }

        status.textContent = `Optimizando ${files.length} imagen(es)...`;

        const optimizedFiles = await Promise.all(files.map(optimizeImageForUpload));

        const formData = new FormData();
        for (const file of optimizedFiles) {
            formData.append('images[]', file);
        }

        status.textContent = `Subiendo ${optimizedFiles.length} imagen(es)...`;

        try {
            const response = await fetch('/api/images/strip-metadata', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                status.innerHTML = `<span class="error">Error: ${data.message ?? 'algo falló'}</span>`;
                button.disabled = false;
                return;
            }

            const batchId = data.batch_id;
            activeChannel = `image-batches.${batchId}`;
            status.textContent = 'En cola, procesando en segundo plano...';

            window.Echo.channel(activeChannel).listen('.batch.processed', async () => {
                status.textContent = 'Terminado. Obteniendo resultado...';

                const result = await fetchBatchResult(batchId);
                currentImages = result.images;
                renderResults(result.images);

                const failedSuffix = result.failed > 0 ? ` (${result.failed} fallaron)` : '';
                status.textContent = `Listo. ${result.images.length} imagen(es) procesada(s)${failedSuffix}.`;

                if (result.images.length > 0) {
                    actions.style.display = 'block';
                }

                window.Echo.leave(activeChannel);
                activeChannel = null;
                button.disabled = false;
            });
        } catch (e) {
            status.innerHTML = `<span class="error">Error de red: ${e.message}</span>`;
            button.disabled = false;
        }
    });

    downloadAllBtn.addEventListener('click', () => {
        if (currentImages.length === 0) return;

        zipForm.querySelectorAll('input[name="paths[]"]').forEach((input) => input.remove());

        for (const image of currentImages) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'paths[]';
            input.value = image.path;
            zipForm.appendChild(input);
        }

        zipForm.submit();
    });
}
