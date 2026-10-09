# libRegRsp

libRegRsp compiles a plugin's bundled resources into a Bedrock resource pack and registers it with Axolotl-PM. Version 1.0.5 declares plugin API 5.0.0 and requires PHP 8.1 or later. Broader Axolotl-PM 5.x compatibility remains unverified.

## Overview

The library reads assets from `resources/<PluginName>/` or the legacy `resources/<PluginName> Pack/` layout. It creates a `.mcpack` file in the plugin data directory and places the pack at the top of the server resource stack. A custom folder and output name are supported.

- Compiles and validates a pack before replacing any existing pack file.
- Preserves an existing pack if the new build fails.
- Removes the current process's registered pack on `unregister()`.
- Can require players to accept the pack through `forceRequired`.

## Requirements

- Axolotl-PM with plugin API 5.0.0. The exact development revision is recorded in `composer.lock` and the [CI workflow](.github/workflows/ci.yml).
- PHP 8.1 or later with the `zip` extension.
- A valid Bedrock resource pack manifest in the selected resource folder.
- [DevTools v1.0.1](https://github.com/NhanAZ/DevTools/releases/tag/v1.0.1) for building a consumer PHAR.

## Installation

Place a checkout of this virion at `virions/libRegRsp` next to your plugin project. Add a compatible declaration to its `devtools.yml`.

```yaml
virions:
  - name: libRegRsp
    version: ^1.0.5
```

Build a standalone consumer PHAR with DevTools after copying the library's `virion.yml`, `src/` and `LICENSE` into that directory. The PHP source is shaded into the consumer PHAR. The consumer must include its own resource pack files. A Composer consumer can depend on `nhanaz/libregrsp` when it supplies the Axolotl-PM API and resource files.

## Usage

Store pack files under `resources/MyPlugin/`, including `manifest.json` at the folder root. Register the pack when the plugin starts and remove it when the plugin stops.

```php
use NhanAZ\libRegRsp\libRegRsp;
use pocketmine\plugin\PluginBase;

final class MyPlugin extends PluginBase{
    protected function onEnable() : void{
        libRegRsp::compileAndRegister($this);
    }

    protected function onDisable() : void{
        libRegRsp::unregister($this);
    }
}
```

For a different resource folder, pass it as the second argument to `compileAndRegister()`. The third argument sets the output name without an extension. The output name must be a safe single file name. The fourth argument controls whether players must accept the resource pack, and defaults to `true`.

## API

| Method | Result |
|---|---|
| `compile(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null): ?string` | Returns the built pack path or `null` on failure. |
| `register(PluginBase $plugin, ?string $zipPath = null, bool $forceRequired = true): void` | Adds a valid pack to the server stack. |
| `unregister(PluginBase $plugin, ?string $zipPath = null): void` | Removes a pack registered by the current process and deletes its generated file. |
| `compileAndRegister(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null, bool $forceRequired = true): void` | Compiles and registers a pack. |

`compile()` disables the owning plugin if the data directory is unusable, the output name is unsafe, a resource cannot be read, or the generated pack is invalid. If a previous pack file exists, a failed replacement keeps it for recovery. `register()` sets Axolotl-PM's server-wide resource pack requirement to the supplied `forceRequired` value. Coordinate that setting with other plugins that manage resource packs.

## Development and testing

Run `composer install --no-scripts --no-plugins`, `composer validate --strict`, `composer phpstan` and `php -l src/NhanAZ/libRegRsp/libRegRsp.php`. CI runs the same checks against locked Axolotl-PM development dependencies. DevTools builds a consumer PHAR. A clean server startup and pack lifecycle test verifies packaging. A Bedrock client is still needed to verify its presentation.

For migration from 1.0.4, keep a copy of the previous consumer PHAR and pack files, upgrade the virion declaration to `^1.0.5`, then rebuild the consumer. If startup or pack loading fails, restore the previous PHAR and restart the server. Existing API method names and resource folder layouts remain supported.

## License, credits and support

The PHP library is licensed under LGPL-3.0-or-later. See [LICENSE](LICENSE). Existing attribution to NhanAZ and jasonwynn10 is preserved in `virion.yml`. For support, use [NhanAZ Discord](https://discord.gg/j2X83ujT6c) or open an issue in this repository.
