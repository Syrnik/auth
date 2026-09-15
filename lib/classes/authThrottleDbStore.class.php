<?php

/**
 * Built-in authThrottleStore, backed by authThrottleModel / auth_throttle.
 * Used whenever a domain has no throttle_store plugin configured — the
 * default, and the only option until someone actually needs a shared
 * counter across app servers (see authThrottleStore's own docblock).
 */
class authThrottleDbStore implements authThrottleStore
{
    // How far back the model's lazy sweep reaches on the rare hit() that
    // triggers it. This is only a bound on table growth, not a policy value
    // — but it is only safe as a fixed constant because
    // authBackendCaptchaAction clamps throttle_login_window/
    // throttle_ip_window/throttle_lockout to this same value. Without that
    // clamp an admin-configured window longer than this could make a fresh
    // row's own window_start already older than the sweep's cutoff, so the
    // very hit() that just wrote it could delete it before returning —
    // public so the backend action enforces the matching ceiling from one
    // definition instead of a second hardcoded 86400.
    public const RETENTION_SECONDS = 86400;

    private authThrottleModel $model;

    public function __construct()
    {
        $this->model = new authThrottleModel();
    }

    public function getRow(string $scope, string $key_type, string $key_hash, string $window_start): ?array
    {
        return $this->model->getRow($scope, $key_type, $key_hash, $window_start);
    }

    public function hit(string $scope, string $key_type, string $key_hash, string $window_start): array
    {
        return $this->model->hit($scope, $key_type, $key_hash, $window_start, self::RETENTION_SECONDS);
    }

    public function reset(string $scope, string $key_type, string $key_hash): void
    {
        $this->model->reset($scope, $key_type, $key_hash);
    }
}
