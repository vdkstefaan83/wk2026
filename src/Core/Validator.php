<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data) {}

    public function required(string $key, ?string $label = null): self
    {
        $val = trim((string)($this->data[$key] ?? ''));
        if ($val === '') {
            $this->errors[$key][] = ($label ?? $key) . ' is required.';
        }
        return $this;
    }

    public function email(string $key, ?string $label = null): self
    {
        $val = trim((string)($this->data[$key] ?? ''));
        if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$key][] = ($label ?? $key) . ' is not a valid email address.';
        }
        return $this;
    }

    public function min(string $key, int $min, ?string $label = null): self
    {
        $val = (string)($this->data[$key] ?? '');
        if ($val !== '' && mb_strlen($val) < $min) {
            $this->errors[$key][] = ($label ?? $key) . " must be at least {$min} characters long.";
        }
        return $this;
    }

    public function in(string $key, array $allowed, ?string $label = null): self
    {
        $val = $this->data[$key] ?? null;
        if ($val !== null && !in_array($val, $allowed, true)) {
            $this->errors[$key][] = ($label ?? $key) . ' is invalid.';
        }
        return $this;
    }

    public function fails(): bool { return !empty($this->errors); }
    public function errors(): array { return $this->errors; }

    public function flashErrors(): void
    {
        foreach ($this->errors as $list) {
            foreach ($list as $msg) {
                Session::flash('error', $msg);
            }
        }
        Session::setOld($this->data);
    }
}
