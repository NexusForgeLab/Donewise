<?php
require_once __DIR__ . '/../app/auth.php';
$user = require_login();
$pdo = db();

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400); exit;
}

$pdo->beginTransaction();
try {
    // Update sort_order for each tag sent
    $st = $pdo->prepare("UPDATE tags SET sort_order=? WHERE id=? AND group_id=?");
    foreach ($data['ids'] as $index => $id) {
        // IDs are tag IDs. We ignore 'untagged' or non-numeric IDs.
        if (is_numeric($id) && $id > 0) {
            $st->execute([$index, (int)$id, $user['group_id']]);
        }
    }
    $pdo->commit();
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
