<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

// Auto-heal missing notifications columns
try {
    $nCols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_read', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        if (in_array('read_at', $nCols)) {
            $pdo->exec("UPDATE notifications SET is_read = IF(read_at IS NOT NULL, 1, 0) WHERE 1");
        }
    }
    if (!in_array('body', $nCols) && in_array('message', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN body TEXT NULL");
        $pdo->exec("UPDATE notifications SET body = message WHERE body IS NULL");
    }
    if (!in_array('type', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(32) NOT NULL DEFAULT 'info'");
    }
    if (!in_array('link', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN link VARCHAR(500) NULL");
    }
    if (!in_array('created_at', $nCols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Throwable $ignored) {}

// Admin Notifications Management API
// Actions: list_sent, broadcast, mark_read, delete, stats

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------------
    // list_sent — All notifications sent to users (by admin/system)
    // -------------------------------------------------------------------------
    case 'list_sent':
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset   = ($page - 1) * $limit;
        $role     = $_GET['role'] ?? '';
        $search   = trim($_GET['search'] ?? '');

        $where = ["1=1"];
        $params = [];

        if ($role) {
            $where[] = "u.role = :role";
            $params[':role'] = $role;
        }
        if ($search) {
            $where[] = "(n.title LIKE :s OR n.body LIKE :s2 OR u.full_name LIKE :s3)";
            $params[':s']  = "%$search%";
            $params[':s2'] = "%$search%";
            $params[':s3'] = "%$search%";
        }
        $wClause = implode(' AND ', $where);

        $total = $pdo->prepare("SELECT COUNT(*) FROM notifications n
            LEFT JOIN users u ON u.id = n.user_id
            WHERE $wClause");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $stmt = $pdo->prepare("SELECT
            n.id, n.user_id, n.title, n.body, n.type, n.is_read,
            n.created_at, n.read_at,
            u.full_name AS user_name, u.email AS user_email, u.role AS user_role
            FROM notifications n
            LEFT JOIN users u ON u.id = n.user_id
            WHERE $wClause
            ORDER BY n.created_at DESC
            LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'notifications' => $rows,
            'total'  => $totalCount,
            'page'   => $page,
            'pages'  => (int)ceil($totalCount / $limit),
        ]);
        break;

    // -------------------------------------------------------------------------
    // broadcast — Send a notification to all users of a given role (or all)
    // -------------------------------------------------------------------------
    case 'broadcast':
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $title = trim($data['title'] ?? '');
        $body  = trim($data['body']  ?? '');
        $type  = $data['type']  ?? 'info';
        $role  = $data['role']  ?? '';   // 'student','instructor','admin', or '' = all
        $link  = $data['link']  ?? null;

        if (!$title || !$body) {
            json_error('العنوان والمحتوى مطلوبان');
        }
        $validTypes = ['info','success','warning','danger','system'];
        if (!in_array($type, $validTypes, true)) $type = 'info';

        // Get target users
        if ($role) {
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE role = :r AND is_active = 1");
            $uStmt->execute([':r' => $role]);
        } else {
            $uStmt = $pdo->query("SELECT id FROM users WHERE is_active = 1");
        }
        $users = $uStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$users) {
            json_error('لا يوجد مستخدمون في هذه الفئة');
        }

        $ins = $pdo->prepare("INSERT INTO notifications
            (user_id, title, body, type, link, created_at)
            VALUES (:uid, :title, :body, :type, :link, NOW())");

        $pdo->beginTransaction();
        try {
            foreach ($users as $uid) {
                $ins->execute([
                    ':uid'   => $uid,
                    ':title' => $title,
                    ':body'  => $body,
                    ':type'  => $type,
                    ':link'  => $link,
                ]);
            }

            // Log to audit_logs if table exists
            try {
                $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, details, created_at)
                    VALUES (:uid, 'broadcast_notification', 'notifications', :det, NOW())")
                    ->execute([
                        ':uid' => $currentUser['id'],
                        ':det' => json_encode(['title'=>$title,'role'=>$role,'recipients'=>count($users)]),
                    ]);
            } catch (Exception $e) {}

            $pdo->commit();
            json_success(['sent_to' => count($users), 'message' => 'تم إرسال الإشعار بنجاح']);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('خطأ أثناء الإرسال: ' . $e->getMessage());
        }
        break;

    // -------------------------------------------------------------------------
    // send_one — Send a notification to a specific user
    // -------------------------------------------------------------------------
    case 'send_one':
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $userId = (int)($data['user_id'] ?? 0);
        $title  = trim($data['title'] ?? '');
        $body   = trim($data['body']  ?? '');
        $type   = $data['type'] ?? 'info';
        $link   = $data['link'] ?? null;

        if (!$userId || !$title || !$body) {
            json_error('user_id والعنوان والمحتوى مطلوبون');
        }

        // Verify user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE id = :id");
        $check->execute([':id' => $userId]);
        if (!$check->fetchColumn()) json_error('المستخدم غير موجود');

        $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, link, created_at)
            VALUES (:uid, :title, :body, :type, :link, NOW())")
            ->execute([
                ':uid'   => $userId,
                ':title' => $title,
                ':body'  => $body,
                ':type'  => $type,
                ':link'  => $link,
            ]);

        json_success(['notification_id' => $pdo->lastInsertId()]);
        break;

    // -------------------------------------------------------------------------
    // stats — Notification statistics
    // -------------------------------------------------------------------------
    case 'stats':
        $total  = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
        $unread = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
        $today  = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = CURDATE()")->fetchColumn();

        // By type
        $byType = $pdo->query("SELECT type, COUNT(*) as cnt FROM notifications GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);

        // By role (recipients)
        $byRole = $pdo->query("SELECT u.role, COUNT(n.id) as cnt
            FROM notifications n
            JOIN users u ON u.id = n.user_id
            GROUP BY u.role")->fetchAll(PDO::FETCH_ASSOC);

        json_success([
            'total'   => $total,
            'unread'  => $unread,
            'today'   => $today,
            'by_type' => $byType,
            'by_role' => $byRole,
        ]);
        break;

    // -------------------------------------------------------------------------
    // delete — Delete a notification by ID
    // -------------------------------------------------------------------------
    case 'delete':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) json_error('id مطلوب');

        $pdo->prepare("DELETE FROM notifications WHERE id = :id")->execute([':id' => $id]);
        json_success(['deleted' => true]);
        break;

    // -------------------------------------------------------------------------
    // delete_bulk — Delete all read notifications older than N days
    // -------------------------------------------------------------------------
    case 'delete_bulk':
        $days = max(1, (int)($_GET['days'] ?? $_POST['days'] ?? 30));
        $pdo->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :d DAY)")
            ->execute([':d' => $days]);
        $deleted = $pdo->rowCount() === false ? 0 : (int)$pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        json_success(['deleted' => true, 'days' => $days]);
        break;

    // -------------------------------------------------------------------------
    // my_notifications — Admin's own notifications
    // -------------------------------------------------------------------------
    case 'my_notifications':
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 15)));
        $stmt = $pdo->prepare("SELECT id, title, body, type, is_read, created_at, link
            FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim");
        $stmt->bindValue(':uid', $currentUser['id'], PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read=0")
            ->execute([':uid' => $currentUser['id']]) ? $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read=0") : 0;
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read=0");
        $unreadStmt->execute([':uid' => $currentUser['id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        json_success(['notifications' => $rows, 'unread' => $unreadCount]);
        break;

    // -------------------------------------------------------------------------
    // mark_all_read — Mark all of admin's notifications as read
    // -------------------------------------------------------------------------
    case 'mark_all_read':
        $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=:uid AND is_read=0")
            ->execute([':uid' => $currentUser['id']]);
        json_success(['marked' => true]);
        break;

    default:
        json_error('action غير معروف', 400);
}
