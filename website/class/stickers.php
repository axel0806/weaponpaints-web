<?php
// Sticker data and storage for WeaponPaints. Each weapon has five slots in wp_player_skins.weapon_sticker_0..4,
// stored as "id;schema;x;y;wear;scale;rotation". The plugin parses that format in WeaponSynchronization.cs.
class StickerClass
{
    public const SLOTS = 5;
    public const EMPTY = '0;0;0;0;0;0;0';

    private static ?array $byId = null;

    /** All stickers keyed by id, loaded from the data file once per request. */
    public static function load(): array
    {
        if (self::$byId === null) {
            self::$byId = [];
            $json = json_decode(file_get_contents(__DIR__ . '/../data/' . STICKER_LANGUAGE . '.json'), true) ?: [];
            foreach ($json as $sticker) {
                $id = (string) $sticker['id'];
                self::$byId[$id] = ['id' => (int) $id, 'name' => $sticker['name'], 'image' => $sticker['image']];
            }
        }
        return self::$byId;
    }

    /** Lowercases and strips accents, so "Skürr" and "skurr" match. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists('Normalizer')) {
            $text = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($text, Normalizer::FORM_D)) ?? $text;
        }
        return $text;
    }

    /**
     * One page of stickers matching $query. Every word of the query must appear somewhere in the name
     * (player, team, finish, event, year), in any order. Better matches come first: whole-name hits, then
     * words that start a name part, then plain substrings; plain stickers come before foil/holo/gold variants.
     */
    public static function search(string $query, int $page, int $perPage = 60): array
    {
        $words = preg_split('/[\s|,]+/u', self::fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_slice($words, 0, 8);
        $page = max(1, $page);

        $matches = [];
        foreach (self::load() as $sticker) {
            if (!$words) {
                $matches[] = $sticker;
                continue;
            }
            $title = preg_replace('/^sticker\s*\|\s*/u', '', self::fold($sticker['name']));
            $score = 0;
            foreach ($words as $word) {
                $pos = mb_strpos($title, $word);
                if ($pos === false) {
                    continue 2;
                }
                if ($pos === 0) {
                    $score += 3;
                } elseif (preg_match('/[\s|(,-]/u', mb_substr($title, $pos - 1, 1))) {
                    $score += 2;
                } else {
                    $score += 1;
                }
            }
            if (count($words) === 1 && preg_match('/^' . preg_quote($words[0], '/') . '(\s*\(|\s*\||$)/u', $title)) {
                $score += 5; // the query is the whole player/team name
            }
            if (str_contains($title, '(')) {
                $score -= 0.5; // foil/holo/gold/glitter variants after the plain sticker
            }
            $matches[] = $sticker + ['score' => $score];
        }
        if ($words) {
            usort($matches, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        }

        $total = count($matches);
        $items = [];
        foreach (array_slice($matches, ($page - 1) * $perPage, $perPage) as $match) {
            unset($match['score']);
            $items[] = $match;
        }
        return ['items' => $items, 'total' => $total, 'has_more' => $total > $page * $perPage];
    }

    /** Parses a stored slot string. Anything malformed reads as an empty slot. */
    public static function parse(?string $raw): array
    {
        $parts = explode(';', (string) $raw);
        if (count($parts) !== 7) {
            $parts = explode(';', self::EMPTY);
        }
        return [
            'id' => (int) $parts[0],
            'schema' => (int) $parts[1],
            'x' => (float) $parts[2],
            'y' => (float) $parts[3],
            'wear' => (float) $parts[4],
            'scale' => (float) $parts[5],
            'rotation' => (float) $parts[6],
        ];
    }

    /** Checks user input and returns a clean slot. Throws InvalidArgumentException when a value is out of range. */
    public static function validate(array $slot): array
    {
        $id = filter_var($slot['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id === false || $id < 0) {
            throw new InvalidArgumentException('Invalid sticker id');
        }
        if ($id === 0) {
            return self::parse(self::EMPTY);
        }
        if (!isset(self::load()[(string) $id])) {
            throw new InvalidArgumentException("Unknown sticker id $id");
        }

        $clean = [
            'id' => $id,
            'schema' => 0,
            'x' => self::number($slot, 'x', -1.0, 1.0, 0.0),
            'y' => self::number($slot, 'y', -1.0, 1.0, 0.0),
            'wear' => self::number($slot, 'wear', 0.0, 1.0, 0.0),
            'scale' => self::number($slot, 'scale', 0.1, 5.0, 1.0),
            'rotation' => self::number($slot, 'rotation', 0.0, 360.0, 0.0),
        ];
        return $clean;
    }

    /** Formats a slot for the database. Callers must validate first. */
    public static function serialize(array $slot): string
    {
        if ($slot['id'] === 0) {
            return self::EMPTY;
        }
        return sprintf(
            '%d;%d;%.4f;%.4f;%.4f;%.4f;%.4f',
            $slot['id'],
            $slot['schema'],
            $slot['x'],
            $slot['y'],
            $slot['wear'],
            $slot['scale'],
            $slot['rotation'],
        );
    }

    private static function number(array $slot, string $key, float $min, float $max, float $default): float
    {
        if (!isset($slot[$key]) || $slot[$key] === '') {
            return $default;
        }
        $value = filter_var($slot[$key], FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException("$key must be between $min and $max");
        }
        return (float) $value;
    }
}
