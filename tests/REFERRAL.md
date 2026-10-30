# Referral tests

Run PHP 8.1+ with `pdo_mysql`, `curl` and `mbstring` enabled.

```powershell
php tests/referral_test.php
php tests/payment_feed_test.php
php tests/multilingual_description_test.php
```

The integration test requires an **isolated local MySQL/MariaDB instance**, with root allowed to create disposable databases. It never reads `config/database.php`; each run creates and drops a random `referral_test_*` database. Do not point it at a production database server.

Start the mock Telegram endpoint in a separate terminal:

```powershell
New-Item -ItemType Directory -Force tmp/referral-tools | Out-Null
$env:REFERRAL_MOCK_STATE = "$PWD/tmp/referral-tools/mock-state.json"
php -S 127.0.0.1:18092 tests/fixtures/referral_telegram.php
```

Run the integration test with the same absolute mock-state path:

```powershell
$env:REFERRAL_MOCK_STATE = "$PWD/tmp/referral-tools/mock-state.json"
$env:REFERRAL_TEST_TELEGRAM_URL = 'http://127.0.0.1:18092/'
$env:REFERRAL_TEST_DB_PORT = '13376'
$env:REFERRAL_TEST_DB_PASSWORD = 'your-local-test-password'
php tests/referral_integration_test.php
```

Coverage includes first-start attribution and gating, a 1 VND purchase, wallet/QR/hybrid payment, manual/API fulfillment, failed fulfillment, membership errors, manual exceptions, reward snapshots, actual concurrent payout processes, daily limits, rollover, notifications and in-place migrations. Telegram requests go to the localhost mock; no real messages are sent.

The test renders `tmp/referral-tools/admin-vi.html` and `admin-en.html` from the actual Admin module for desktop/mobile inspection using the repository CSS.
