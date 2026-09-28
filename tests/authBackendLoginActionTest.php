<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AUTH-560: a new instance key whose account-link field would not fit
 * wa_contact_data.field (varchar(32)) is refused by the backend "Login"
 * screen, whether it arrives as a settings block or only as a login_methods
 * entry; an already stored key is never judged. Private helpers are reached
 * through Reflection, same as authBackendCaptchaActionTest.
 *
 * testmulti's link field is 'testmulti_<key>_id' — 10 + 3 characters around
 * the key, so the longest key that fits is 19.
 */
class authBackendLoginActionTest extends TestCase
{
    private authBackendLoginAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        authPluginManager::clearCache();
        $this->action = new authBackendLoginAction();
    }

    public function testFitsSourceFieldBoundary(): void
    {
        $this->assertTrue(authContactResolver::fitsSourceField(str_repeat('a', 29)));
        $this->assertFalse(authContactResolver::fitsSourceField(str_repeat('a', 30)));
    }

    public function testLinkSourceFitsUsesTheInstanceKey(): void
    {
        $this->assertTrue($this->instance(str_repeat('k', 19))->linkSourceFits());
        $this->assertFalse($this->instance(str_repeat('k', 20))->linkSourceFits());
    }

    public function testMaxInstanceKeyLengthIsProbedFromTheLinkSource(): void
    {
        $this->assertSame(19, $this->invoke('maxInstanceKeyLength', 'testmulti'));
    }

    public function testNewKeyAtTheLimitIsAccepted(): void
    {
        $key = str_repeat('k', 19);
        $this->assertSame([], $this->reject([], [
            'plugin_settings' => ['testmulti' => [$key => []]],
            'login_methods'   => ['testmulti_plugin:' . $key],
        ]));
        $this->assertSame([], $this->notices());
    }

    public function testNewKeyOverTheLimitIsRejectedWithNotice(): void
    {
        $key = str_repeat('k', 20);
        $this->assertSame(['testmulti' => [$key]], $this->reject([], [
            'plugin_settings' => ['testmulti' => [$key => []]],
            'login_methods'   => ['testmulti_plugin:' . $key],
        ]));
        $this->assertCount(1, $this->notices());
    }

    public function testNewKeyPostedOnlyInLoginMethodsIsRejected(): void
    {
        $key = str_repeat('k', 20);
        $this->assertSame(['testmulti' => [$key]], $this->reject([], [
            'login_methods' => ['testmulti_plugin:' . $key],
        ]));
    }

    public function testStoredKeyIsNotJudged(): void
    {
        $key = str_repeat('k', 20);
        $current = ['plugin_settings' => ['testmulti' => [$key => []]]];
        $this->assertSame([], $this->reject($current, [
            'plugin_settings' => ['testmulti' => [$key => []]],
            'login_methods'   => ['testmulti_plugin:' . $key],
        ]));
    }

    public function testUnfitSingleSlotPluginIsStrippedFromLoginMethods(): void
    {
        $unfit = $this->singleSlot(str_repeat('p', 30));
        $fit   = $this->singleSlot('short');

        $this->assertSame(
            ['email', 'short_plugin'],
            $this->invoke(
                'stripUnfitSingleSlotMethods',
                ['email', 'long_plugin', 'short_plugin'],
                ['long' => $unfit, 'short' => $fit]
            )
        );
    }

    private function reject(array $current, array $post): array
    {
        $plugins = ['testmulti' => authPluginManager::get('testmulti_plugin')];
        return $this->invoke('rejectOversizedInstances', $plugins, $current, $post);
    }

    private function instance(string $key): authPlugin
    {
        $plugin = authPluginManager::get('testmulti_plugin:' . $key);
        $this->assertInstanceOf(authPlugin::class, $plugin);
        return $plugin;
    }

    private function singleSlot(string $source): authPlugin
    {
        $plugin = $this->getMockBuilder(authPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInfo', 'getLinkSource'])
            ->getMockForAbstractClass();
        $plugin->method('getInfo')->willReturn([]);
        $plugin->method('getLinkSource')->willReturn($source);
        return $plugin;
    }

    private function notices(): array
    {
        $property = new ReflectionProperty(authBackendLoginAction::class, 'notices');
        $property->setAccessible(true);
        return $property->getValue($this->action);
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    private function invoke(string $name, ...$args)
    {
        $method = new ReflectionMethod(authBackendLoginAction::class, $name);
        $method->setAccessible(true);
        return $method->invoke($this->action, ...$args);
    }
}
