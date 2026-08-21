<?php
declare(strict_types=1);

function audit_log(PDO $pdo, string $action, string $entityType, ?string $entityId = null, array $details = []): void {
    if (!db_table_exists($pdo, 'hsg_audit_log')) return;
    $adminId = current_admin_id();
    $userId = current_link_user_id();
    $actorType = $adminId ? 'admin' : ($userId ? 'link' : 'system');
    $actorId = $adminId ?: $userId;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ipHash = $ip !== '' ? hash('sha256', $ip) : null;
    $st = $pdo->prepare('INSERT INTO hsg_audit_log(actor_type,actor_id,actor_name,action,entity_type,entity_id,details_json,ip_hash) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$actorType, $actorId, current_actor_name() ?: 'System', $action, $entityType, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null, $ipHash]);
}
