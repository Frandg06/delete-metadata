// Los DNG son contenedores TIFF: además de los datos RAW del sensor, casi
// siempre incluyen uno o más previews JPEG completos en SubIFDs (tag 0x14A)
// y/o una miniatura chica en la IFD1 (tags 0x201/0x202, estilo EXIF clásico).
// El navegador no puede decodificar RAW, así que en vez de subir el DNG
// entero (pueden pesar 60-70MB) buscamos el preview JPEG embebido más
// grande y subimos solo eso.

const TAG = {
    ImageWidth: 0x0100,
    ImageLength: 0x0101,
    Compression: 0x0103,
    StripOffsets: 0x0111,
    StripByteCounts: 0x0117,
    JpegIFOffset: 0x0201,
    JpegIFByteCount: 0x0202,
    SubIFDs: 0x014a,
};

const TYPE_SIZE = { 3: 2, 4: 4 };

async function readBytes(file, offset, length) {
    const buffer = await file.slice(offset, offset + length).arrayBuffer();
    return new DataView(buffer);
}

function readInlineValue(dv, offset, type, littleEndian) {
    return type === 3 ? dv.getUint16(offset, littleEndian) : dv.getUint32(offset, littleEndian);
}

async function readIfd(file, offset, littleEndian) {
    const countDv = await readBytes(file, offset, 2);
    const count = countDv.getUint16(0, littleEndian);

    const body = await readBytes(file, offset + 2, count * 12 + 4);
    const entries = [];

    for (let i = 0; i < count; i++) {
        const base = i * 12;
        const tag = body.getUint16(base, littleEndian);
        const type = body.getUint16(base + 2, littleEndian);
        const valueCount = body.getUint32(base + 4, littleEndian);
        const size = (TYPE_SIZE[type] || 4) * valueCount;

        const value = size <= 4
            ? readInlineValue(body, base + 8, type, littleEndian)
            : body.getUint32(base + 8, littleEndian);

        entries.push({ tag, type, count: valueCount, value, isOffset: size > 4 });
    }

    const nextIfdOffset = body.getUint32(count * 12, littleEndian);

    return { entries, nextIfdOffset };
}

function tagValue(entries, tag) {
    return entries.find((entry) => entry.tag === tag)?.value;
}

async function readSubIfdOffsets(file, entries, littleEndian) {
    const entry = entries.find((e) => e.tag === TAG.SubIFDs);
    if (!entry) return [];
    if (entry.count === 1) return [entry.value];

    const dv = await readBytes(file, entry.value, entry.count * 4);
    const offsets = [];
    for (let i = 0; i < entry.count; i++) {
        offsets.push(dv.getUint32(i * 4, littleEndian));
    }

    return offsets;
}

function jpegCandidateFromIfd(entries) {
    const width = tagValue(entries, TAG.ImageWidth) || 0;
    const height = tagValue(entries, TAG.ImageLength) || 0;
    const compression = tagValue(entries, TAG.Compression);

    // Miniatura clásica estilo EXIF (IFD1): JPEG completo apuntado directo.
    const jpegOffset = tagValue(entries, TAG.JpegIFOffset);
    const jpegLength = tagValue(entries, TAG.JpegIFByteCount);
    if (jpegOffset && jpegLength) {
        return { offset: jpegOffset, length: jpegLength, pixels: width * height };
    }

    // Preview JPEG "moderno" (SubIFD con Compression=7), un solo strip.
    if (compression === 7) {
        const stripOffsets = entries.find((e) => e.tag === TAG.StripOffsets);
        const stripCounts = entries.find((e) => e.tag === TAG.StripByteCounts);
        if (stripOffsets && stripCounts && stripOffsets.count === 1) {
            return { offset: stripOffsets.value, length: stripCounts.value, pixels: width * height };
        }
    }

    return null;
}

/**
 * Busca el preview JPEG embebido más grande dentro de un DNG.
 * Devuelve un Blob JPEG, o null si no encontró ninguno (DNG sin preview
 * embebido, o con estructura no soportada por este parser reducido).
 */
export async function extractDngPreview(file) {
    const header = await readBytes(file, 0, 8);
    const byteOrderMark = header.getUint16(0, false);

    if (byteOrderMark !== 0x4949 && byteOrderMark !== 0x4d4d) return null;
    const littleEndian = byteOrderMark === 0x4949;

    if (header.getUint16(2, littleEndian) !== 42) return null;

    const candidates = [];
    let ifdOffset = header.getUint32(4, littleEndian);
    let guard = 0;

    while (ifdOffset && guard < 8) {
        guard++;

        const { entries, nextIfdOffset } = await readIfd(file, ifdOffset, littleEndian);

        const direct = jpegCandidateFromIfd(entries);
        if (direct) candidates.push(direct);

        const subIfdOffsets = await readSubIfdOffsets(file, entries, littleEndian);
        for (const subOffset of subIfdOffsets) {
            const { entries: subEntries } = await readIfd(file, subOffset, littleEndian);
            const candidate = jpegCandidateFromIfd(subEntries);
            if (candidate) candidates.push(candidate);
        }

        ifdOffset = nextIfdOffset;
    }

    if (candidates.length === 0) return null;

    const best = candidates.reduce((a, b) => (b.pixels > a.pixels ? b : a));

    return file.slice(best.offset, best.offset + best.length, 'image/jpeg');
}
