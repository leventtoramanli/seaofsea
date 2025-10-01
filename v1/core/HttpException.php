<?php
namespace V1\Core;

class HttpException extends \RuntimeException {
  private array $data;
  public function __construct(int $code, string $message, array $data = []) {
    parent::__construct($message, $code);
    $this->data = $data;
  }
  public function getData(): array { return $this->data; }
}
