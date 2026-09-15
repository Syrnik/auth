<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authThrottleModel — агрегатные окна счётчика подбора (AUTH-49): апсерт-инкремент,
 * независимость окон друг от друга, reset(), ленивая чистка.
 *
 * window_start передаётся вызывающей стороной (authThrottle::windowStart()), а не
 * вычисляется моделью из time() — это и держит модель без policy-логики, и позволяет
 * тесту адресовать конкретное окно явной строкой вместо реального ожидания или
 * подмены часов.
 *
 * Работает через authTestTemporaryTablesTrait поверх настоящей auth_throttle — тест
 * не трогает боевые данные dev-БД и не оставляет мусора при упавшем assert-е.
 */
class authThrottleModelTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    private authThrottleModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_throttle');
        $this->model = new authThrottleModel();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testGetRowReturnsNullForAnUnhitWindow(): void
    {
        $this->assertNull($this->model->getRow('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00'));
    }

    /**
     * Every test below that isn't specifically about deleteExpired() passes
     * an oversized retention (999999999s, ~31 years): hit()'s own lazy sweep
     * fires on a random 1-in-50 call, and these tests' window_start literals
     * ('2026-01-01', a fixed point already in the past by the time this
     * suite runs) would otherwise occasionally be older than a realistic
     * retention and get swept out from under the very call that just wrote
     * them — a self-inflicted flake, not a real code path: authThrottle
     * always builds window_start from time(), so a row it just created is
     * never older than "now" when the sweep in the same call runs.
     */
    public function testHitCreatesARowWithOneAttempt(): void
    {
        $row = $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);

        $this->assertSame(1, (int)$row['attempts']);
        $this->assertSame('2026-01-01 00:00:00', $row['window_start']);
    }

    public function testHitOnTheSameWindowAccumulatesInOneRow(): void
    {
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $row = $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);

        $this->assertSame(3, (int)$row['attempts']);
    }

    public function testHitOnADifferentWindowStartsAFreshRow(): void
    {
        // Two window_start values for the same key/scope/type are two
        // independent buckets — the point of a fixed-window counter: a
        // later window is never influenced by an earlier one's count.
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $row = $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:15:00', 999999999);

        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testDifferentKeyTypesAndScopesAreIndependent(): void
    {
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $this->model->hit('login', 'ip', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $this->model->hit('otp_send', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);

        $login_row = $this->model->getRow('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00');
        $this->assertSame(1, (int)$login_row['attempts']);
        // Each hit() above used the same key_hash but a different key_type or
        // scope; none of them should have touched another's row.
    }

    public function testResetClearsEveryWindowOfAKey(): void
    {
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:15:00', 999999999);

        $this->model->reset('login', 'login', str_repeat('a', 64));

        $this->assertNull($this->model->getRow('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00'));
        $this->assertNull($this->model->getRow('login', 'login', str_repeat('a', 64), '2026-01-01 00:15:00'));
    }

    public function testResetDoesNotTouchADifferentKey(): void
    {
        $this->model->hit('login', 'login', str_repeat('a', 64), '2026-01-01 00:00:00', 999999999);
        $this->model->hit('login', 'ip', str_repeat('b', 64), '2026-01-01 00:00:00', 999999999);

        $this->model->reset('login', 'login', str_repeat('a', 64));

        $this->assertNotNull($this->model->getRow('login', 'ip', str_repeat('b', 64), '2026-01-01 00:00:00'));
    }

    public function testDeleteExpiredRemovesOnlyOldWindows(): void
    {
        $this->model->hit('login', 'login', str_repeat('a', 64), '2000-01-01 00:00:00', 86400);
        $fresh_window = date('Y-m-d H:i:s', time());
        $this->model->hit('login', 'login', str_repeat('a', 64), $fresh_window, 86400);

        $this->model->deleteExpired(3600);

        $this->assertNull($this->model->getRow('login', 'login', str_repeat('a', 64), '2000-01-01 00:00:00'));
        $this->assertNotNull($this->model->getRow('login', 'login', str_repeat('a', 64), $fresh_window));
    }
}
