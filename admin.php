<?php
date_default_timezone_set('Asia/Kolkata');
session_start();

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
        $startTime = DateTime::createFromFormat('d/m/Y h:i A', $row['start_date_time']);
        if ($startTime) {
            $currentTime = new DateTime();
            $elapsedMinutes = floor(($currentTime->getTimestamp() - $startTime->getTimestamp()) / 60);
            $remainingMinutes = max(0, $row['estimate_time'] - $elapsedMinutes);
            return $remainingMinutes;
        }
    }
    
    return $row['estimate_time'] ?? '';
}

if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        if ($_POST['username'] === 'admin' && $_POST['password'] === 'admin123') {
            $_SESSION['admin_logged_in'] = true;
            header("Location: admin.php");
            exit;
        } else {
            $error = "Invalid username or password";
        }
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Admin Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="admin.css">
        <link rel="icon" type="png" href="assets/kataria-favicon.png">
    </head>
    <body>
        <div class="login-container">
            <h1>Admin Login</h1>
            <?php if (isset($error)): ?>
                <p class="error"><?= $error ?></p>
            <?php endif; ?>
            <form method="POST">
                <label>Username:</label>
                <input type="text" name="username" required>
                <label>Password:</label>
                <input type="password" name="password" required>
                <button type="submit" name="login">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$dataDir = "data/";
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$data = [];
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

// Function to parse date with multiple formats for admin
function parseAdminDateTime($dateTimeString) {
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
        $timeA = !empty($a['start_date_time']) ? parseAdminDateTime($a['start_date_time']) : parseAdminDateTime($a['entry_date_time']);
        $timeB = !empty($b['start_date_time']) ? parseAdminDateTime($b['start_date_time']) : parseAdminDateTime($b['entry_date_time']);
    } elseif ($a['status'] === 'COMPLETE') {
        $timeA = parseAdminDateTime($a['end_date_time']);
        $timeB = parseAdminDateTime($b['end_date_time']);
    } else { // PENDING
        $timeA = parseAdminDateTime($a['entry_date_time']);
        $timeB = parseAdminDateTime($b['entry_date_time']);
    }
    
    return $timeB - $timeA; // Latest first
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $token = $_POST['token'];
            $vehicle = $_POST['vehicle'];
            
            // Auto-generate entry_date_time for new entries
            $entryDateTime = date('d/m/Y h:i A');
            $entryDateTimeForFile = str_replace(['/', ':', ' ', '-'], '', $entryDateTime);
            $filename = $dataDir . $token . "_" . $vehicle . "_" . $entryDateTimeForFile . ".json";
            
            // Auto-set start_date_time and end_date_time based on status
            $startDateTime = '';
            $endDateTime = '';
            if ($_POST['status'] === 'WORK RUNNING') {
                $startDateTime = date('d/m/Y h:i A');
            } elseif ($_POST['status'] === 'COMPLETE') {
                $startDateTime = date('d/m/Y h:i A');
                $endDateTime = date('d/m/Y h:i A');
            }
            
            $newEntry = [
                'token' => (int)$token,
                'vehicle' => $vehicle,
                'driver' => $_POST['driver'],
                'work' => $_POST['work'],
                'estimate_time' => (int)($_POST['estimate_time'] ?? 0),
                'entry_date_time' => $entryDateTime,
                'start_date_time' => $startDateTime,
                'end_date_time' => $endDateTime,
                'mechanic' => $_POST['mechanic'],
                'status' => $_POST['status'],
                'plant' => $_POST['plant'],
                'entry_by' => $_POST['entry_by']
            ];
            
            // Add completed_time if status is COMPLETE
            if ($_POST['status'] === 'COMPLETE') {
                $newEntry['completed_time'] = time();
                $newEntry['estimate_time'] = 0;
            }
            
            file_put_contents($filename, json_encode([$newEntry], JSON_PRETTY_PRINT));
        } elseif ($_POST['action'] === 'update') {
            $filename = $dataDir . $_POST['filename'];
            if (file_exists($filename)) {
                $entries = json_decode(file_get_contents($filename), true);
                if ($entries && is_array($entries)) {
                    $oldStatus = $entries[0]['status'];
                    $entries[0]['vehicle'] = $_POST['vehicle'];
                    $entries[0]['driver'] = $_POST['driver'];
                    $entries[0]['work'] = $_POST['work'];
                    $entries[0]['estimate_time'] = (int)($_POST['estimate_time'] ?? 0);
                    $entries[0]['mechanic'] = $_POST['mechanic'];
                    $entries[0]['status'] = $_POST['status'];
                    $entries[0]['plant'] = $_POST['plant'];
                    $entries[0]['entry_by'] = $_POST['entry_by'];
                    
                    // Auto-set start_date_time when status changes to WORK RUNNING
                    if ($_POST['status'] === 'WORK RUNNING' && $oldStatus !== 'WORK RUNNING') {
                        $entries[0]['start_date_time'] = date('d/m/Y h:i A');
                    }
                    
                    // Auto-set end_date_time when status changes to COMPLETE
                    if ($_POST['status'] === 'COMPLETE' && $oldStatus !== 'COMPLETE') {
                        $entries[0]['end_date_time'] = date('d/m/Y h:i A');
                        $entries[0]['completed_time'] = time();
                        $entries[0]['estimate_time'] = 0;
                    }
                    
                    file_put_contents($filename, json_encode($entries, JSON_PRETTY_PRINT));
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $filename = $dataDir . $_POST['filename'];
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
        header("Location: admin.php");
        exit;
    } elseif (isset($_POST['quick_status_update'])) {
        $filename = $dataDir . $_POST['filename'];
        if (file_exists($filename)) {
            $entries = json_decode(file_get_contents($filename), true);
            if ($entries && is_array($entries)) {
                $oldStatus = $entries[0]['status'];
                $entries[0]['status'] = $_POST['new_status'];
                
                // Auto-set start_date_time when status changes to WORK RUNNING
                if ($_POST['new_status'] === 'WORK RUNNING' && $oldStatus !== 'WORK RUNNING') {
                    $entries[0]['start_date_time'] = date('d/m/Y h:i A');
                }
                
                // Auto-set end_date_time when status changes to COMPLETE
                if ($_POST['new_status'] === 'COMPLETE' && $oldStatus !== 'COMPLETE') {
                    $entries[0]['end_date_time'] = date('d/m/Y h:i A');
                    $entries[0]['completed_time'] = time();
                    $entries[0]['estimate_time'] = 0;
                }
                
                file_put_contents($filename, json_encode($entries, JSON_PRETTY_PRINT));
            }
        }
        header("Location: admin.php");
        exit;
    }
}

// Handle ticker settings update
if (isset($_POST['update_ticker'])) {
    $tickerSettings = [
        'text' => $_POST['ticker_text'],
        'speed' => (int)$_POST['ticker_speed']
    ];
    file_put_contents('ticker_settings.json', json_encode($tickerSettings, JSON_PRETTY_PRINT));
    header("Location: admin.php");
    exit;
}

// Load ticker settings for admin
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

$editData = null;
if (isset($_GET['edit'])) {
    $filename = $dataDir . $_GET['edit'];
    if (file_exists($filename)) {
        $entries = json_decode(file_get_contents($filename), true);
        if ($entries && is_array($entries)) {
            $editData = $entries[0];
            $editData['filename'] = $_GET['edit'];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Maintenance System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="admin.css">
    <link rel="icon" type="png" href="assets/kataria-favicon.png">

</head>
<body>

<h1>Admin Panel - Maintenance System</h1>
<div style="text-align:center;">
    <a href="index.php" class="btn-view">View Dashboard</a>
    <a href="?logout" class="btn-logout">Logout</a>
</div>

<div class="form-container">
    <h2><?= $editData ? 'Update Entry' : 'Add New Entry' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editData ? 'update' : 'add' ?>">
        <?php if ($editData): ?>
            <input type="hidden" name="filename" value="<?= $editData['filename'] ?>">
        <?php endif; ?>
        
        <label>Token Number:</label>
        <input type="number" name="token" value="<?= $editData['token'] ?? '' ?>" <?= $editData ? 'readonly' : 'required' ?>>
        
        <label>Vehicle Number:</label>
        <input type="text" name="vehicle" value="<?= $editData['vehicle'] ?? '' ?>" required>
        
        <label>Driver Name:</label>
        <input type="text" name="driver" value="<?= $editData['driver'] ?? '' ?>" required>
        
        <label>Work Description:</label>
        <input type="text" name="work" value="<?= $editData['work'] ?? '' ?>" required>
        
        <label>Approx Working Time (minutes):</label>
        <input type="number" name="estimate_time" value="<?= $editData['estimate_time'] ?? '' ?>" min="0" placeholder="Enter time in minutes">
        
        <input type="hidden" name="entry_date_time" value="<?= $editData['entry_date_time'] ?? '' ?>">
        <input type="hidden" name="start_date_time" value="<?= $editData['start_date_time'] ?? '' ?>">
        <input type="hidden" name="end_date_time" value="<?= $editData['end_date_time'] ?? '' ?>">
        
        <label>Mechanic Name:</label>
        <input type="text" name="mechanic" value="<?= $editData['mechanic'] ?? '' ?>">
        
        <label>Status:</label>
        <select name="status" required>
            <option value="PENDING" <?= ($editData['status'] ?? 'PENDING') === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
            <option value="WORK RUNNING" <?= ($editData['status'] ?? '') === 'WORK RUNNING' ? 'selected' : '' ?>>WORK RUNNING</option>
            <option value="COMPLETE" <?= ($editData['status'] ?? '') === 'COMPLETE' ? 'selected' : '' ?>>COMPLETE</option>
        </select>
        
        <label>Plant:</label>
        <select name="plant" required>
            <option value="RAJKOT" <?= ($editData['plant'] ?? '') === 'RAJKOT' ? 'selected' : '' ?>>RAJKOT</option>
            <option value="VALSAD" <?= ($editData['plant'] ?? '') === 'VALSAD' ? 'selected' : '' ?>>VALSAD</option>
            <option value="INDORE" <?= ($editData['plant'] ?? '') === 'INDORE' ? 'selected' : '' ?>>INDORE</option>
            <option value="SANDILA" <?= ($editData['plant'] ?? '') === 'SANDILA' ? 'selected' : '' ?>>SANDILA</option>
        </select>
        
        <label>Entry By:</label>
        <input type="text" name="entry_by" value="<?= $editData['entry_by'] ?? '' ?>" required>
        
        <button type="submit"><?= $editData ? 'Update Entry' : 'Add Entry' ?></button>
        <?php if ($editData): ?>
            <a href="admin.php" class="btn-cancel">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<div class="form-container">
    <h2>Ticker Settings</h2>
    <form method="POST">
        <label>Ticker Text:</label>
        <textarea class="ticker-tb" name="ticker_text" rows="3"><?= htmlspecialchars($tickerSettings['text']) ?></textarea>
        <label>Speed (seconds):</label>
        <input type="number" name="ticker_speed" value="<?= $tickerSettings['speed'] ?>" min="5" max="60" required>
        
        <button type="submit" name="update_ticker">Update Ticker</button>
    </form>
</div>

<div class="table-container">
    <h2>All Entries</h2>
    <table>
        <thead>
            <tr>
                <th>Sr.</th>
                <th>Token</th>
                <th>Vehicle</th>
                <th>Status</th>
                <th>Estimated Time</th>
                <th>Work</th>
                <th>Entry Date Time</th>
                <th>Start Date Time</th>
                <th>End Date Time</th>
                <th>Plant</th>
                <th>Driver</th>
                <th>Mechanic</th>
                <th>Entry By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php $srNo = 1; foreach ($data as $row): ?>
            <tr class="<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                <td><?= $srNo++ ?></td>
                <td><?= $row['token'] ?></td>
                <td class="multiline"><?= $row['vehicle'] ?></td>
                <td class="status-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                    <form method="POST" style="display:inline; margin:0;">
                        <input type="hidden" name="filename" value="<?= $row['filename'] ?>">
                        <input type="hidden" name="quick_status_update" value="1">
                        <select name="new_status" onchange="this.form.submit()" style="border:none; background:transparent; font-weight:bold; color:inherit;">
                            <option value="PENDING" <?= $row['status'] === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
                            <option value="WORK RUNNING" <?= $row['status'] === 'WORK RUNNING' ? 'selected' : '' ?>>WORK RUNNING</option>
                            <option value="COMPLETE" <?= $row['status'] === 'COMPLETE' ? 'selected' : '' ?>>COMPLETE</option>
                        </select>
                    </form>
                </td>
                <td><?= formatEstimateTime(getRemainingTime($row)) ?></td>
                <td class="multiline"><?= $row['work'] ?></td>
                <td><?= convertTo12Hour($row['entry_date_time']) ?></td>
                <td><?= convertTo12Hour($row['start_date_time']) ?></td>
                <td><?= convertTo12Hour($row['end_date_time']) ?></td>
                <td><?= $row['plant'] ?></td>
                <td class="multiline"><?= $row['driver'] ?></td>
                <td class="multiline"><?= $row['mechanic'] ?></td>
                <td><?= $row['entry_by'] ?></td>
                <td class="actions">
                    <a href="?edit=<?= $row['filename'] ?>" class="btn-edit">Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this entry?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="filename" value="<?= $row['filename'] ?>">
                        <button type="submit" class="btn-delete">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
