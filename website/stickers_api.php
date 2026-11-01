<?php
// JSON API for the sticker editor. Requires a Steam login. POST also needs the session's CSRF token.
require_once 'class/config.php';
require_once 'class/database.php';
require_once 'steamauth/steamauth.php';
require_once 'class/utils.php';
require_once 'class/stickers.php';
require_once 'class/csrf.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (!isset($_SESSION['steamid'])) {
    respond(401, ['error' => 'Sign in with Steam first']);
}
$steamid = $_SESSION['steamid'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = substr((string) ($_GET['q'] ?? ''), 0, 64);
    $page = min(max(1, (int) ($_GET['page'] ?? 1)), 1000);
    respond(200, StickerClass::search($query, $page));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method not allowed']);
}

if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    respond(403, ['error' => 'Your session expired. Reload the page and try again.']);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(400, ['error' => 'Invalid request body']);
}

// Only weapons, not knives (knife defindexes start at 500). The player must already have a skin for this weapon.
$defindex = filter_var($body['defindex'] ?? null, FILTER_VALIDATE_INT);
$weapons = UtilsClass::getWeaponsFromArray();
if ($defindex === false || !isset($weapons[$defindex]) || $defindex >= 500) {
    respond(400, ['error' => 'Stickers can only be set on a weapon']);
}

$team = filter_var($body['team'] ?? null, FILTER_VALIDATE_INT);
if (!in_array($team, [2, 3], true)) {
    respond(400, ['error' => 'Unknown side']);
}

$db = new DataBase();
$hasSkin = $db->select(
    'SELECT COUNT(*) AS n FROM `wp_player_skins` WHERE `steamid` = :steamid AND `weapon_team` = :team AND `weapon_defindex` = :defindex',
    ['steamid' => $steamid, 'team' => $team, 'defindex' => $defindex]
);
if (empty($hasSkin) || (int) $hasSkin[0]['n'] === 0) {
    respond(409, ['error' => 'Select a skin for this weapon first']);
}

$slots = $body['slots'] ?? null;
if (!is_array($slots) || count($slots) !== StickerClass::SLOTS) {
    respond(400, ['error' => 'Send exactly ' . StickerClass::SLOTS . ' sticker slots']);
}

try {
    $serialized = [];
    foreach (array_values($slots) as $slot) {
        if (!is_array($slot)) {
            throw new InvalidArgumentException('Each slot must be an object');
        }
        $serialized[] = StickerClass::serialize(StickerClass::validate($slot));
    }
} catch (InvalidArgumentException $e) {
    respond(400, ['error' => $e->getMessage()]);
}

// Written for the side being edited only.
$db->query(
    'UPDATE `wp_player_skins` SET
        `weapon_sticker_0` = :s0, `weapon_sticker_1` = :s1, `weapon_sticker_2` = :s2,
        `weapon_sticker_3` = :s3, `weapon_sticker_4` = :s4
     WHERE `steamid` = :steamid AND `weapon_team` = :team AND `weapon_defindex` = :defindex',
    [
        's0' => $serialized[0], 's1' => $serialized[1], 's2' => $serialized[2],
        's3' => $serialized[3], 's4' => $serialized[4],
        'steamid' => $steamid, 'team' => $team, 'defindex' => $defindex,
    ]
);

respond(200, ['ok' => true]);
