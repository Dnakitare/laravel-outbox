<?php

namespace Laravel\Outbox\Tests\Unit;

use Laravel\Outbox\Exceptions\SerializationException;
use Laravel\Outbox\Support\PayloadSerializer;
use Laravel\Outbox\Tests\Stubs\TestEvent;
use Laravel\Outbox\Tests\TestCase;

class PayloadSerializerTest extends TestCase
{
    public function test_round_trip_preserves_object(): void
    {
        $serializer = $this->app->make(PayloadSerializer::class);

        $original = new TestEvent('hello');
        $encoded = $serializer->serialize($original);
        $decoded = $serializer->unserialize($encoded);

        $this->assertInstanceOf(TestEvent::class, $decoded);
        $this->assertSame('hello', $decoded->data);
    }

    public function test_tampered_payload_rejected(): void
    {
        $serializer = $this->app->make(PayloadSerializer::class);
        $encoded = $serializer->serialize(new TestEvent('safe'));

        $tampered = $encoded.'X';

        $this->expectException(SerializationException::class);
        $serializer->unserialize($tampered);
    }

    public function test_payload_signed_with_different_key_rejected(): void
    {
        $a = new PayloadSerializer($this->app['config'], 'key-a');
        $b = new PayloadSerializer($this->app['config'], 'key-b');

        $encoded = $a->serialize(new TestEvent('x'));

        $this->expectException(SerializationException::class);
        $b->unserialize($encoded);
    }

    public function test_class_not_in_allowlist_rejected(): void
    {
        // Swap the allowlist for this test only.
        $this->app['config']->set('outbox.serialization.allowed_classes', []);
        $serializer = $this->app->make(PayloadSerializer::class);

        $encoded = $serializer->serialize(new TestEvent('x'));

        $this->expectException(SerializationException::class);
        $serializer->unserialize($encoded);
    }
}
