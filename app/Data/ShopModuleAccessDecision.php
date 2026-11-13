<?php

namespace App\Data;

final class ShopModuleAccessDecision
{
    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $code,
        public readonly array $moduleKeys,
        public readonly string $message,
    ) {}

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public static function allow(array $moduleKeys = [], string $message = 'Module access is available.'): self
    {
        return new self(true, null, array_values(array_unique($moduleKeys)), $message);
    }

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public static function deny(string $code, array $moduleKeys, string $message): self
    {
        return new self(false, $code, array_values(array_unique($moduleKeys)), $message);
    }

    /**
     * @return array{allowed: bool, code: ?string, moduleKeys: array<int, string>, message: string}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'moduleKeys' => $this->moduleKeys,
            'message' => $this->message,
        ];
    }
}
