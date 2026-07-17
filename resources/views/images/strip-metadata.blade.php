<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Eliminar metadatos de imágenes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; color: #1a1a1a; }
        h1 { font-size: 1.4rem; }
        form { border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
        input[type="file"] { display: block; margin-bottom: 12px; }
        button, .btn { background: #1a1a1a; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; text-decoration: none; display: inline-block; }
        button:disabled { opacity: 0.5; cursor: default; }
        .btn-secondary { background: #fff; color: #1a1a1a; border: 1px solid #1a1a1a; }
        #status { margin-top: 16px; font-size: 0.9rem; color: #555; }
        #actions { margin-top: 16px; display: none; }
        #results { margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
        #results figure { margin: 0; font-size: 0.75rem; word-break: break-all; }
        #results img { width: 100%; border-radius: 6px; border: 1px solid #eee; display: block; }
        #results figcaption { margin-top: 6px; }
        #results .btn { margin-top: 6px; padding: 6px 10px; }
        .error { color: #b00020; margin-top: 12px; }
    </style>
</head>
<body>
    <h1>Eliminar metadatos de imágenes</h1>
    <p>Seleccioná una o varias imágenes. Se suben a MinIO ya limpias de metadatos y convertidas a PNG.</p>

    <form id="upload-form">
        <input type="file" name="images" id="images" accept="image/*" multiple required>
        <button type="submit" id="submit-btn">Subir y limpiar</button>
    </form>

    <p id="status"></p>

    <div id="actions">
        <button type="button" id="download-all-btn" class="btn-secondary">Descargar todas (.zip)</button>
    </div>

    <div id="results"></div>

    <form id="zip-form" method="POST" action="{{ route('images.download-zip') }}" style="display:none;">
        @csrf
    </form>

    <script>
        const form = document.getElementById('upload-form');
        const button = document.getElementById('submit-btn');
        const status = document.getElementById('status');
        const results = document.getElementById('results');
        const actions = document.getElementById('actions');
        const downloadAllBtn = document.getElementById('download-all-btn');
        const zipForm = document.getElementById('zip-form');

        let currentImages = [];

        function downloadUrl(path) {
            return `{{ route('images.download') }}?path=${encodeURIComponent(path)}`;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const files = document.getElementById('images').files;
            if (files.length === 0) return;

            const formData = new FormData();
            for (const file of files) {
                formData.append('images[]', file);
            }

            button.disabled = true;
            results.innerHTML = '';
            actions.style.display = 'none';
            currentImages = [];
            status.textContent = `Procesando ${files.length} imagen(es)...`;

            try {
                const response = await fetch('/api/images/strip-metadata', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    status.innerHTML = `<span class="error">Error: ${data.message ?? 'algo falló'}</span>`;
                    return;
                }

                status.textContent = `Listo. ${data.images.length} imagen(es) procesada(s).`;
                currentImages = data.images;

                for (const image of data.images) {
                    const figure = document.createElement('figure');
                    figure.innerHTML = `
                        <img src="${image.url}" alt="${image.original_name}">
                        <figcaption>${image.original_name}</figcaption>
                        <a class="btn" href="${downloadUrl(image.path)}">Descargar</a>
                    `;
                    results.appendChild(figure);
                }

                if (data.images.length > 0) {
                    actions.style.display = 'block';
                }
            } catch (e) {
                status.innerHTML = `<span class="error">Error de red: ${e.message}</span>`;
            } finally {
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
    </script>
</body>
</html>
