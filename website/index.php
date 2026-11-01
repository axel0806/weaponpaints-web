<?php
require_once 'class/config.php';
require_once 'class/database.php';
require_once 'steamauth/steamauth.php';
require_once 'class/utils.php';
require_once 'class/stickers.php';
require_once 'class/csrf.php';

$db = new DataBase();
if (isset($_SESSION['steamid'])) {

	$steamid = $_SESSION['steamid'];

	// Each side has its own loadout: ?team=t (weapon_team 2, the default) or ?team=ct (weapon_team 3).
	$teamSlug = (($_GET['team'] ?? 't') === 'ct') ? 'ct' : 't';
	$team = $teamSlug === 'ct' ? 3 : 2;
	$teamLabel = $teamSlug === 'ct' ? 'CT side' : 'T side';
	$teamUrl = $_SERVER['PHP_SELF'] . '?team=' . $teamSlug;

	$weapons = UtilsClass::getWeaponsFromArray();
	$skins = UtilsClass::skinsFromJson();
    $querySelected = $db->select("
        SELECT `weapon_defindex`, MAX(`weapon_paint_id`) AS `weapon_paint_id`, MAX(`weapon_wear`) AS `weapon_wear`, MAX(`weapon_seed`) AS `weapon_seed`, MAX(`weapon_stattrak`) AS `weapon_stattrak`
        FROM `wp_player_skins`
        WHERE `steamid` = :steamid AND `weapon_team` = :team
        GROUP BY `weapon_defindex`, `steamid`
    ", ["steamid" => $steamid, "team" => $team]);
	$selectedSkins = UtilsClass::getSelectedSkins($querySelected);
	$selectedKnife = $db->select("SELECT * FROM `wp_player_knife` WHERE `wp_player_knife`.`steamid` = :steamid AND `wp_player_knife`.`weapon_team` = :team LIMIT 1", ["steamid" => $steamid, "team" => $team]);
	$knifes = UtilsClass::getKnifeTypes();

	// Gloves: the glove model from this side's row, and its paint from the skins table.
	$gloves = UtilsClass::glovesFromJson();
	$gloveRow = $db->select("SELECT `weapon_defindex` FROM `wp_player_gloves` WHERE `steamid` = :steamid AND `weapon_team` = :team LIMIT 1", ["steamid" => $steamid, "team" => $team]);
	$selectedGlove = '0|0';
	if (!empty($gloveRow)) {
		$glovePaint = $db->select("SELECT `weapon_paint_id` FROM `wp_player_skins` WHERE `steamid` = :steamid AND `weapon_team` = :team AND `weapon_defindex` = :defindex LIMIT 1", ["steamid" => $steamid, "team" => $team, "defindex" => (int) $gloveRow[0]['weapon_defindex']]);
		if (!empty($glovePaint)) {
			$selectedGlove = (int) $gloveRow[0]['weapon_defindex'] . '|' . (int) $glovePaint[0]['weapon_paint_id'];
		}
	}
	$selectedGloveName = 'Default gloves';
	$selectedGloveImage = '';
	foreach ($gloves as $glove) {
		if ((int) $glove['weapon_defindex'] . '|' . (int) $glove['paint'] === $selectedGlove) {
			$selectedGloveName = $glove['paint_name'];
			$selectedGloveImage = $glove['image'];
			break;
		}
	}

	// Current stickers per weapon for this side.
	$stickerRows = $db->select("SELECT `weapon_defindex`, `weapon_sticker_0`, `weapon_sticker_1`, `weapon_sticker_2`, `weapon_sticker_3`, `weapon_sticker_4` FROM `wp_player_skins` WHERE `steamid` = :steamid AND `weapon_team` = :team", ["steamid" => $steamid, "team" => $team]);
	$stickersByWeapon = [];
	foreach ($stickerRows as $row) {
		$slots = [];
		for ($i = 0; $i < StickerClass::SLOTS; $i++) {
			$slot = StickerClass::parse($row["weapon_sticker_$i"]);
			if ($slot['id'] > 0) {
				$sticker = StickerClass::load()[(string) $slot['id']] ?? null;
				$slot['name'] = $sticker['name'] ?? "Unknown sticker {$slot['id']}";
				$slot['image'] = $sticker['image'] ?? '';
			}
			$slots[] = $slot;
		}
		$stickersByWeapon[(int) $row['weapon_defindex']] = $slots;
	}

	// The defindex of the knife that is equipped (0 = default knife, which has no skin to adjust).
	$equippedKnifeDefindex = 0;
	if (!empty($selectedKnife)) {
		foreach ($knifes as $knifeDefindex => $knife) {
			if ($knifeDefindex !== 0 && $selectedKnife[0]['knife'] == $knife['weapon_name']) {
				$equippedKnifeDefindex = (int) $knifeDefindex;
				break;
			}
		}
	}
	$gloveDefindex = (int) explode('|', $selectedGlove)[0];

	// Wear and pattern index for the equipped gloves or knife. Written to this side's row.
	if (isset($_POST['item_settings'])) {
		$target = filter_var($_POST['item_defindex'] ?? null, FILTER_VALIDATE_INT);
		$wear = filter_var($_POST['wear'] ?? null, FILTER_VALIDATE_FLOAT);
		$seed = filter_var($_POST['seed'] ?? null, FILTER_VALIDATE_INT);
		$allowed = $target !== false && $target > 0 && ($target === $equippedKnifeDefindex || $target === $gloveDefindex);
		if (csrf_valid($_POST['csrf'] ?? null) && $allowed && $wear !== false && $wear >= 0.0 && $wear <= 1.0 && $seed !== false && $seed >= 0 && $seed <= 1000) {
			$db->query("UPDATE `wp_player_skins` SET `weapon_wear` = :wear, `weapon_seed` = :seed WHERE `steamid` = :steamid AND `weapon_team` = :team AND `weapon_defindex` = :defindex", ["wear" => $wear, "seed" => $seed, "steamid" => $steamid, "team" => $team, "defindex" => $target]);
		}
		header("Location: {$teamUrl}");
		exit;
	}

	if (isset($_POST['gloves'])) {
		if (csrf_valid($_POST['csrf'] ?? null)) {
			UtilsClass::saveGloves($db, $steamid, (string) $_POST['gloves'], $team);
		}
		header("Location: {$teamUrl}");
		exit;
	}

	if (isset($_POST['forma'])) {
		$ex = explode("-", $_POST['forma']);

		if ($ex[0] == "knife") {
			$db->query("INSERT INTO `wp_player_knife` (`steamid`, `knife`, `weapon_team`) VALUES(:steamid, :knife, :team) ON DUPLICATE KEY UPDATE `knife` = :knife_update", ["steamid" => $steamid, "knife" => $knifes[$ex[1]]['weapon_name'], "team" => $team, "knife_update" => $knifes[$ex[1]]['weapon_name']]);
		} else {
			if (array_key_exists($ex[1], $skins[$ex[0]]) && isset($_POST['wear']) && $_POST['wear'] >= 0.00 && $_POST['wear'] <= 1.00 && isset($_POST['seed'])) {
				$wear = floatval($_POST['wear']); // wear
				$seed = intval($_POST['seed']); // seed
				$stattrak = isset($_POST['stattrak']) ? 1 : 0; // StatTrak toggle (unchecked boxes are not sent)
				if (array_key_exists($ex[0], $selectedSkins)) {
					$db->query("UPDATE wp_player_skins SET weapon_paint_id = :weapon_paint_id, weapon_wear = :weapon_wear, weapon_seed = :weapon_seed, weapon_stattrak = :stattrak WHERE steamid = :steamid AND weapon_team = :team AND weapon_defindex = :weapon_defindex", ["steamid" => $steamid, "team" => $team, "weapon_defindex" => $ex[0], "weapon_paint_id" => $ex[1], "weapon_wear" => $wear, "weapon_seed" => $seed, "stattrak" => $stattrak]);
				} else {
					$db->query("INSERT INTO wp_player_skins (`steamid`, `weapon_defindex`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`, `weapon_stattrak`, `weapon_team`) VALUES (:steamid, :weapon_defindex, :weapon_paint_id, :weapon_wear, :weapon_seed, :stattrak, :team)", ["steamid" => $steamid, "weapon_defindex" => $ex[0], "weapon_paint_id" => $ex[1], "weapon_wear" => $wear, "weapon_seed" => $seed, "stattrak" => $stattrak, "team" => $team]);
				}
			}
		}
		header("Location: {$teamUrl}");
	}
}
?>

<!DOCTYPE html>
<html lang="en"<?php if(WEB_STYLE_DARK) echo 'data-bs-theme="dark"'?>>

<head>
	<meta charset="utf-8">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
	<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.min.js"></script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="style.css?v=<?php echo substr(md5_file(__DIR__ . '/style.css'), 0, 8) ?>">
	<title>CS2 Simple Weapon Paints</title>
</head>

<body>

	<?php
	if (!isset($_SESSION['steamid'])) {
		echo "<header class='site-bar'><span class='site-title'>Weapon skins</span></header><div class='site-intro'><p>To choose your loadout, sign in with Steam. ";
		loginbutton("rectangle");
		echo "</p></div>";
	} else {
		echo "<header class='site-bar'><span class='site-title'>Weapon skins</span><nav class='side-tabs' aria-label='Loadout side'><a class='side-tab side-t" . ($teamSlug === 't' ? ' active' : '') . "' href='{$_SERVER['PHP_SELF']}?team=t'>T side</a><a class='side-tab side-ct" . ($teamSlug === 'ct' ? ' active' : '') . "' href='{$_SERVER['PHP_SELF']}?team=ct'>CT side</a></nav><a class='btn btn-outline-secondary btn-sm' href='{$_SERVER['PHP_SELF']}?logout'>Sign out</a></header>";
		echo "<div class='page-head'><div><h1>Your {$teamLabel} loadout</h1><p class='muted' id='skin-count'></p></div><input id='skin-search' class='form-control skin-search' type='search' placeholder='Search weapons' aria-label='Search weapons' autocomplete='off'></div>";
		echo "<div class='card-group mt-2' id='skin-grid'>";
	?>

		<div class="col-sm-2">
			<div class="card text-center mb-3 border border-primary">
				<div class="card-body">
					<?php
					$actualKnife = $knifes[0];
					if ($selectedKnife != null)
					{
						foreach ($knifes as $knife) {
							if ($selectedKnife[0]['knife'] == $knife['weapon_name']) {
								$actualKnife = $knife;
								break;
							}
						}
					}

					echo "<div class='card-header'>";
					echo "<h6 class='card-title item-name'>Knife type</h6>";
					echo "<h5 class='card-title item-name'>{$actualKnife["paint_name"]}</h5>";
					echo "</div>";
					echo "<img src='{$actualKnife["image_url"]}' class='skin-image'>";
					?>
				</div>
				<div class="card-footer">
					<form action="" method="POST">
						<select name="forma" class="form-control select" onchange="this.form.submit()" class="SelectWeapon">
							<option disabled>Select knife</option>
							<?php
							foreach ($knifes as $knifeKey => $knife) {
								if ($selectedKnife[0]['knife'] == $knife['weapon_name'])
									echo "<option selected value=\"knife-{$knifeKey}\">{$knife['paint_name']}</option>";
								else
									echo "<option value=\"knife-{$knifeKey}\">{$knife['paint_name']}</option>";
							}
							?>
					</select>
				</form>
				<?php if ($equippedKnifeDefindex && isset($selectedSkins[$equippedKnifeDefindex])) : ?>
					<button type="button" class="btn btn-primary mt-2 item-settings-open" data-toggle="modal" data-target="#itemSettingsModal"
						data-defindex="<?php echo $equippedKnifeDefindex ?>"
						data-title="<?php echo htmlspecialchars($actualKnife['paint_name']) ?> settings"
						data-wear="<?php echo (float) $selectedSkins[$equippedKnifeDefindex]['weapon_wear'] ?>"
						data-seed="<?php echo (int) $selectedSkins[$equippedKnifeDefindex]['weapon_seed'] ?>">Settings</button>
				<?php else : ?>
					<button type="button" class="btn btn-primary mt-2" onclick="alert('Pick a knife type, then give that knife a skin, to change its wear and pattern.')">Settings</button>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php
	?>
		<div class="col-sm-2">
			<div class="card text-center mb-3">
				<div class="card-body">
					<div class="card-header">
						<h6 class="card-title item-name">Gloves</h6>
						<h5 class="card-title item-name"><?php echo htmlspecialchars($selectedGloveName) ?></h5>
					</div>
					<?php if ($selectedGloveImage !== '') : ?>
						<img src="<?php echo htmlspecialchars($selectedGloveImage) ?>" class="skin-image" alt="">
					<?php endif; ?>
				</div>
				<div class="card-footer">
					<form action="" method="POST">
						<input type="hidden" name="csrf" value="<?php echo csrf_token() ?>">
						<select name="gloves" class="form-control select" onchange="this.form.submit()" aria-label="Gloves">
							<?php foreach ($gloves as $glove) :
								$value = (int) $glove['weapon_defindex'] . '|' . (int) $glove['paint']; ?>
								<option value="<?php echo $value ?>" <?php echo $value === $selectedGlove ? 'selected' : '' ?>><?php echo htmlspecialchars($glove['paint_name']) ?></option>
							<?php endforeach; ?>
					</select>
				</form>
				<?php if ($gloveDefindex && isset($selectedSkins[$gloveDefindex])) : ?>
					<button type="button" class="btn btn-primary mt-2 item-settings-open" data-toggle="modal" data-target="#itemSettingsModal"
						data-defindex="<?php echo $gloveDefindex ?>"
						data-title="<?php echo htmlspecialchars($selectedGloveName) ?> settings"
						data-wear="<?php echo (float) $selectedSkins[$gloveDefindex]['weapon_wear'] ?>"
						data-seed="<?php echo (int) $selectedSkins[$gloveDefindex]['weapon_seed'] ?>">Settings</button>
				<?php else : ?>
					<button type="button" class="btn btn-primary mt-2" onclick="alert('Pick gloves first.')">Settings</button>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	foreach ($weapons as $defindex => $default) { ?>
			<div class="col-sm-2">
				<div class="card text-center mb-3">
					<div class="card-body">
						<?php
						if (array_key_exists($defindex, $selectedSkins)) {
							echo "<div class='card-header'>";
							echo "<h5 class='card-title item-name'>{$skins[$defindex][$selectedSkins[$defindex]['weapon_paint_id']]["paint_name"]}</h5>";
							echo "</div>";
							echo "<img src='{$skins[$defindex][$selectedSkins[$defindex]['weapon_paint_id']]['image_url']}' class='skin-image'>";
						} else {
							echo "<div class='card-header'>";
							echo "<h5 class='card-title item-name'>{$default["paint_name"]}</h5>";
							echo "</div>";
							echo "<img src='{$default["image_url"]}' class='skin-image'>";
						}
						?>
					</div>
					<div class="card-footer">
						<form action="" method="POST">
							<select name="forma" class="form-control select" onchange="this.form.submit()" class="SelectWeapon">
								<option disabled>Select skin</option>
								<?php
								foreach ($skins[$defindex] as $paintKey => $paint) {
									if (array_key_exists($defindex, $selectedSkins) && $selectedSkins[$defindex]['weapon_paint_id'] == $paintKey)
										echo "<option selected value=\"{$defindex}-{$paintKey}\">{$paint['paint_name']}</option>";
									else
										echo "<option value=\"{$defindex}-{$paintKey}\">{$paint['paint_name']}</option>";
								}
								?>
							</select>
							<br></br>
							<?php
							$selectedSkinInfo = isset($selectedSkins[$defindex]) ? $selectedSkins[$defindex] : null;
							$steamid = $_SESSION['steamid'];

							if ($selectedSkinInfo) :
							?>
								<button type="button" class="btn btn-outline-primary sticker-open" data-defindex="<?php echo $defindex ?>">Stickers</button>
									<button type="button" class="btn btn-primary" data-toggle="modal" data-target="#weaponModal<?php echo $defindex ?>">
									Settings
								</button>
							<?php else : ?>
								<button type="button" class="btn btn-primary" onclick="showSkinSelectionAlert()">
									Settings
								</button>
								<script>
									function showSkinSelectionAlert() {
										alert("You need to select a skin first.");
									}
								</script>
							<?php endif; ?>

					</div>

					<?php
					// wear value 
					$selectedSkinInfo = isset($selectedSkins[$defindex]['weapon_paint_id']) ? $selectedSkins[$defindex] : null;
					$queryWear = $selectedSkins[$defindex]['weapon_wear'] ?? 1.0;
					$initialWearValue = isset($selectedSkinInfo['weapon_wear']) ? $selectedSkinInfo['weapon_wear'] : (isset($queryWear[0]['weapon_wear']) ? $queryWear[0] : 0.0);

					// seed value 
					$querySeed = $selectedSkins[$defindex]['weapon_seed'] ?? 0;
					$initialSeedValue = isset($selectedSkinInfo['weapon_seed']) ? $selectedSkinInfo['weapon_seed'] : 0;
					?>


					<div class="modal fade" id="weaponModal<?php echo $defindex ?>" tabindex="-1" role="dialog" aria-labelledby="weaponModalLabel<?php echo $defindex ?>" aria-hidden="true">
						<div class="modal-dialog" role="document">
							<div class="modal-content">
								<div class="modal-header">
									<h5 class='card-title item-name'>
										<?php
										if (array_key_exists($defindex, $selectedSkins)) {
											echo "{$skins[$defindex][$selectedSkins[$defindex]['weapon_paint_id']]["paint_name"]} Settings";
										} else {
											echo "{$default["paint_name"]} Settings";
										}
										?>
									</h5>
								</div>
								<div class="modal-body">
									<div class="form-group">
										<select class="form-select" id="wearSelect<?php echo $defindex ?>" name="wearSelect" onchange="updateWearValue<?php echo $defindex ?>(this.value)">
											<option disabled>Select Wear</option>
											<option value="0.00" <?php echo ($initialWearValue == 0.00) ? 'selected' : ''; ?>>Factory New</option>
											<option value="0.07" <?php echo ($initialWearValue == 0.07) ? 'selected' : ''; ?>>Minimal Wear</option>
											<option value="0.15" <?php echo ($initialWearValue == 0.15) ? 'selected' : ''; ?>>Field-Tested</option>
											<option value="0.38" <?php echo ($initialWearValue == 0.38) ? 'selected' : ''; ?>>Well-Worn</option>
											<option value="0.45" <?php echo ($initialWearValue == 0.45) ? 'selected' : ''; ?>>Battle-Scarred</option>
										</select>

									</div>
									<div class="row">
										<div class="col-md-6">
											<div class="form-group">
												<label for="wear">Wear:</label>
												<input type="text" value="<?php echo $initialWearValue; ?>" class="form-control" id="wear<?php echo $defindex ?>" name="wear">
											</div>
										</div>
										<div class="col-md-6">
											<div class="form-group">
												<label for="seed">Seed:</label>
												<input type="text" value="<?php echo $initialSeedValue; ?>" class="form-control" id="seed<?php echo $defindex ?>" name="seed" oninput="validateSeed(this)">
											</div>
										</div>
									</div>
									<div class="form-check form-switch mt-3">
										<input class="form-check-input" type="checkbox" role="switch" id="stattrak<?php echo $defindex ?>" name="stattrak" value="1" <?php echo !empty($selectedSkins[$defindex]['weapon_stattrak']) ? 'checked' : ''; ?>>
										<label class="form-check-label" for="stattrak<?php echo $defindex ?>">StatTrak&trade;</label>
									</div>
								</div>
								<div class="modal-footer">
									<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
									<button type="submit" class="btn btn-danger">Use</button>
									</form>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<script>
				//  wear
				function updateWearValue<?php echo $defindex ?>(selectedValue) {
					var wearInputElement = document.getElementById("wear<?php echo $defindex ?>");
					wearInputElement.value = selectedValue;
				}

				function validateWear(inputElement) {
					inputElement.value = inputElement.value.replace(/[^0-9]/g, '');
				}
				// seed
				function validateSeed(input) {
					// Check entered value
					var inputValue = input.value.replace(/[^0-9]/g, ''); // Just get the numbers

					if (inputValue === "") {
						input.value = 0; // Set to 0 if empty or no numbers
					} else {
						var numericValue = parseInt(inputValue);
						numericValue = Math.min(1000, Math.max(1, numericValue)); // Interval control

						input.value = numericValue;
					}
				}
			</script>
		<?php } ?>
	<?php } ?>
	</div>
	</div>
	<div class="container">
		<footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
			<div class="col-md-4 d-flex align-items-center">
				<span class="mb-3 mb-md-0 text-body-secondary">© 2023 <a href="https://github.com/Nereziel/cs2-WeaponPaints">Nereziel/cs2-WeaponPaints</a></span>
			</div>
		</footer>
	</div>
<?php if (isset($_SESSION['steamid'])) : ?>
<div class="modal fade" id="itemSettingsModal" tabindex="-1" role="dialog" aria-labelledby="itemSettingsTitle" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<form class="modal-content" method="POST" action="">
			<input type="hidden" name="csrf" value="<?php echo csrf_token() ?>">
			<input type="hidden" name="item_settings" value="1">
			<input type="hidden" name="item_defindex" id="itemDefindex" value="">
			<div class="modal-header"><h5 class="card-title item-name" id="itemSettingsTitle">Settings</h5></div>
			<div class="modal-body">
				<div class="form-group">
					<select class="form-select" id="itemWearPreset" aria-label="Wear preset">
						<option value="">Wear preset</option>
						<option value="0.00">Factory New</option>
						<option value="0.07">Minimal Wear</option>
						<option value="0.15">Field-Tested</option>
						<option value="0.38">Well-Worn</option>
						<option value="0.45">Battle-Scarred</option>
					</select>
				</div>
				<div class="row">
					<div class="col-md-6">
						<label for="itemWear">Wear (0 to 1):</label>
						<input type="number" min="0" max="1" step="0.0001" class="form-control" id="itemWear" name="wear" required>
					</div>
					<div class="col-md-6">
						<label for="itemSeed">Pattern index (0 to 1000):</label>
						<input type="number" min="0" max="1000" step="1" class="form-control" id="itemSeed" name="seed" required>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
				<button type="submit" class="btn btn-danger">Use</button>
			</div>
		</form>
	</div>
</div>
<script>
	// Fills the shared knife/gloves settings dialog from the button that opened it.
	document.querySelectorAll('.item-settings-open').forEach(function (button) {
		button.addEventListener('click', function () {
			document.getElementById('itemSettingsTitle').textContent = button.dataset.title;
			document.getElementById('itemDefindex').value = button.dataset.defindex;
			document.getElementById('itemWear').value = button.dataset.wear;
			document.getElementById('itemSeed').value = button.dataset.seed;
			document.getElementById('itemWearPreset').value = '';
		});
	});
	document.getElementById('itemWearPreset').addEventListener('change', function () {
		if (this.value !== '') document.getElementById('itemWear').value = this.value;
	});
</script>
<div id="sticker-modal" class="sticker-modal" hidden>
		<div class="sticker-dialog" role="dialog" aria-labelledby="sticker-title">
			<div class="sticker-head">
				<h5 id="sticker-title">Stickers</h5>
				<button type="button" id="sticker-close" class="btn btn-secondary">Close</button>
			</div>
			<div id="sticker-slots" class="sticker-slots"></div>
			<div id="sticker-editor"></div>
			<div class="sticker-foot">
				<button type="button" id="sticker-clear-all" class="btn btn-secondary">Clear all</button>
				<span id="sticker-msg" class="muted"></span>
				<button type="button" id="sticker-save" class="btn btn-danger">Save</button>
			</div>
		</div>
	</div>
	<script>
		window.WP_STICKER_CONFIG = {
				team: <?php echo (int) $team ?>,
			token: <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
			stickers: <?php echo json_encode($stickersByWeapon ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
		};
	</script>
	<script src="stickers.js?v=<?php echo substr(md5_file(__DIR__ . '/stickers.js'), 0, 8) ?>"></script>
	<?php endif; ?>
	<script>
		// Filters the weapon tiles by name and shows how many match.
		(function () {
			const search = document.getElementById('skin-search');
			if (!search) return;
			const count = document.getElementById('skin-count');
			const tiles = Array.from(document.querySelectorAll('#skin-grid > .col-sm-2'));
			function filter() {
				const q = search.value.trim().toLowerCase();
				let shown = 0;
				for (const tile of tiles) {
					const match = q === '' || tile.textContent.toLowerCase().includes(q);
					tile.hidden = !match;
					if (match) shown++;
				}
				count.textContent = shown + (shown === 1 ? ' weapon' : ' weapons') + (q ? ' match' : ' available');
			}
			search.addEventListener('input', filter);
			filter();
		})();
	</script>
</body>
</html>
