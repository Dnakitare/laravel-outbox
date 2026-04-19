<?php

namespace Dnakitare\Outbox\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dnakitare\Outbox\Exceptions\SerializationException;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Serializes outbox payloads with a tamper-evident integrity tag and
 * a class allowlist on deserialization.
 *
 * The serialized format is: sha256_hmac_hex || ':' || serialized_bytes.
 *
 * The HMAC is computed over the serialized bytes using the application
 * key. Both production-quality defenses are in play:
 *
 *   1. Integrity: if anyone tampers with payload bytes (including DBAs or
 *      an attacker who gains DB write), the HMAC mismatch rejects the
 *      payload before unserialize() runs.
 *
 *   2. Allowlist: unserialize() is called with allowed_classes, so even
 *      if the HMAC was bypassed somehow (rare — e.g., leaked app key),
 *      only classes the application declares safe will rehydrate.
 */
class PayloadSerializer
{
    public function __construct(
        protected Config $config,
        protected string $hmacKey
    ) {}

    public function serialize(mixed $value): string
    {
        $bytes = serialize($value);
        $hash = $this->hash($bytes);

        return $hash.':'.$bytes;
    }

    public function unserialize(string $data): mixed
    {
        [$hash, $bytes] = $this->split($data);

        if (! hash_equals($this->hash($bytes), $hash)) {
            throw new SerializationException(
                'Outbox payload integrity check failed. The stored payload was modified or signed with a different key.'
            );
        }

        $allowed = $this->allowedClasses();

        $value = @unserialize($bytes, ['allowed_classes' => $allowed]);

        if ($value === false && $bytes !== 'b:0;') {
            throw new SerializationException('Outbox payload could not be unserialized.');
        }

        if ($value instanceof \__PHP_Incomplete_Class) {
            throw new SerializationException(
                'Outbox payload contains a class that is not in the allowlist. Add it to outbox.serialization.allowed_classes.'
            );
        }

        return $value;
    }

    /**
     * Hash the payload bytes without deserializing. Useful for dedup and
     * audit.
     */
    public function hash(string $bytes): string
    {
        return hash_hmac('sha256', $bytes, $this->hmacKey);
    }

    public function verifyHash(string $data): bool
    {
        try {
            [$hash, $bytes] = $this->split($data);
        } catch (SerializationException) {
            return false;
        }

        return hash_equals($this->hash($bytes), $hash);
    }

    /**
     * @return array<int, class-string>|true
     */
    protected function allowedClasses(): array|bool
    {
        $allowed = $this->config->get('outbox.serialization.allowed_classes', []);

        // Explicit true permits every class — an escape hatch for apps
        // that fully trust their DB and payload sources. Default is the
        // configured allowlist.
        if ($allowed === true) {
            return true;
        }

        // DateTime-family and Laravel's SerializableClosure are virtually
        // always needed — add them silently so the common case works.
        $defaults = [
            \DateTime::class,
            \DateTimeImmutable::class,
            Carbon::class,
            CarbonImmutable::class,
            \Illuminate\Support\Carbon::class,
        ];

        return array_values(array_unique(array_merge($defaults, (array) $allowed)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function split(string $data): array
    {
        $pos = strpos($data, ':');

        if ($pos === false || $pos !== 64) {
            throw new SerializationException('Outbox payload is not in the expected signed format.');
        }

        return [substr($data, 0, 64), substr($data, 65)];
    }
}
