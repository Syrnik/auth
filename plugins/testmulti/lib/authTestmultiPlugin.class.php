<?php

/**
 * Test fixture for the multi-instance plugin machinery (like testguard for
 * guards): an OAuth-style method that can be enabled several times with
 * different settings ('testmulti_plugin:one', 'testmulti_plugin:two').
 * There is no real provider behind it — the "OAuth redirect" goes straight
 * to our own callback route, which reports which instance handled it and
 * with what settings. Enough to verify the whole id round-trip:
 * login button → authenticate() → callback URL with ':' → handleCallback().
 */
class authTestmultiPlugin extends authPlugin implements authMethod
{
    public function getId(): string
    {
        return $this->getMethodId();
    }

    public function getCallbackUrl(): string
    {
        return authHelper::getCallbackUrl($this->getMethodId());
    }

    public function authenticate(array $params): ?int
    {
        // A real plugin would redirect to the provider's authorize endpoint here.
        wa()->getResponse()->redirect($this->getCallbackUrl());
        return null;
    }

    public function handleCallback(array $params): authCallbackResult
    {
        $settings = $this->getDomainSettings();
        throw new waException(sprintf(
            'Test plugin callback OK: instance "%s", base_url "%s". No real provider behind it.',
            $this->getInstance() ?? '(none)',
            $settings['base_url'] ?? '(not set)'
        ));
    }

    public function getSettingsControls(array $settings): array
    {
        return [
            'name' => [
                'label' => 'Название кнопки',
                'value' => (string)($settings['name'] ?? ''),
                'hint'  => 'Отображается на странице входа. Пусто — имя плагина.',
            ],
            'base_url' => [
                'label' => 'Base URL',
                'value' => (string)($settings['base_url'] ?? ''),
            ],
            'client_id' => [
                'label' => 'Client ID',
                'value' => (string)($settings['client_id'] ?? ''),
            ],
        ];
    }
}
