# Mautic Kumo MTA Callback

Supported Mautic Version: 5.xx
This Plugins processes an 5xx requests sent by Kumo MTA via webhook and markes contact as bounced if nesseccary.

Supported mailer schemes:
```
smtp
```

## Installation

To install the Mautic Kumo MTA plugin, follow these steps:

1. Run the following command to add the plugin to your Mautic installation:
    ```bash
    composer require jos0405/kumomtacallback
    ```

2. Clear the Mautic cache to ensure the plugin is recognized:
    ```bash
    php bin/console cache:clear
    ```

3. Enable Webhook processing in Kumo MTA, make sure you replace your Mautic URL at YOURMAUTIC

    ```bash

    -- Load DKIM config (uses /opt/kumomta/etc/dkim_data.toml)
    local dkim_signer = dkim_sign:setup{ '/opt/kumomta/etc/dkim_data.toml' }

    -- Configure webhook.site log hook (must be BEFORE queue_helper)
    log_hooks:new_json{
      name = 'webhook',
      url  = 'YOURMAUTIC/mailer/callback',
      log_parameters = {
        headers = { 'Date', 'Message-Id', 'Subject', 'From', 'To' },
     },
     queue_config = {
        retry_interval     = '1m',
        max_retry_interval = '20m',
      },
    }

    -- Load the queue helper, which reads queues.toml
    local queue_helper = queue_module:setup{ '/opt/kumomta/etc/policy/queues.toml' }

    ```

4. After updating the init.lua file, don't forget to restart kumo MTA. Check status if everything is still crisp and curry.
        ```bash
    systemctl restart kumomta
	systemctl status kumomta
        ```

## Contributing

Plz feel free to submit PRs, bugs and suggestions.
