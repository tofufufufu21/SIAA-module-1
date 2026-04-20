<?php
// app/core/Validator.php

namespace App\Core;

/**
 * Validator — reusable input validation.
 * Services call this before processing data.
 */
class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    // ── Rules ────────────────────────────────────────────────

    public function required(string $field, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            $this->errors[$field] = "'{$label}' is required.";
        }
        return $this;
    }

    public function maxLength(string $field, int $max, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if (strlen((string) $value) > $max) {
            $this->errors[$field] = "'{$label}' must not exceed {$max} characters.";
        }
        return $this;
    }

    public function numeric(string $field, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->errors[$field] = "'{$label}' must be a number.";
        }
        return $this;
    }

    public function inList(string $field, array $allowed, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->errors[$field] = "'{$label}' must be one of: " . implode(', ', $allowed);
        }
        return $this;
    }

    public function date(string $field, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value !== null && $value !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                $this->errors[$field] = "'{$label}' must be a valid date (YYYY-MM-DD).";
            }
        }
        return $this;
    }

    public function positive(string $field, string $label = ''): self
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value !== null && $value !== '' && (float) $value <= 0) {
            $this->errors[$field] = "'{$label}' must be a positive number.";
        }
        return $this;
    }

    // ── Result ───────────────────────────────────────────────

    public function fails(): bool    { return !empty($this->errors); }
    public function passes(): bool   { return empty($this->errors); }
    public function errors(): array  { return $this->errors; }

    public function firstError(): string
    {
        return array_values($this->errors)[0] ?? 'Validation failed.';
    }

    /**
     * Get a sanitized value from the input.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        $val = $this->data[$field] ?? $default;
        if (is_string($val)) $val = trim($val);
        return ($val === '') ? $default : $val;
    }
}
