<?php
/**
 * MIGRATION SCRIPT: Move audio files from user_id to user_uuid paths
 *
 * OLD: audio/{user_id}/{year}/{month}/{audio_uuid}.webm
 * NEW: audio/{user_uuid}/{year}/{month}/{audio_uuid}.webm
 *
 * Run: docker exec need2talk_php php /var/www/html/scripts/migrate-audio-to-uuid.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/bootstrap.php';

use Aws\S3\S3Client;

echo "=== AUDIO PATH MIGRATION: user_id -> user_uuid ===\n\n";

// S3 Client
$s3 = new S3Client([
    'version' => 'latest',
    'region' => env('DO_SPACES_REGION', 'fra1'),
    'endpoint' => env('DO_SPACES_ENDPOINT', 'https://fra1.digitaloceanspaces.com'),
    'credentials' => [
        'key' => env('DO_SPACES_KEY'),
        'secret' => env('DO_SPACES_SECRET'),
    ],
    'use_path_style_endpoint' => false,
]);

$bucket = env('DO_SPACES_BUCKET', 'need2talk');
$cdnBase = env('DO_SPACES_CDN_ENDPOINT', 'https://need2talk.fra1.cdn.digitaloceanspaces.com');

// Get audio file to migrate
$db = db_pdo();
$stmt = $db->query("
    SELECT
        af.id,
        af.uuid as audio_uuid,
        af.user_id,
        af.cdn_url,
        u.uuid as user_uuid
    FROM audio_files af
    JOIN users u ON u.id = af.user_id
    WHERE af.deleted_at IS NULL
");

$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($files) . " audio file(s) to migrate\n\n";

foreach ($files as $file) {
    echo "Processing audio ID: {$file['id']}\n";
    echo "  Audio UUID: {$file['audio_uuid']}\n";
    echo "  User ID: {$file['user_id']}\n";
    echo "  User UUID: {$file['user_uuid']}\n";
    echo "  Current CDN URL: {$file['cdn_url']}\n";

    // Parse current path
    $currentUrl = $file['cdn_url'];
    $pathPart = str_replace($cdnBase . '/', '', $currentUrl);

    // Check if already using UUID (skip if so)
    if (strpos($pathPart, $file['user_uuid']) !== false) {
        echo "  ✓ Already using user_uuid, skipping\n\n";
        continue;
    }

    // Extract year/month from current path
    // Format: audio/{user_id}/{year}/{month}/{audio_uuid}.webm
    preg_match('/audio\/\d+\/(\d{4})\/(\d{2})\//', $pathPart, $matches);
    $year = $matches[1] ?? date('Y');
    $month = $matches[2] ?? date('m');

    $oldKey = $pathPart;
    $newKey = "audio/{$file['user_uuid']}/{$year}/{$month}/{$file['audio_uuid']}.webm";
    $newCdnUrl = "{$cdnBase}/{$newKey}";

    echo "  Old S3 Key: {$oldKey}\n";
    echo "  New S3 Key: {$newKey}\n";
    echo "  New CDN URL: {$newCdnUrl}\n";

    try {
        // 1. Copy to new location
        echo "  Copying to new location...\n";
        $s3->copyObject([
            'Bucket' => $bucket,
            'CopySource' => "{$bucket}/{$oldKey}",
            'Key' => $newKey,
            'ACL' => 'private',
            'MetadataDirective' => 'COPY',
        ]);
        echo "  ✓ Copy successful\n";

        // 2. Update database
        echo "  Updating database...\n";
        $updateStmt = $db->prepare("
            UPDATE audio_files
            SET cdn_url = :cdn_url,
                user_uuid = :user_uuid,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':cdn_url' => $newCdnUrl,
            ':user_uuid' => $file['user_uuid'],
            ':id' => $file['id'],
        ]);
        echo "  ✓ Database updated\n";

        // 3. Delete old file
        echo "  Deleting old file...\n";
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => $oldKey,
        ]);
        echo "  ✓ Old file deleted\n";

        echo "  ✓ Migration complete!\n\n";

    } catch (Exception $e) {
        echo "  ✗ ERROR: " . $e->getMessage() . "\n\n";
    }
}

echo "=== MIGRATION COMPLETE ===\n";
