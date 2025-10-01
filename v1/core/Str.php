<?php
namespace V1\Core;

final class Str {
  public static function pascal(string $s): string {
    return str_replace(' ', '', ucwords(str_replace(['_','-','.'], ' ', strtolower($s))));
  }
  public static function snake(string $s): string {
    $s = preg_replace('/[^\p{L}\p{Nd}]+/u', '_', $s);
    return strtolower(trim($s, '_'));
  }
}
