<?php

// ----------------------------
// FILE: config.php
// ----------------------------
// Place this file next to the monolithic bot file. It defines constants only — no env variables.
// Edit values below to match your bot and deployment.

// Bot API token (from @BotFather)
const BOT_TOKEN = 'YOUR_TELEGRAM_BOT_TOKEN_HERE';

// Secret token to receive messages from telegram.
const TG_SUBSCRIBE_TOKEN = 'anyrandomstring';

// Secret token that external clients must provide when calling protected endpoints.
const EXTERNAL_ACCESS_TOKEN = 'CHANGE_THIS_TO_A_STRONG_TOKEN';

// Optional: admin token for manual actions (can be same as EXTERNAL_ACCESS_TOKEN)
const ADMIN_TOKEN = EXTERNAL_ACCESS_TOKEN;

// Directory for storing data files (relative to this script). Make writable by the web server.
const DATA_DIR = __DIR__ . '/data';

// If using Telethon, in py-script you be abble to load msgs to TG from polling. It disables sending updates to TG through bot
const SEND_TO_TG_THROUGH_EXTERNAL_POLL = false;
// Path to file that stores messages FOR EXTERNAL POLLING JSON
const SEND_TO_TG_THROUGH_EXTERNAL_POLL_MESSAGES_FILE = DATA_DIR . '/externalpoll_to_tg.json.php';

// Public URL that Telegram will call for updates (must be https unless using special Telegram options).
// Example: 'https://example.com/bot.php'
define('BOT_WEBHOOK_URL', 'https://'.$_SERVER["HTTP_HOST"].'/telegram/meshTgBot/bot.php?token=');

// Path to file that stores messages JSON
const MESSAGES_FILE = DATA_DIR . '/messages.json.php';

// Path to file that stores last webhook set timestamp
const WEBHOOK_TIMESTAMP_FILE = DATA_DIR . '/last_webhook_set.txt';

// How often to re-set webhook (in seconds). Requirement: every 30 minutes => 1800 seconds
const WEBHOOK_INTERVAL = 1800;

// Timezone used for formatting dates
const APP_TIMEZONE = 'Europe/Moscow';

// list of IP's for admin functionality
define("ALLOWED_IP", [
    '255.255.255.255',
]);

// ----------------------------
// End of config.php
// ----------------------------