<?php

namespace Need2Talk\Services;

use Need2Talk\Traits\EnterpriseDependencies;

/**
 * FileUploadService - Gestione upload file need2talk
 *
 * Gestisce upload sicuri di:
 * - File audio (MP3, WAV, OGG, WebM)
 * - Avatar utente (JPG, PNG)
 * - Immagini profilo
 *
 * Con validazione, ridimensionamento e archiviazione sicura
 */
class FileUploadService
{
    use EnterpriseDependencies;

    private Logger $logger;

    private ContentValidator $validator;

    // Configurazione upload
    private string $uploadPath;

    private string $tempPath;

    // Limiti file audio
    private int $maxAudioSize = 10 * 1024 * 1024; // 10MB

    private int $maxAudioDuration = 180; // 3 minuti

    private array $allowedAudioTypes = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg',
        'audio/webm', 'audio/m4a', 'audio/aac',
    ];

    // Limiti immagini
    private int $maxImageSize = 5 * 1024 * 1024; // 5MB

    private int $maxImageWidth = 2000;

    private int $maxImageHeight = 2000;

    private array $allowedImageTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
    ];

    // Limiti avatar
    private int $avatarSize = 200; // 200x200px

    private int $maxAvatarFileSize = 2 * 1024 * 1024; // 2MB

    public function __construct()
    {
        $this->logger = new Logger();
        $this->validator = new ContentValidator();

        // Configura percorsi
        $this->uploadPath = dirname(__DIR__, 2) . '/storage/uploads';
        $this->tempPath = dirname(__DIR__, 2) . '/storage/temp';

        // Crea cartelle se non esistono
        $this->createDirectories();
    }

    /**
     * Upload file audio su DigitalOcean CDN con ACL privato
     */
    public function uploadAudio(array $fileData, int $userId): array
    {
        try {
            // Validazione base
            $validation = $this->validateAudioFile($fileData);

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors'],
                ];
            }

            // Upload locale temporaneo per processing
            $tempFileName = 'temp_' . uniqid() . '_' . $fileData['name'];
            $tempPath = $this->tempPath . '/' . $tempFileName;

            if (!move_uploaded_file($fileData['tmp_name'], $tempPath)) {
                throw new \Exception('Errore durante il caricamento temporaneo');
            }

            // Estrai metadata prima dell'upload
            $metadata = $this->extractAudioMetadata($tempPath);

            // Upload su DigitalOcean CDN con ACL privato
            $cdnUpload = $this->uploadToDigitalOceanPrivate($tempPath, $userId);

            // Cleanup file temporaneo
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            if (!$cdnUpload['success']) {
                throw new \Exception('Errore upload CDN: ' . implode(', ', $cdnUpload['errors'] ?? []));
            }

            $this->logger->info('Audio uploaded to CDN successfully', [
                'user_id' => $userId,
                'file_name' => $cdnUpload['file_name'],
                'private_url' => $cdnUpload['private_url'],
                'file_size' => $fileData['size'],
                'duration' => $metadata['duration'] ?? null,
            ]);

            return [
                'success' => true,
                'file_path' => null, // Non più usato - tutto su CDN
                'private_url' => $cdnUpload['private_url'], // URL privato CDN
                'public_stream_url' => url("api/audio/{$userId}/stream"), // Endpoint streaming PHP
                'file_name' => $cdnUpload['file_name'],
                'metadata' => $metadata,
                'cdn_storage' => true,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Audio upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file_name' => $fileData['name'] ?? 'unknown',
            ]);

            // Cleanup se necessario
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            return [
                'success' => false,
                'errors' => ['Errore durante il caricamento. Riprova più tardi.'],
            ];
        }
    }

    /**
     * Upload avatar utente
     */
    public function uploadAvatar(array $fileData, int $userId): array
    {
        try {
            // Validazione immagine
            $validation = $this->validateImageFile($fileData, 'avatar');

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors'],
                ];
            }

            // Rimuovi avatar precedente
            $this->removeOldAvatar($userId);

            // Genera nome file
            $fileName = "avatar_{$userId}_" . time() . '.jpg';
            $avatarPath = "avatars/{$fileName}";
            $fullPath = $this->uploadPath . '/' . $avatarPath;

            // Crea directory avatar se non esiste
            $avatarDir = dirname($fullPath);

            if (!is_dir($avatarDir)) {
                mkdir($avatarDir, 0755, true);
            }

            // Ridimensiona e ottimizza
            $processed = $this->processAvatar($fileData['tmp_name'], $fullPath);

            if (!$processed) {
                throw new \Exception('Errore nel processamento dell\'immagine');
            }

            $this->logger->info('Avatar uploaded successfully', [
                'user_id' => $userId,
                'file_name' => $fileName,
            ]);

            return [
                'success' => true,
                'file_path' => $avatarPath,
                'full_path' => $fullPath,
                'file_name' => $fileName,
                'url' => url("uploads/{$avatarPath}"),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Avatar upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors' => ['Errore durante il caricamento dell\'avatar.'],
            ];
        }
    }

    /**
     * Upload immagine generica
     */
    public function uploadImage(array $fileData, int $userId, string $type = 'general'): array
    {
        try {
            $validation = $this->validateImageFile($fileData, $type);

            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors'],
                ];
            }

            $fileName = $this->generateUniqueFileName('images', $fileData['name']);
            $imagePath = "images/{$fileName}";
            $fullPath = $this->uploadPath . '/' . $imagePath;

            // Crea directory immagini
            $imageDir = dirname($fullPath);

            if (!is_dir($imageDir)) {
                mkdir($imageDir, 0755, true);
            }

            // Processa immagine (ridimensiona se troppo grande)
            $processed = $this->processImage($fileData['tmp_name'], $fullPath, $type);

            if (!$processed) {
                throw new \Exception('Errore nel processamento dell\'immagine');
            }

            return [
                'success' => true,
                'file_path' => $imagePath,
                'full_path' => $fullPath,
                'file_name' => $fileName,
                'url' => url("uploads/{$imagePath}"),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Image upload failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors' => ['Errore durante il caricamento dell\'immagine.'],
            ];
        }
    }

    /**
     * Elimina file upload
     */
    public function deleteFile(string $filePath): bool
    {
        $fullPath = $this->uploadPath . '/' . $filePath;

        if (file_exists($fullPath)) {
            $success = unlink($fullPath);

            $this->logger->info('File deleted', [
                'file_path' => $filePath,
                'success' => $success,
            ]);

            return $success;
        }

        return true; // File già inesistente
    }

    /**
     * Ottieni informazioni file
     */
    public function getFileInfo(string $filePath): ?array
    {
        $fullPath = $this->uploadPath . '/' . $filePath;

        if (!file_exists($fullPath)) {
            return null;
        }

        $info = [
            'exists' => true,
            'size' => filesize($fullPath),
            'mime_type' => mime_content_type($fullPath),
            'modified_at' => filemtime($fullPath),
            'path' => $filePath,
            'url' => url("uploads/{$filePath}"),
        ];

        // Aggiungi metadata specifici per tipo
        if (strpos($info['mime_type'], 'audio/') === 0) {
            $info['metadata'] = $this->extractAudioMetadata($fullPath);
        } elseif (strpos($info['mime_type'], 'image/') === 0) {
            $imageInfo = getimagesize($fullPath);

            if ($imageInfo) {
                $info['width'] = $imageInfo[0];
                $info['height'] = $imageInfo[1];
            }
        }

        return $info;
    }

    /**
     * Pulisci file temporanei vecchi
     */
    public function cleanupTempFiles(): int
    {
        $cleaned = 0;
        $tempDir = $this->tempPath;

        if (!is_dir($tempDir)) {
            return 0;
        }

        $files = glob($tempDir . '/*');
        $cutoffTime = time() - (24 * 60 * 60); // 24 ore

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }

        $this->logger->info('Temp files cleaned', ['count' => $cleaned]);

        return $cleaned;
    }

    /**
     * Upload ACL privato su DigitalOcean CDN
     */
    private function uploadToDigitalOceanPrivate(string $localFilePath, int $userId): array
    {
        try {
            // Configura AWS S3 client per DigitalOcean Spaces usando Enterprise Dependencies
            $client = $this->createS3ClientSafely([
                'version' => 'latest',
                'region' => env('DO_SPACES_REGION', 'fra1'),
                'endpoint' => 'https://' . env('DO_SPACES_REGION', 'fra1') . '.digitaloceanspaces.com',
                'credentials' => [
                    'key' => env('DO_SPACES_KEY'),
                    'secret' => env('DO_SPACES_SECRET'),
                ],
                'use_path_style_endpoint' => false,
            ]);

            // Fallback se S3 non disponibile
            if (!$client) {
                return [
                    'success' => false,
                    'errors' => ['CDN upload not available - AWS SDK not installed'],
                ];
            }

            // Genera nome file univoco per CDN
            $extension = pathinfo($localFilePath, PATHINFO_EXTENSION);
            $timestamp = time();
            $random = bin2hex(random_bytes(8));
            $fileName = "audio/{$userId}/{$timestamp}_{$random}.{$extension}";

            // Upload con ACL privato usando safe operation
            $result = $this->safeS3Operation($client, 'putObject', [
                'Bucket' => env('DO_SPACES_BUCKET'),
                'Key' => $fileName,
                'Body' => fopen($localFilePath, 'rb'),
                'ACL' => 'private', // CRITICO: ACL privato
                'ContentType' => mime_content_type($localFilePath),
                'Metadata' => [
                    'user_id' => (string) $userId,
                    'uploaded_at' => date('c'),
                    'original_name' => basename($localFilePath),
                ],
            ]);

            // URL privato per accesso interno
            $privateUrl = $result['ObjectURL'];

            return [
                'success' => true,
                'private_url' => $privateUrl,
                'file_name' => $fileName,
                'bucket' => env('DO_SPACES_BUCKET'),
                'region' => env('DO_SPACES_REGION'),
            ];

        } catch (\Exception $e) {
            $this->logger->error('DigitalOcean upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'local_file' => basename($localFilePath),
            ]);

            return [
                'success' => false,
                'errors' => ['CDN upload failed: ' . $e->getMessage()],
            ];
        }
    }

    // === METODI PRIVATI === //

    /**
     * Valida file audio
     */
    private function validateAudioFile(array $fileData): array
    {
        $errors = [];

        // Controlli base
        if (!isset($fileData['tmp_name']) || !file_exists($fileData['tmp_name'])) {
            $errors[] = 'File non ricevuto correttamente';

            return ['valid' => false, 'errors' => $errors];
        }

        // Dimensione
        if ($fileData['size'] > $this->maxAudioSize) {
            $errors[] = 'File troppo grande (massimo 10MB)';
        }

        // Tipo MIME
        $mimeType = mime_content_type($fileData['tmp_name']);

        if (!in_array($mimeType, $this->allowedAudioTypes, true)) {
            $errors[] = 'Formato audio non supportato';
        }

        // Durata (se possibile)
        $duration = $this->getAudioDuration($fileData['tmp_name']);

        if ($duration !== null && $duration > $this->maxAudioDuration) {
            $errors[] = 'Audio troppo lungo (massimo 3 minuti)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mime_type' => $mimeType,
            'duration' => $duration,
        ];
    }

    /**
     * Valida file immagine
     */
    private function validateImageFile(array $fileData, string $type): array
    {
        $errors = [];

        if (!isset($fileData['tmp_name']) || !file_exists($fileData['tmp_name'])) {
            $errors[] = 'Immagine non ricevuta correttamente';

            return ['valid' => false, 'errors' => $errors];
        }

        // Dimensione file
        $maxSize = $type === 'avatar' ? $this->maxAvatarFileSize : $this->maxImageSize;

        if ($fileData['size'] > $maxSize) {
            $sizeLimit = $type === 'avatar' ? '2MB' : '5MB';
            $errors[] = "Immagine troppo grande (massimo {$sizeLimit})";
        }

        // Tipo MIME
        $mimeType = mime_content_type($fileData['tmp_name']);

        if (!in_array($mimeType, $this->allowedImageTypes, true)) {
            $errors[] = 'Formato immagine non supportato (usa JPG, PNG, GIF o WebP)';
        }

        // Dimensioni immagine
        $imageInfo = getimagesize($fileData['tmp_name']);

        if ($imageInfo === false) {
            $errors[] = 'File immagine corrotto';
        } else {
            [$width, $height] = $imageInfo;

            if ($width > $this->maxImageWidth || $height > $this->maxImageHeight) {
                $errors[] = "Dimensioni troppo grandi (massimo {$this->maxImageWidth}x{$this->maxImageHeight}px)";
            }

            if ($width < 50 || $height < 50) {
                $errors[] = 'Immagine troppo piccola (minimo 50x50px)';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mime_type' => $mimeType,
            'dimensions' => $imageInfo,
        ];
    }

    /**
     * Processa avatar (ridimensiona a 200x200)
     */
    private function processAvatar(string $sourcePath, string $destPath): bool
    {
        $imageInfo = getimagesize($sourcePath);

        if (!$imageInfo) {
            return false;
        }

        // Crea immagine sorgente
        $source = $this->createImageResource($sourcePath, $imageInfo['mime']);

        if (!$source) {
            return false;
        }

        // Crea canvas quadrato
        $canvas = imagecreatetruecolor($this->avatarSize, $this->avatarSize);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        // Calcola ritaglio centrato
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = ($sourceWidth - $cropSize) / 2;
        $cropY = ($sourceHeight - $cropSize) / 2;

        // Ridimensiona e copia
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            $this->avatarSize,
            $this->avatarSize,
            $cropSize,
            $cropSize
        );

        // Salva come JPEG
        $success = imagejpeg($canvas, $destPath, 85);

        // Cleanup
        imagedestroy($source);
        imagedestroy($canvas);

        return $success;
    }

    /**
     * Processa immagine generica
     */
    private function processImage(string $sourcePath, string $destPath, string $type): bool
    {
        $imageInfo = getimagesize($sourcePath);

        if (!$imageInfo) {
            return false;
        }

        [$width, $height] = $imageInfo;

        // Se l'immagine è già nelle dimensioni giuste, copia semplicemente
        if ($width <= $this->maxImageWidth && $height <= $this->maxImageHeight) {
            return copy($sourcePath, $destPath);
        }

        // Calcola nuove dimensioni mantenendo proporzioni
        $ratio = min($this->maxImageWidth / $width, $this->maxImageHeight / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        // Crea immagini
        $source = $this->createImageResource($sourcePath, $imageInfo['mime']);

        if (!$source) {
            return false;
        }

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        // Mantieni trasparenza per PNG/GIF
        if ($imageInfo['mime'] === 'image/png' || $imageInfo['mime'] === 'image/gif') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            imagefill($canvas, 0, 0, $transparent);
        }

        // Ridimensiona
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Salva
        $success = $this->saveImage($canvas, $destPath, $imageInfo['mime']);

        // Cleanup
        imagedestroy($source);
        imagedestroy($canvas);

        return $success;
    }

    /**
     * Crea risorsa immagine dal file
     */
    private function createImageResource(string $path, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                return imagecreatefrompng($path);
            case 'image/gif':
                return imagecreatefromgif($path);
            case 'image/webp':
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }

    /**
     * Salva immagine processata
     */
    private function saveImage($image, string $path, string $mimeType): bool
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagejpeg($image, $path, 85);
            case 'image/png':
                return imagepng($image, $path, 6);
            case 'image/gif':
                return imagegif($image, $path);
            case 'image/webp':
                return imagewebp($image, $path, 80);
            default:
                return false;
        }
    }

    /**
     * Estrai metadata audio
     */
    private function extractAudioMetadata(string $filePath): array
    {
        $metadata = [];

        // Durata
        $duration = $this->getAudioDuration($filePath);

        if ($duration !== null) {
            $metadata['duration'] = $duration;
        }

        // Altri metadata usando solo ffmpeg (come richiesto dall'utente)
        try {
            // Bitrate con ffprobe
            $bitrateOutput = shell_exec('ffprobe -v quiet -select_streams a:0 -show_entries stream=bit_rate -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null');

            if ($bitrateOutput && is_numeric(trim($bitrateOutput))) {
                $metadata['bitrate'] = (int) trim($bitrateOutput);
            }

            // Sample rate con ffprobe
            $sampleRateOutput = shell_exec('ffprobe -v quiet -select_streams a:0 -show_entries stream=sample_rate -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null');

            if ($sampleRateOutput && is_numeric(trim($sampleRateOutput))) {
                $metadata['sample_rate'] = (int) trim($sampleRateOutput);
            }

            // Codec information
            $codecOutput = shell_exec('ffprobe -v quiet -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null');

            if ($codecOutput) {
                $metadata['codec'] = trim($codecOutput);
            }
        } catch (\Exception $e) {
            // Fail silently for metadata extraction
            $this->logger->debug('FFmpeg metadata extraction failed', [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);
        }

        return $metadata;
    }

    /**
     * Ottieni durata audio usando solo ffmpeg (come richiesto dall'utente)
     */
    private function getAudioDuration(string $filePath): ?float
    {
        try {
            // Usa FFprobe per la durata - metodo enterprise-grade
            $output = shell_exec('ffprobe -v quiet -show_entries format=duration -of csv=p=0 ' . escapeshellarg($filePath) . ' 2>/dev/null');

            if ($output !== null && is_numeric(trim($output))) {
                $duration = (float) trim($output);

                return $duration > 0 ? $duration : null;
            }

            // Fallback con ffmpeg diretto se ffprobe non funziona
            $fallbackOutput = shell_exec('ffmpeg -i ' . escapeshellarg($filePath) . " -f null - 2>&1 | grep 'Duration' | head -1 | sed 's/.*Duration: \\([^,]*\\).*/\\1/' 2>/dev/null");

            if ($fallbackOutput) {
                $time = trim($fallbackOutput);

                if (preg_match('/^(\\d{2}):(\\d{2}):(\\d{2}(?:\\.\\d+)?)$/', $time, $matches)) {
                    $hours = (int) $matches[1];
                    $minutes = (int) $matches[2];
                    $seconds = (float) $matches[3];

                    return $hours * 3600 + $minutes * 60 + $seconds;
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->warning('Audio duration extraction failed', [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Genera nome file univoco
     */
    private function generateUniqueFileName(string $type, string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $timestamp = time();
        $random = bin2hex(random_bytes(8));

        return "{$type}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Rimuovi avatar precedente utente
     */
    private function removeOldAvatar(int $userId): void
    {
        $avatarDir = $this->uploadPath . '/avatars';
        $pattern = "avatar_{$userId}_*";

        $files = glob($avatarDir . '/' . $pattern);

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Crea directory necessarie
     */
    private function createDirectories(): void
    {
        $dirs = [
            $this->uploadPath,
            $this->uploadPath . '/audio',
            $this->uploadPath . '/images',
            $this->uploadPath . '/avatars',
            $this->tempPath,
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}
