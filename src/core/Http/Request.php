<?php
declare(strict_types=1);

namespace App\core\Http;

final class Request
{
    public function isMethod(?string $method = null): bool
    {
        return $_SERVER['REQUEST_METHOD'] === \strtoupper($method);
    }

    public function getMethod(?string $name = null): mixed
    {
        return !$this->isEmpty($name) ? $_REQUEST[$name] : $_REQUEST;
    }

    public function getFiles(string $name = ''): array
    {
        return !$this->isFiles($name) ? $_FILES[$name] : $_FILES;
    }

    public function isFiles(): bool
    {
        return $this->isEmpty($_FILES);
    }

    public function isEmpty(mixed $name): bool
    {
        return !isset($name);
    }

}
