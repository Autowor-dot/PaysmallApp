<?php
/**
 * api/notifications.php
 *
 * GET  [?unread=1] [&limit=50]                    -> list the customer's notifications
 * POST { action: "mark_read", id } | { action: "mark_all_read" } | { action: "delete", id }
 */

require_once __DIR__ . '/config.php';

require_method('GET', 'POST');

$userId = require_auth();

try {
    if (!table_exists('notifications')) {
        json_response(200, ['success' => true, 'data' => ['notifications' => [], 'unread_count' => 0]]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $unreadOnly = (string) ($_GET['unread'] ?? '') === '1';
        $limit      = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

        $sql = 'SELECT * FROM notifications WHERE user_id = ?' . ($unreadOnly ? ' AND is_read = 0' : '')
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $rows = db_select($sql, 'i', [$userId]);

        $list = array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'type'       => $r['type'] ?? 'general',
                'title'      => $r['title'] ?? 'Account Update',
                'message'    => $r['message'] ?? '',
                'is_read'    => (bool) ($r['is_read'] ?? 0),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows);

        $unread = db_select('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', 'i', [$userId]);

        json_response(200, [
            'success' => true,
            'data'    => ['notifications' => $list, 'unread_count' => (int) ($unread[0]['c'] ?? 0)],
        ]);
    }

    $input  = request_input();
    $action = input_string($input, 'action', 40);

    switch ($action) {
        case 'mark_read':
            $id = input_int($input, 'id');
            if ($id === null) {
                json_error(400, 'A notification id is required.');
            }
            db_execute('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', 'ii', [$id, $userId]);
            break;

        case 'mark_all_read':
            db_execute('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', 'i', [$userId]);
            break;

        case 'delete':
            $id = input_int($input, 'id');
            if ($id === null) {
                json_error(400, 'A notification id is required.');
            }
            db_execute('DELETE FROM notifications WHERE id = ? AND user_id = ?', 'ii', [$id, $userId]);
            break;

        default:
            json_error(400, 'Unknown action.');
    }

    $unread = db_select('SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0', 'i', [$userId]);
    json_response(200, ['success' => true, 'data' => ['unread_count' => (int) ($unread[0]['c'] ?? 0)]]);
} catch (mysqli_sql_exception $e) {
    server_error($e, 'notifications');
}
