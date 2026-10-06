# Communications
Telegram, webhook, outbox durable, notifikasi.

ROUTES: 070_config_telegram_admin.php, 075_communication_channels.php,
        076_communication_webhook.php, 080_telegram_webhook.php,
        090_operations_communications.php
SUPPORT: 045_communication_core.php, 050_integrations_telegram_mail.php

Invariant: Primary-only side effects, outbox at-least-once.
