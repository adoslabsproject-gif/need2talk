<?php
require __DIR__ . "/../app/bootstrap.php";

use Need2Talk\Services\Moderation\AudioReportService;

try {
    $service = new AudioReportService();
    $result = $service->getPendingReports(50, 0, ["status" => "pending"]);

    echo "=== RESULT ===\n";
    print_r($result);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "TRACE: " . $e->getTraceAsString() . "\n";
}
