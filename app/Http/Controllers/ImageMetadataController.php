<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
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
                    ->max(102400),
            ],
        ]);

        // Imagick (vía libraw) puede decodificar formatos RAW como DNG, además de
        // los formatos estándar; GD no soporta RAW en absoluto.
        $manager = ImageManager::imagick();
        $results = [];

        // Se procesan todas las imágenes de forma síncrona antes de responder,
        // ya que el frontend espera el resultado completo en una única respuesta.
        foreach ($request->file('images') as $file) {
            $image = $manager->read($file->getRealPath());

            $path = 'cleaned/'.Str::uuid().'.png';

            Storage::disk('s3')->put($path, (string) $image->encode(new PngEncoder()), 'public');

            $results[] = [
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk('s3')->url($path),
            ];
        }

        return response()->json(['images' => $results]);
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
