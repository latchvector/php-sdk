<?php

declare(strict_types=1);

namespace LatchVector\Sso;

/**
 * Declare a device at login for a longer-lived, device-bound session. Omit
 * $deviceId on first login; the service returns one on the {@see TokenPair} —
 * store it and pass it back next time so the same device row is reused.
 * $name/$platform show up in the user's device list.
 */
final class DeviceInfo
{
    public function __construct(
        public readonly ?string $deviceId = null,
        public readonly ?string $name = null,
        public readonly ?string $platform = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['deviceId' => $this->deviceId, 'name' => $this->name, 'platform' => $this->platform];
    }
}
