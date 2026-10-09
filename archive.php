<?php
/**
 * Archive Page
 * Displays all archived items (pets, reports, appointments)
 */

ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

error_log("Archive page - User ID: $userId");

// Initialize arrays
$archivedPets = [];
$archivedReports = [];
$archivedAppointments = [];

// Fetch archived pets (with archived_at column)
$archivedPetsQuery = $conn->prepare("SELECT * FROM pets WHERE owner_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
if (!$archivedPetsQuery) {
    error_log("Prepare failed for archived pets: " . $conn->error);
} else {
    $archivedPetsQuery->bind_param("i", $userId);
    $archivedPetsQuery->execute();
    $archivedPetsResult = $archivedPetsQuery->get_result();
    while ($row = $archivedPetsResult->fetch_assoc()) {
        $archivedPets[] = $row;
    }
    $archivedPetsQuery->close();
    error_log("Found " . count($archivedPets) . " archived pets for user $userId");
}

// Fetch archived reports (with archived_at column)
$archivedReportsQuery = $conn->prepare("SELECT * FROM reports WHERE reporter_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$archivedReportsQuery->bind_param("i", $userId);
$archivedReportsQuery->execute();
$archivedReportsResult = $archivedReportsQuery->get_result();
$archivedReports = [];
while ($row = $archivedReportsResult->fetch_assoc()) {
    $archivedReports[] = $row;
}
$archivedReportsQuery->close();

// Fetch archived appointments (with archived_at column)
$archivedAppointmentsQuery = $conn->prepare("SELECT *, reference_id FROM appointments WHERE user_id = ? AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$archivedAppointmentsQuery->bind_param("i", $userId);
$archivedAppointmentsQuery->execute();
$archivedAppointmentsResult = $archivedAppointmentsQuery->get_result();
$archivedAppointments = [];
while ($row = $archivedAppointmentsResult->fetch_assoc()) {
    $archivedAppointments[] = $row;
}
$archivedAppointmentsQuery->close();

function residentArchiveStatusStyle($status, $isAppointment = false) {
    $status = strtolower((string)$status);
    if ($isAppointment && $status === 'scheduled') {
        return 'background:#0E8131;color:#FFFFFF;border-color:#0E8131;';
    }
    if ($isAppointment && $status === 'completed') {
        return 'background:#DBEAFE;color:#1E40AF;border-color:#BFDBFE;';
    }
    if (!$isAppointment && $status === 'resolved') {
        return 'background:#0E8131;color:#FFFFFF;border-color:#0E8131;';
    }
    return 'background:#F4F4F5;color:#52525B;border-color:#E4E4E7;';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive - DCVS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        .archive-section {
            background: #fff;
            padding: 2rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .archive-section h2 {
            margin-bottom: 1.5rem;
            color: #333;
        }
        .archive-table {
            width: 100%;
            border-collapse: collapse;
        }
        .archive-table th,
        .archive-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .archive-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        .archive-table tr:hover {
            background: #f9f9f9;
        }
        .archive-status-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border: 1px solid;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        .no-items {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-paw"></i>
                    <span>DCVS</span>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard-resident.php" class="nav-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="archive.php" class="nav-item active">
                    <i class="fas fa-archive"></i>
                    <span>Archive</span>
                </a>
            </nav>
            <div class="sidebar-footer">
                <a href="logout.php" class="nav-item logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <header class="top-bar">
                <div class="top-bar-left">
                    <button class="menu-toggle"><i class="fas fa-bars"></i></button>
                    <h1 class="page-title">Archive</h1>
                </div>
                <div class="top-bar-right">
                    <div class="user-profile">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            <span class="user-role">Resident</span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Archive Content -->
            <div class="content-wrapper">
                <!-- Archived Pets -->
                <div class="archive-section">
                    <h2>Archived Pets (<?php echo count($archivedPets); ?>)</h2>
                    <?php if (empty($archivedPets)): ?>
                        <p class="no-items">No archived pets found.</p>
                    <?php else: ?>
                        <table class="archive-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Species</th>
                                    <th>Breed</th>
                                    <th>Gender</th>
                                    <th>Age</th>
                                    <th>Archived Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedPets as $pet): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pet['reference_id'] ?? '#PET-'.str_pad($pet['pet_id'], 4, '0', STR_PAD_LEFT)); ?></td>
                                        <td><strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong></td>
                                        <td><?php echo ucfirst($pet['pet_type']); ?></td>
                                        <td><?php echo htmlspecialchars($pet['breed'] ?? '-'); ?></td>
                                        <td><?php echo ucfirst($pet['gender']); ?></td>
                                        <td><?php echo $pet['age']; ?> yrs</td>
                                        <td><?php echo date('M d, Y', strtotime($pet['archived_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Archived Reports -->
                <div class="archive-section">
                    <h2>Archived Reports (<?php echo count($archivedReports); ?>)</h2>
                    <?php if (empty($archivedReports)): ?>
                        <p class="no-items">No archived reports found.</p>
                    <?php else: ?>
                        <table class="archive-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Archived</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedReports as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['reference_id'] ?? '#RPT-'.str_pad($report['report_id'], 4, '0', STR_PAD_LEFT)); ?></td>
                                        <td><?php echo ucfirst($report['report_type']); ?></td>
                                        <td><?php echo htmlspecialchars($report['location_address']); ?></td>
                                        <td><span class="archive-status-pill" style="<?php echo residentArchiveStatusStyle($report['status']); ?>"><?php echo ucfirst($report['status']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($report['archived_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Archived Appointments -->
                <div class="archive-section">
                    <h2>Archived Appointments (<?php echo count($archivedAppointments); ?>)</h2>
                    <?php if (empty($archivedAppointments)): ?>
                        <p class="no-items">No archived appointments found.</p>
                    <?php else: ?>
                        <table class="archive-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Archived</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedAppointments as $appt): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appt['reference_id'] ?? '#APT-'.str_pad($appt['appointment_id'], 4, '0', STR_PAD_LEFT)); ?></td>
                                        <td><?php echo ucfirst($appt['appointment_type']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($appt['preferred_date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($appt['preferred_time'])); ?></td>
                                        <td><span class="archive-status-pill" style="<?php echo residentArchiveStatusStyle($appt['status'], true); ?>"><?php echo ucfirst($appt['status']); ?></span></td>
                                        <td><?php echo date('M d, Y', strtotime($appt['archived_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/dashboard.js"></script>
</body>
</html>
