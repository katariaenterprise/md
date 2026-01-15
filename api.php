<?php
header('Content-Type: application/json');

$dataDir = "data/";
$data = [];

if (is_dir($dataDir)) {
    $files = glob($dataDir . "*.json");
    foreach ($files as $file) {
        $entries = json_decode(file_get_contents($file), true);
        if ($entries && is_array($entries)) {
            foreach ($entries as $entry) {
                $entry['filename'] = basename($file);
                if ($entry['status'] === 'COMPLETE' && isset($entry['completed_time'])) {
                    $currentTime = time();
                    $completedTime = $entry['completed_time'];
                    if (($currentTime - $completedTime) > 10800) { // 3 hours = 10800 seconds
                        unlink($file);
                        continue 2;
                    }
                }
                $data[] = $entry;
            }
        }
    }
}

echo json_encode($data);
?>