<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Session\Infrastructure\Store;

/**
 * Regression cover for S-32j: the guest CSRF token was seeded but never
 * persisted, so no cookie was sent and the next request minted a different
 * token — the anonymous GET->POST handshake could never succeed.
 */
#[CoversClass(Store::class)]
final class GuestCsrfTokenTest extends TestCase
{
    /** An in-memory handler so we can observe exactly what gets written. */
    private function handler(): \SessionHandlerInterface
    {
        return new class implements \SessionHandlerInterface {
            /** @var array<string,string> */
            public array $written = [];
            /** @var array<string,string> */
            public array $store = [];

            public function open(string $path, string $name): bool { return true; }
            public function close(): bool { return true; }
            public function read(string $id): string|false { return $this->store[$id] ?? ''; }
            public function write(string $id, string $data): bool
            {
                $this->written[$id] = $data;
                $this->store[$id]   = $data;
                return true;
            }
            public function destroy(string $id): bool { unset($this->store[$id]); return true; }
            public function gc(int $max): int|false { return 0; }
        };
    }

    public function test_reading_the_token_makes_the_session_worth_persisting(): void
    {
        $store = new Store('hkm_session', $this->handler());
        $store->start();

        $token = $store->token();

        self::assertNotSame('', $token);
        self::assertTrue(
            $store->shouldPersist(),
            'a token that has been handed to a form must survive to the submit',
        );
    }

    public function test_the_token_is_stable_across_the_request_that_stores_it(): void
    {
        $handler = $this->handler();

        $first = new Store('hkm_session', $handler);
        $first->start();
        $token = $first->token();
        $first->save();

        // The next request presents the same id — the handshake must still work.
        $second = new Store('hkm_session', $handler);
        $second->start($first->id());

        self::assertSame($token, $second->token(), 'the token must not be re-minted');
    }

    public function test_a_session_that_never_reads_the_token_is_not_worth_persisting(): void
    {
        // The original intent, preserved: a bot fetching one page that renders
        // no form must not create a stored session.
        $store = new Store('hkm_session', $this->handler());
        $store->start();

        self::assertFalse($store->shouldPersist());
    }

    public function test_ordinary_writes_still_mark_the_session_for_persistence(): void
    {
        $store = new Store('hkm_session', $this->handler());
        $store->start();
        $store->put('k', 'v');

        self::assertTrue($store->shouldPersist());
    }
}
