<?php
class UtilsClass
{
    public static function skinsFromJson(): array
    {
        $skins = [];
        $json = json_decode(file_get_contents(__DIR__ . "/../data/".SKIN_LANGUAGE.".json"), true);

        foreach ($json as $skin) {
            $skins[(int) $skin['weapon_defindex']][(int) $skin['paint']] = [
                'weapon_name' => $skin['weapon_name'],
                'paint_name' => $skin['paint_name'],
                'image_url' => $skin['image'],
            ];
        }

        return $skins;
    }

    public static function getWeaponsFromArray()
    {
        $weapons = [];
        $temp = self::skinsFromJson();

        foreach ($temp as $key => $value) {
            if (key_exists($key, $weapons))
                continue;

            $weapons[$key] = [
                'weapon_name' => $value[0]['weapon_name'],
                'paint_name' => $value[0]['paint_name'],
                'image_url' => $value[0]['image_url'],
            ];
        }

        return $weapons;
    }

    public static function getKnifeTypes()
    {
        $knifes = [];
        $temp = self::getWeaponsFromArray();

        foreach ($temp as $key => $weapon) {
            if (
                !in_array($key, [
                    500,
                    503,
                    505,
                    506,
                    507,
                    508,
                    509,
                    512,
                    514,
                    515,
                    516,
                    517,
                    518,
                    519,
                    520,
                    521,
                    522,
                    523,
                    525,
                    526
                ])
            )
                continue;

            $knifes[$key] = [
                'weapon_name' => $weapon['weapon_name'],
                'paint_name' => rtrim(explode("|", $weapon['paint_name'])[0]),
                'image_url' => $weapon['image_url'],
            ];
            $knifes[0] = [
                'weapon_name' => "weapon_knife",
                'paint_name' => "Default knife",
                'image_url' => "https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/img/skins/weapon_knife.png",
            ];
        }

        ksort($knifes);
        return $knifes;
    }

    public static function getSelectedSkins(array $temp)
    {
        $selected = [];

        foreach ($temp as $weapon) {
            $selected[$weapon['weapon_defindex']] =  [
                'weapon_paint_id' => $weapon['weapon_paint_id'],
                'weapon_seed' => $weapon['weapon_seed'],
                'weapon_wear' => $weapon['weapon_wear'],
                'weapon_stattrak' => $weapon['weapon_stattrak'] ?? 0,
            ];
        }

        return $selected;
    }

    /** Every glove model and paint, from the data file. The first entry is the default (no gloves). */
    public static function glovesFromJson(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../data/' . GLOVE_LANGUAGE . '.json'), true) ?: [];
    }

    /**
     * Saves the chosen gloves for one side ($team: 2 = T, 3 = CT). $value is "defindex|paint"; "0|0" removes the gloves.
     * The glove model goes in wp_player_gloves and its paint in wp_player_skins, as the plugin expects.
     */
    public static function saveGloves(DataBase $db, string $steamid, string $value, int $team): void
    {
        [$defindex, $paint] = array_pad(explode('|', $value, 2), 2, '');
        if (!ctype_digit($defindex) || !ctype_digit($paint)) {
            return;
        }
        $defindex = (int) $defindex;
        $paint = (int) $paint;

        $valid = false;
        foreach (self::glovesFromJson() as $glove) {
            if ((int) $glove['weapon_defindex'] === $defindex && (int) $glove['paint'] === $paint) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return;
        }

        if ($defindex === 0) {
            $db->query("DELETE FROM `wp_player_gloves` WHERE `steamid` = :steamid AND `weapon_team` = :team", ["steamid" => $steamid, "team" => $team]);
            return;
        }

        $db->query(
            "INSERT INTO `wp_player_gloves` (`steamid`, `weapon_team`, `weapon_defindex`) VALUES (:steamid, :team, :defindex)
             ON DUPLICATE KEY UPDATE `weapon_defindex` = :defindex_update",
            ["steamid" => $steamid, "team" => $team, "defindex" => $defindex, "defindex_update" => $defindex]
        );
        $db->query(
            "INSERT INTO `wp_player_skins` (`steamid`, `weapon_team`, `weapon_defindex`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`)
             VALUES (:steamid, :team, :defindex, :paint, 0.01, 0)
             ON DUPLICATE KEY UPDATE `weapon_paint_id` = :paint_update",
            ["steamid" => $steamid, "team" => $team, "defindex" => $defindex, "paint" => $paint, "paint_update" => $paint]
        );
    }
}
