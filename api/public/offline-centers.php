<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->query("SELECT id,name,description,address,phone,whatsapp,map_url,cover_image FROM offline_centers WHERE status='active' ORDER BY updated_at DESC,id DESC");
    $centers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($centers) {
        $ids = array_map(static fn(array $center): int => (int)$center['id'], $centers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $mediaStmt = $pdo->prepare("SELECT id,center_id,media_type,title,original_name,file_path,mime_type FROM offline_center_media WHERE center_id IN ($placeholders) ORDER BY id DESC");
        $mediaStmt->execute($ids);
        $mediaByCenter = [];
        foreach ($mediaStmt->fetchAll(PDO::FETCH_ASSOC) as $media) {
            $media['id'] = (int)$media['id'];
            $media['center_id'] = (int)$media['center_id'];
            $mediaByCenter[$media['center_id']][] = $media;
        }
        $slotStmt = $pdo->prepare("SELECT id,center_id,title,booking_date,start_time,end_time,capacity,notes FROM offline_center_slots WHERE center_id IN ($placeholders) AND status='open' AND booking_date>=CURDATE() ORDER BY booking_date ASC,start_time ASC");
        $slotStmt->execute($ids);
        $slotsByCenter = [];
        foreach ($slotStmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
            $slot['id'] = (int)$slot['id'];
            $slot['center_id'] = (int)$slot['center_id'];
            $slot['capacity'] = (int)$slot['capacity'];
            $slotsByCenter[$slot['center_id']][] = $slot;
        }
        foreach ($centers as &$center) {
            $center['id'] = (int)$center['id'];
            $center['media'] = $mediaByCenter[$center['id']] ?? [];
            $center['slots'] = $slotsByCenter[$center['id']] ?? [];
        }
        unset($center);
    }
    echo json_encode(['success' => true, 'centers' => $centers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[Public offline centers] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر تحميل السناتر حاليًا.'], JSON_UNESCAPED_UNICODE);
}
