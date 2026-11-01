<?php
// Values come from the environment (set in .env via docker-compose), so no secrets live in this file.
define('SKIN_LANGUAGE', 'skins_en');
define('STICKER_LANGUAGE', getenv('STICKER_LANGUAGE') ?: 'stickers_en');
define('GLOVE_LANGUAGE', getenv('GLOVE_LANGUAGE') ?: 'gloves_en');

define('DB_HOST', getenv('DB_HOST') ?: '172.18.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'weaponpaints');
define('DB_USER', getenv('DB_USER') ?: 'weaponpaints');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('WEB_STYLE_DARK', true);

define('STEAM_API_KEY', getenv('STEAM_API_KEY') ?: '');
define('STEAM_DOMAIN_NAME', getenv('STEAM_DOMAIN_NAME') ?: '');
define('STEAM_LOGOUT_PAGE', '');
define('STEAM_LOGIN_PAGE', '');
