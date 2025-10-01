<?php
namespace V1\Core;

final class Validator {
  public static function requiredInt(array $p, string $k): int {
    if (!isset($p[$k]) || !is_numeric($p[$k])) throw new HttpException(422, "$k required integer");
    return (int)$p[$k];
  }
  public static function requiredString(array $p, string $k, int $max=255): string {
    $v = isset($p[$k]) ? trim((string)$p[$k]) : '';
    if ($v==='') throw new HttpException(422, "$k required");
    if (mb_strlen($v)>$max) throw new HttpException(422, "$k max $max");
    return $v;
  }
  public static function enum(array $p, string $k, array $allowed): string {
    $v = isset($p[$k]) ? (string)$p[$k] : '';
    if ($v!=='' && !in_array($v, $allowed, true)) throw new HttpException(422, "$k invalid");
    return $v;
  }
  public static function jsonString(?string $v): ?string {
    if ($v===null || $v==='') return null;
    json_decode($v);
    if (json_last_error()!==JSON_ERROR_NONE) throw new HttpException(422, "invalid json");
    return $v;
  }
}
