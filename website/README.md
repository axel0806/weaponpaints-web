# weaponpaints-web (website changes)

Skin, sticker and glove picker website for the [cs2-WeaponPaints](https://github.com/Nereziel/cs2-WeaponPaints) plugin, this fork changes the `website/` folder for a CS2 server run with Docker.

Changes from upstream:

- Separate T and CT loadouts (`?team=t` / `?team=ct`).
- Wear, seed and sticker editing for knives, gloves and weapons.
- StatTrak toggle per weapon.
- CSRF protection on forms; the database and Steam settings come from environment variables.
- PHP 8.3 Apache `Dockerfile`.

## License

GPL-3.0, the same as upstream (see [LICENSE](LICENSE)). This is a modified version of the upstream website, so it has to stay under GPL-3.0.

```bash
docker build -t weaponpaints-web website/
```
