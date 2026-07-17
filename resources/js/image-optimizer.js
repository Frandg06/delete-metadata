import { extractDngPreview } from './dng-preview';

// Formatos que <canvas>/createImageBitmap puede decodificar de forma nativa
// y confiable en Chrome/Firefox/Safari. TIFF y HEIC/HEIF quedan afuera
// (sin soporte consistente) y se suben sin tocar.
const CANVAS_DECODABLE = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp']);

const MAX_DIMENSION = 4000;
const JPEG_QUALITY = 0.85;

// Si ya es liviana no vale la pena perder calidad recomprimiéndola.
const SKIP_BELOW_BYTES = 2 * 1024 * 1024;

async function optimizeRasterImage(file) {
    if (file.size < SKIP_BELOW_BYTES) return file;

    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));

    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    // El PNG se mantiene PNG (puede tener transparencia); el resto pasa a JPEG.
    const outputType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, outputType, JPEG_QUALITY));

    if (!blob || blob.size >= file.size) return file;

    const alreadyJpegName = /\.(jpe?g)$/i.test(file.name);
    const newName = outputType === 'image/jpeg' && !alreadyJpegName
        ? file.name.replace(/\.[^.]+$/, '.jpg')
        : file.name;

    return new File([blob], newName, { type: outputType });
}

async function optimizeDng(file) {
    try {
        const preview = await extractDngPreview(file);
        if (!preview) return file;

        return new File([preview], file.name.replace(/\.dng$/i, '.jpg'), { type: 'image/jpeg' });
    } catch (e) {
        console.warn(`No se pudo extraer el preview de ${file.name}, se sube el DNG original.`, e);
        return file;
    }
}

/**
 * Reduce el peso de una imagen antes de subirla, cuando es posible.
 * - DNG: se reemplaza por su preview JPEG embebido más grande (el navegador
 *   no puede decodificar RAW, así que no hay forma de recomprimirlo directamente).
 * - jpg/png/gif/bmp/webp: se reescala y recomprime con canvas si es pesada.
 * - El resto (tiff, heic/heif) se sube sin modificar.
 * Ante cualquier error, se hace fallback al archivo original.
 */
export async function optimizeImageForUpload(file) {
    const extension = file.name.split('.').pop()?.toLowerCase();

    if (extension === 'dng') {
        return optimizeDng(file);
    }

    if (CANVAS_DECODABLE.has(file.type)) {
        try {
            return await optimizeRasterImage(file);
        } catch (e) {
            console.warn(`No se pudo optimizar ${file.name}, se sube sin cambios.`, e);
            return file;
        }
    }

    return file;
}
