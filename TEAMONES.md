# Teamones EasySms PHP 8.5 compatibility fork

Based on upstream EasySms 3.5.0 (6ec405e467df6189ffcffe19f3b4ef453cb3ced3), MIT; original authors, namespace and public API preserved.

- Package: teamones/easy-sms, Overtrue\EasySms namespace unchanged.
- QcloudGateway supports existing sdk_app_id/app_key/sign_name configuration without application adapters or extend() calls.
- AppKey-only credentials select the legacy protocol; secret_id/secret_key select the upstream v3 implementation. Explicit api_version=legacy/v3 overrides selection; invalid versions fail clearly.
- Legacy endpoint is configurable; defaults to the original Tencent endpoint. Existing template order, phone, response and signature contract retained.
- Modern implementation retained. No migration of production credentials or business behavior is required.
- Legacy signing uses random_int; malformed provider responses fail as gateway errors under PHP 8.5/E_ALL.
- Run composer test:compatibility and the upstream QcloudGatewayTest. Tests are mocked and send no messages.
