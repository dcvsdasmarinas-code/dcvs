<?php
session_name('DCVS_STAFF_SESSION');
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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    case 'save':
        $response = handleSave($conn, $_SESSION['user_id']);
        break;
    case 'delete':
        $response = handleDelete($conn);
        break;
    case 'toggle':
        $response = handleToggle($conn);
        break;
    case 'status':
        $response = handleStatus($conn);
        break;
    case 'duplicate':
        $response = handleDuplicate($conn, $_SESSION['user_id']);
        break;
    case 'get':
        $response = handleGet($conn);
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();

function uploadPhoto($file, $prefix) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return null;
    $filename = $prefix . '_' . uniqid() . '.' . $ext;
    $dest = __DIR__ . '/uploads/announcements/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/announcements/' . $filename;
    }
    return null;
}

function validateFields($p) {
    $category = $p['category'] ?? 'announcement';
    if (!in_array($category, ['advisory', 'announcement'])) $category = 'announcement';

    $subjects = ['animal_health', 'services_programs', 'events', 'general_notices'];
    $subject = $p['subject'] ?? 'general_notices';
    if (!in_array($subject, $subjects)) $subject = 'general_notices';

    $status = $p['status'] ?? 'draft';
    if (!in_array($status, ['draft', 'scheduled', 'published', 'archived'])) $status = 'draft';

    $audienceTypes = ['all', 'barangay', 'selected'];
    $audienceType = $p['audience_type'] ?? 'all';
    if (!in_array($audienceType, $audienceTypes)) $audienceType = 'all';

    $audienceBarangays = trim($p['audience_barangays'] ?? '');
    $barangayId = null;
    if ($audienceType === 'barangay') {
        $barangayId = (int)($p['barangay_id'] ?? 0);
        if ($barangayId <= 0) $barangayId = null;
    }
    if ($audienceType === 'selected') {
        $ids = array_filter(array_map('intval', explode(',', $audienceBarangays)));
        $audienceBarangays = implode(',', $ids);
    }
    if ($audienceType === 'all') {
        $audienceBarangays = '';
    }

    $publishAt = null;
    $publishRaw = trim($p['publish_at'] ?? '');
    if ($publishRaw !== '') {
        $ts = strtotime($publishRaw);
        if ($ts) $publishAt = date('Y-m-d H:i:s', $ts);
    }

    if ($status === 'scheduled' && !$publishAt) {
        $publishAt = date('Y-m-d H:i:s', time() + 3600);
    }

    $featured = !empty($p['is_featured']) ? 1 : 0;

    return [$category, $subject, $status, $audienceType, $audienceBarangays, $barangayId, $publishAt, $featured];
}

function handleSave($conn, $userId) {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $barangayId = null;

    if (empty($title) || empty($content)) {
        return ['success' => false, 'message' => 'Title and content are required'];
    }
    list($category, $subject, $status, $audienceType, $audienceBarangays, $barangayId, $publishAt, $featured) = validateFields($_POST);

    $photoUrl = null;
    $photoHoverUrl = null;
    $oldPhotoUrl = null;
    $oldPhotoHoverUrl = null;

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT photo_url, photo_hover_url FROM announcements WHERE announcement_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $photoUrl = $oldPhotoUrl = $existing['photo_url'] ?? null;
        $photoHoverUrl = $oldPhotoHoverUrl = $existing['photo_hover_url'] ?? null;
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo'], 'ann_photo');
        if ($uploaded) {
            $photoUrl = $uploaded;
        }
    }

    if ($category === 'advisory' && isset($_FILES['photo_hover']) && $_FILES['photo_hover']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo_hover'], 'ann_hover');
        if ($uploaded) {
            $photoHoverUrl = $uploaded;
        }
    }

    $now = date('Y-m-d H:i:s');
    $archivedAt = null;

    if ($id > 0) {
        if ($status === 'archived') {
            $archivedAt = $now;
        }
        $stmt = $conn->prepare("UPDATE announcements SET title=?, location=?, content=?, category=?, subject=?, link_url=?, barangay_id=?, status=?, publish_at=?, archived_at=?, audience_type=?, audience_barangays=?, is_featured=?, photo_url=?, photo_hover_url=?, updated_at=? WHERE announcement_id=?");
        $stmt->bind_param("ssssssisssssisssi", $title, $location, $content, $category, $subject, $linkUrl, $barangayId, $status, $publishAt, $archivedAt, $audienceType, $audienceBarangays, $featured, $photoUrl, $photoHoverUrl, $now, $id);
    } else {
        if ($status === 'archived') {
            $status = 'draft';
        }
        $stmt = $conn->prepare("INSERT INTO announcements (title, location, content, category, subject, link_url, barangay_id, status, publish_at, audience_type, audience_barangays, is_featured, photo_url, photo_hover_url, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssissssississ", $title, $location, $content, $category, $subject, $linkUrl, $barangayId, $status, $publishAt, $audienceType, $audienceBarangays, $featured, $photoUrl, $photoHoverUrl, $userId, $now, $now);
    }

    if ($stmt->execute()) {
        if ($photoUrl !== $oldPhotoUrl && $oldPhotoUrl && file_exists(__DIR__ . '/' . $oldPhotoUrl)) unlink(__DIR__ . '/' . $oldPhotoUrl);
        if ($photoHoverUrl !== $oldPhotoHoverUrl && $oldPhotoHoverUrl && file_exists(__DIR__ . '/' . $oldPhotoHoverUrl)) unlink(__DIR__ . '/' . $oldPhotoHoverUrl);
        $savedId = $id > 0 ? $id : $stmt->insert_id;
        $stmt->close();
        $label = $category === 'advisory' ? 'Advisory' : 'Announcement';
        return ['success' => true, 'message' => "$label saved successfully", 'id' => $savedId];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Database error: ' . $conn->error];
}

function handleDelete($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT photo_url, photo_hover_url FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($row) {
            if ($row['photo_url'] && file_exists(__DIR__ . '/' . $row['photo_url'])) unlink(__DIR__ . '/' . $row['photo_url']);
            if ($row['photo_hover_url'] && file_exists(__DIR__ . '/' . $row['photo_hover_url'])) unlink(__DIR__ . '/' . $row['photo_hover_url']);
        }
        $stmt->close();
        return ['success' => true, 'message' => 'Deleted successfully'];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Delete failed'];
}

function handleGet($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    $stmt = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return ['success' => true, 'data' => $row];
    }
    return ['success' => false, 'message' => 'Not found'];
}

function handleToggle($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT status FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $newStatus = $row['status'] === 'published' ? 'draft' : 'published';
    $stmt = $conn->prepare("UPDATE announcements SET status = ?, publish_at = NULL, archived_at = NULL, updated_at = NOW() WHERE announcement_id = ?");
    $stmt->bind_param("si", $newStatus, $id);
    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Status updated', 'status' => $newStatus];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Update failed'];
}

function handleStatus($conn) {
    $id = (int)($_POST['id'] ?? 0);
    $next = $_POST['status'] ?? '';
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    if (!in_array($next, ['draft', 'scheduled', 'published', 'archived'])) return ['success' => false, 'message' => 'Invalid status'];

    $stmt = $conn->prepare("SELECT status FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $archivedAt = $next === 'archived' ? date('Y-m-d H:i:s') : null;
    $publishAt = null;
    if ($next === 'scheduled') {
        $publishAt = date('Y-m-d H:i:s', time() + 3600);
    }
    if ($next === 'published') {
        $publishAt = date('Y-m-d H:i:s');
    }

    $stmt = $conn->prepare("UPDATE announcements SET status = ?, publish_at = ?, archived_at = ?, updated_at = NOW() WHERE announcement_id = ?");
    $stmt->bind_param("sssi", $next, $publishAt, $archivedAt, $id);
    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Status updated', 'status' => $next];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Update failed'];
}

function handleDuplicate($conn, $userId) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO announcements (title, location, content, category, subject, link_url, barangay_id, status, publish_at, audience_type, audience_barangays, is_featured, photo_url, photo_hover_url, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
    $newTitle = $row['title'] . ' (Copy)';
    $stmt->bind_param("ssssssissississ", $newTitle, $row['location'], $row['content'], $row['category'], $row['subject'], $row['link_url'], $row['barangay_id'], $row['audience_type'], $row['audience_barangays'], $row['is_featured'], $row['photo_url'], $row['photo_hover_url'], $userId, $now, $now);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();
        return ['success' => true, 'message' => 'Duplicated as draft', 'id' => $newId];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Duplicate failed'];
}