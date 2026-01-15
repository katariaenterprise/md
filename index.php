<?php
date_default_timezone_set('Asia/Kolkata');

// Function to convert any time format to 12-hour format
function convertTo12Hour($dateTimeString) {
    if (empty($dateTimeString)) return '';
    
    // Try different date formats
    $formats = ['d/m/Y H:i', 'd/m/Y h:i A', 'd-m-Y H:i', 'd-m-Y h:i A'];
    
    foreach ($formats as $format) {
        $dateTime = DateTime::createFromFormat($format, $dateTimeString);
        if ($dateTime) {
            return $dateTime->format('d/m/Y h:i A');
        }
    }
    
    return $dateTimeString; // Return original if no format matches
}
$dataDir = "data/";
$data = [];

if (is_dir($dataDir)) {
    $files = glob($dataDir . "*.json");
    foreach ($files as $file) {
        $entries = json_decode(file_get_contents($file), true);
        if ($entries && is_array($entries)) {
            foreach ($entries as $entry) {
                $entry['filename'] = basename($file);
                if ($entry['status'] === 'COMPLETE') {
                    // Check if completed_time exists, if not use end_date_time
                    $completedTime = null;
                    if (isset($entry['completed_time'])) {
                        $completedTime = $entry['completed_time'];
                    } elseif (!empty($entry['end_date_time'])) {
                        $endTime = parseDateTime($entry['end_date_time']);
                        if ($endTime > 0) {
                            $completedTime = $endTime;
                        }
                    }
                    if ($completedTime) {
                        $currentTime = time();
                        if (($currentTime - $completedTime) > 10800) { // 3 hours = 10800 seconds
                            unlink($file);
                            continue 2;
                        }
                    }
                }
                $data[] = $entry;
            }
        }
    }
}

// Filter by plant if selected
$selectedPlant = $_GET['plant'] ?? 'ALL';
if ($selectedPlant !== 'ALL') {
    $data = array_filter($data, function($row) use ($selectedPlant) {
        return $row['plant'] === $selectedPlant;
    });
}

// Function to parse date with multiple formats
function parseDateTime($dateTimeString) {
    if (empty($dateTimeString)) return 0;
    
    // Handle escaped slashes from JSON
    $dateTimeString = str_replace('\/', '/', $dateTimeString);
    
    $formats = ['d/m/Y h:i A', 'd/m/Y H:i', 'd-m-Y h:i A', 'd-m-Y H:i', 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $dateTime = DateTime::createFromFormat($format, $dateTimeString);
        if ($dateTime && $dateTime->format($format) === $dateTimeString) {
            return $dateTime->getTimestamp();
        }
    }
    
    // Fallback: try strtotime with format conversion
    $converted = str_replace('/', '-', $dateTimeString);
    $timestamp = strtotime($converted);
    if ($timestamp !== false) {
        return $timestamp;
    }
    
    return 0;
}

// Sort data by status priority and latest time within each status
usort($data, function($a, $b) {
    $statusOrder = ['WORK RUNNING' => 1, 'PENDING' => 2, 'COMPLETE' => 3];
    
    // First sort by status priority
    $statusDiff = $statusOrder[$a['status']] - $statusOrder[$b['status']];
    if ($statusDiff !== 0) {
        return $statusDiff;
    }
    
    // Within same status, sort by latest time (newest first)
    if ($a['status'] === 'WORK RUNNING') {
        // For WORK RUNNING, use start_date_time if available, otherwise entry_date_time
        $timeA = !empty($a['start_date_time']) ? parseDateTime($a['start_date_time']) : parseDateTime($a['entry_date_time']);
        $timeB = !empty($b['start_date_time']) ? parseDateTime($b['start_date_time']) : parseDateTime($b['entry_date_time']);
    } elseif ($a['status'] === 'COMPLETE') {
        $timeA = parseDateTime($a['end_date_time']);
        $timeB = parseDateTime($b['end_date_time']);
    } else { // PENDING
        $timeA = parseDateTime($a['entry_date_time']);
        $timeB = parseDateTime($b['entry_date_time']);
    }
    
    return $timeB - $timeA; // Latest first
});

// Load ticker settings
$tickerFile = 'ticker_settings.json';
$tickerSettings = [
    'text' => 'KATARIA ENTERPRISE - MAINTENANCE WORKING PROGRESS CHART - REAL TIME VEHICLE MAINTENANCE TRACKING SYSTEM - RAJKOT | VALSAD | INDORE | SANDILA',
    'speed' => 30
];
if (file_exists($tickerFile)) {
    $savedSettings = json_decode(file_get_contents($tickerFile), true);
    if ($savedSettings) {
        $tickerSettings = array_merge($tickerSettings, $savedSettings);
    }
}

// Count status items
$pendingCount = count(array_filter($data, function($row) { return $row['status'] === 'PENDING'; }));
$workRunningCount = count(array_filter($data, function($row) { return $row['status'] === 'WORK RUNNING'; }));
$completeCount = count(array_filter($data, function($row) { return $row['status'] === 'COMPLETE'; }));
$currentDateTime = date('d/m/Y l h:i A');

// Function to convert minutes to hours and minutes
function formatEstimateTime($minutes) {
    if (empty($minutes)) return '';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) {
        return $hours . ' hr ' . $mins . ' min';
    } elseif ($hours > 0) {
        return $hours . ' hr';
    } else {
        return $mins . ' min';
    }
}

// Function to calculate remaining estimated time
function getRemainingTime($row) {
    if (empty($row['estimate_time']) || $row['status'] === 'PENDING') {
        return $row['estimate_time'] ?? '';
    }
    
    if ($row['status'] === 'COMPLETE') {
        return 0;
    }
    
    if ($row['status'] === 'WORK RUNNING' && !empty($row['start_date_time'])) {
        // Try multiple formats for start_date_time
        $formats = ['d/m/Y h:i A', 'd/m/Y H:i', 'd-m-Y h:i A', 'd-m-Y H:i'];
        $startTime = null;
        
        foreach ($formats as $format) {
            $startTime = DateTime::createFromFormat($format, $row['start_date_time']);
            if ($startTime) break;
        }
        
        if ($startTime) {
            $currentTime = new DateTime();
            $elapsedMinutes = floor(($currentTime->getTimestamp() - $startTime->getTimestamp()) / 60);
            $remainingMinutes = max(0, $row['estimate_time'] - $elapsedMinutes);
            return $remainingMinutes;
        }
    }
    
    return $row['estimate_time'] ?? '';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Maintenance Working Progress Chart</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="png" href="assets/kataria-favicon.png">
        <script>
        function getRefreshTime() {
            let refreshTime = null;

            if (window.innerWidth <= 480) {
                refreshTime = null; // ❌ No auto refresh on mobile
                console.log('Mobile detected - Auto refresh disabled');
            } 
            else if (window.innerWidth <= 768) {
                refreshTime = 300000; // Tablet: 120 seconds
                console.log('Tablet detected - Refresh in 120 seconds');
            } 
            else {
                refreshTime =120000; // Laptop/Desktop: 30 seconds
                console.log('Desktop detected - Refresh in 30 seconds');
            }

            console.log('Screen width:', window.innerWidth + 'px');
            return refreshTime;
        }

        const refreshTime = getRefreshTime();

            if (refreshTime) {
                setTimeout(() => location.reload(), refreshTime);
        }
        </script>

</head>

<body>

<div class="info-panel-left desktop-only">
    <div class="counts">
        <span class="pending-count">Pending: <?= $pendingCount ?></span>
        <span class="running-count">Work Running: <?= $workRunningCount ?></span>
        <span class="complete-count">Complete: <?= $completeCount ?></span>
    </div>
</div>
<div class="info-panel desktop-only">
    <span class="mo">Mo. 9714009465</span></br>
    <span class="mo">Mo. 9824176899</span>
    <div class="date"><?= $currentDateTime ?></div>
</div>

<h1 class="company-name">KATARIA ENTERPRISE</h1>
<h2>MAINTENANCE WORKING PROGRESS CHART</h2>

<div class="info-panel mobile-only">
    <div class="date"><?= $currentDateTime ?></div>
    <div class="counts">
        <span class="pending-count">Pending: <?= $pendingCount ?></span>
        <span class="running-count">Work Running: <?= $workRunningCount ?></span>
        <span class="complete-count">Complete: <?= $completeCount ?></span>
    </div>
</div>

<div class="table-container">
<table>
    <thead>
        <tr>
            <th>Sr.</th>
            <th>Token</th>
            <th>Vehicle</th>
            <th>Work Status</th>
            <th>Approx Time</th>
            <th>Vehicle Work</th>
            <th>Entry Date Time</th>
            <th>Start Date Time</th>
            <th>End Date Time</th>
            <th>
                <form method="GET" style="margin: 0;">
                    <select name="plant" onchange="this.form.submit()" style="border: none; background: transparent; color: black; font-weight: bold;">
                        <option value="ALL" <?= $selectedPlant === 'ALL' ? 'selected' : '' ?>>PLANT</option>
                        <option value="RAJKOT" <?= $selectedPlant === 'RAJKOT' ? 'selected' : '' ?>>RAJKOT</option>
                        <option value="VALSAD" <?= $selectedPlant === 'VALSAD' ? 'selected' : '' ?>>VALSAD</option>
                        <option value="INDORE" <?= $selectedPlant === 'INDORE' ? 'selected' : '' ?>>INDORE</option>
                        <option value="SANDILA" <?= $selectedPlant === 'SANDILA' ? 'selected' : '' ?>>SANDILA</option>
                    </select>
                </form>
            </th>
            <th>Driver</th>
            <th>Mechanic</th>
        </tr>
    </thead>

    <tbody>
        <?php $srNo = 1; foreach ($data as $row): ?>
        <tr class="<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
            <td class="black-text"><?= $srNo++ ?></td>
            <td class="black-text"><?= $row['token'] ?></td>
            <td class="vehicle-data"><?= $row['vehicle'] ?></td>
            <td><?= $row['status'] ?></td>
            <td class="black-text lowercase"><?= formatEstimateTime(getRemainingTime($row)) ?></td>
            <td class="left-align black-text"><?= $row['work'] ?></td>
            <td class="black-text lowercase"><?= convertTo12Hour($row['entry_date_time']) ?></td>
            <td class="black-text lowercase"><?= convertTo12Hour($row['start_date_time']) ?></td>
            <td class="black-text lowercase"><?= convertTo12Hour($row['end_date_time']) ?></td>
            <td class="plant-<?= strtolower($row['plant']) ?>"><?= $row['plant'] ?></td>
            <td class="black-text"><?= $row['driver'] ?></td>
            <td class="black-text"><?= $row['mechanic'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if (!empty(trim($tickerSettings['text']))): ?>
<div class="ticker-container">
    <div class="ticker-text" style="animation-duration: <?= $tickerSettings['speed'] ?>s;">
        <?= htmlspecialchars(str_replace(["\r\n", "\n", "\r"], ' • ', $tickerSettings['text'])) ?>
    </div>
</div>
<style>body { padding-bottom: 50px; }</style>
<?php endif; ?>

</body>
</html>
