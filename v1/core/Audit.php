<?php
namespace V1\Core;

final class Audit {
  public static function log(int $actorId, string $entityType, int $entityId, string $action, array $meta=[]): void {
    Crud::create('audit_events', [
      'actor_id'   => $actorId,
      'entity_type'=> $entityType,
      'entity_id'  => $entityId,
      'action'     => $action,
      'meta'       => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
      'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
      'created_at' => date('Y-m-d H:i:s'),
    ]);
  }
}
