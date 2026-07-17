<?php

namespace App\Http\Controllers;

use App\Events\ImageBatchProcessed;
use App\Jobs\StripImageMetadataJob;
use App\Models\ImageBatchResult;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ImageMetadataController extends Controller
{
    public function stripMetadata(Request $request): JsonResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                'file',
                File::default()
                    ->extensions(['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tif', 'tiff', 'heic', 'heif', 'dng'])
                    ->max(524288),
            ],
        ]);

        // Carpeta puramente interna para los temporales; no tiene relación con
        // el ID de batch real, que Laravel recién asigna al hacer dispatch().
        $uploadId = (string) Str::uuid();

        // Los archivos subidos viven en un tmp del request; se guardan en el
        // disco local para que los jobs en cola puedan leerlos después,
        // incluso una vez terminada esta request HTTP.
        //
        // Ojo: se guarda con extensión .tmp a propósito (no la original). Con
        // .dng/.DNG, Imagick fuerza su decoder RAW (libraw), que falla en
        // algunos DNG de iPhone (ProRAW); sin esa pista de extensión, Imagick
        // detecta el formato por los magic bytes y cae en un decoder más
        // robusto que además puede aprovechar el preview JPEG embebido.
        $jobs = collect($request->file('images'))->map(function ($file) use ($uploadId) {
            $tempPath = $file->storeAs(
                "uploads/{$uploadId}",
                Str::uuid().'.tmp',
                'local'
            );

            return new StripImageMetadataJob($tempPath, $file->getClientOriginalName());
        });

        $batch = Bus::batch($jobs)
            ->name("strip-metadata-{$uploadId}")
            ->allowFailures()
            ->finally(function (Batch $batch) {
                ImageBatchProcessed::dispatch($batch->id, $batch->hasFailures());
            })
            ->dispatch();

        return response()->json(['batch_id' => $batch->id], Response::HTTP_ACCEPTED);
    }

    public function batchStatus(string $batchId): JsonResponse
    {
        $batch = Bus::findBatch($batchId);

        abort_unless($batch, Response::HTTP_NOT_FOUND);

        $images = ImageBatchResult::query()
            ->where('batch_id', $batchId)
            ->get(['original_name', 'path', 'url']);

        return response()->json([
            'status' => match (true) {
                $batch->cancelled() => 'cancelled',
                $batch->finished() => 'finished',
                default => 'processing',
            },
            'total' => $batch->totalJobs,
            'processed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'images' => $images,
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = $this->validCleanedPath($request->string('path'));

        return Storage::disk('s3')->download($path);
    }

    public function downloadZip(Request $request): BinaryFileResponse
    {
        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
        ]);

        $paths = collect($request->input('paths'))
            ->map(fn (string $path) => $this->validCleanedPath($path));

        $zipPath = tempnam(sys_get_temp_dir(), 'images-').'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($paths as $path) {
            $zip->addFromString(basename($path), Storage::disk('s3')->get($path));
        }

        $zip->close();

        return response()
            ->download($zipPath, 'imagenes-sin-metadatos.zip')
            ->deleteFileAfterSend(true);
    }

    private function validCleanedPath(string $path): string
    {
        $isValidKey = (bool) preg_match('/^cleaned\/[0-9a-f-]{36}\.png$/', $path);

        abort_unless(
            $isValidKey && Storage::disk('s3')->exists($path),
            Response::HTTP_NOT_FOUND
        );

        return $path;
    }
}
