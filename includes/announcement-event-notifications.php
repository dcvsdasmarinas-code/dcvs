<?php

function notifyPublishedAnnouncementEventBarangays(mysqli $conn, int $announcementId): void
{
    if ($announcementId <= 0) {
        throw new InvalidArgumentException('A valid event announcement is required for notification.');
    }

    $stmt = $conn->prepare(
        "SELECT a.title, a.event_date, a.venue_name, r.barangay_id
         FROM announcements a
         JOIN announcement_event_responses r ON r.announcement_id = a.announcement_id
         WHERE a.announcement_id = ?
           AND a.is_event = 1
           AND a.status = 'published'
           AND (a.publish_at IS NULL OR a.publish_at <= NOW())
           AND r.notification_sent_at IS NULL
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event notification lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load event notification targets: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    $pendingTargets = [];
    while ($row = $result->fetch_assoc()) {
        $pendingTargets[(int)$row['barangay_id']] = [
            'title' => (string)$row['title'],
            'event_date' => (string)$row['event_date'],
            'venue_name' => (string)$row['venue_name']
        ];
    }
    $stmt->close();

    $usersQuery = $conn->prepare("SELECT user_id FROM users WHERE role = 'barangay' AND status = 'active' AND deleted_at IS NULL AND barangay_id = ?");
    $notificationInsert = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, reference_type, reference_id) VALUES (?, 'announcement_event', ?, ?, 'announcement', ?)");
    $markSent = $conn->prepare('UPDATE announcement_event_responses SET notification_sent_at = NOW() WHERE announcement_id = ? AND barangay_id = ? AND notification_sent_at IS NULL');
    if (!$usersQuery || !$notificationInsert || !$markSent) {
        throw new RuntimeException('Could not prepare event notifications: ' . $conn->error);
    }

    foreach ($pendingTargets as $barangayId => $event) {
        $usersQuery->bind_param('i', $barangayId);
        if (!$usersQuery->execute()) {
            throw new RuntimeException('Could not load event notification recipients: ' . $usersQuery->error);
        }
        $users = $usersQuery->get_result();
        $notificationTitle = 'New event from DCVS';
        $notificationMessage = $event['title'] . ' is scheduled for ' . date('M j, Y', strtotime($event['event_date']))
            . ' at ' . $event['venue_name'] . '. Review the event details and confirm preparedness.';
        while ($user = $users->fetch_assoc()) {
            $userId = (int)$user['user_id'];
            $notificationInsert->bind_param('issi', $userId, $notificationTitle, $notificationMessage, $announcementId);
            if (!$notificationInsert->execute()) {
                throw new RuntimeException('Could not notify a barangay event recipient: ' . $notificationInsert->error);
            }
        }

        $markSent->bind_param('ii', $announcementId, $barangayId);
        if (!$markSent->execute()) {
            throw new RuntimeException('Could not record the event notification: ' . $markSent->error);
        }
    }

    $usersQuery->close();
    $notificationInsert->close();
    $markSent->close();
}
