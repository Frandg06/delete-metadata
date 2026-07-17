<?php

namespace App\Jobs;

use App\Models\ImageBatchResult;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class StripImageMetadataJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    // El PNG es sin pérdida, no tiene un parámetro de "calidad": para
    // garantizar que nunca se devuelva una imagen de más de 10MB, la única
    // palanca es reducir dimensiones (y, si no alcanza, pasar a paleta indexada).
    private const MAX_OUTPUT_BYTES = 10 * 1024 * 1024;

    private const MIN_DIMENSION = 200;

    private const SCALE_STEP = 0.85;

    public function __construct(
        public readonly string $tempPath,
        public readonly string $originalName,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $manager = ImageManager::imagick();
        $image = $manager->read(Storage::disk('local')->path($this->tempPath));

        $path = 'cleaned/'.Str::uuid().'.png';

        Storage::disk('s3')->put($path, $this->encodeUnderSizeLimit($image), 'public');

        ImageBatchResult::create([
            'batch_id' => $this->batchId,
            'original_name' => $this->originalName,
            'path' => $path,
            'url' => Storage::disk('s3')->url($path),
        ]);

        Storage::disk('local')->delete($this->tempPath);
    }

    public function failed(): void
    {
        Storage::disk('local')->delete($this->tempPath);
    }

    private function encodeUnderSizeLimit(ImageInterface $image): string
    {
        $encoded = (string) $image->encode(new PngEncoder());

        while (
            strlen($encoded) > self::MAX_OUTPUT_BYTES
            && min($image->width(), $image->height()) > self::MIN_DIMENSION
        ) {
            $image->scale(width: (int) round($image->width() * self::SCALE_STEP));
            $encoded = (string) $image->encode(new PngEncoder());
        }

        if (strlen($encoded) > self::MAX_OUTPUT_BYTES) {
            $encoded = (string) $image->encode(new PngEncoder(indexed: true));
        }

        return $encoded;
    }
}
