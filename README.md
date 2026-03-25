# libRegRsp

Resource-pack compile & register helper for PocketMine-MP (virion or composer library).

## Features
- Build `.mcpack` directly from your plugin `resources/`.
- Supports both layouts: default `<PluginName>/` and legacy `"<PluginName> Pack"/`.
- Registers the pack at the top of the stack and removes duplicates (same UUID/path) first.
- Optional `forceRequired` to require players to accept the pack.

## Requirements
- PocketMine-MP API 5.x
- PHP 8.1+

## Installation
### Composer (self-hosted plugin)
```bash
composer require nhanaz/libregrsp
```

### Poggit (virion)
Add to `.poggit.yml`:
```yml
projects:
  YourPlugin:
    libs:
      - src: NhanAZ/libRegRsp/libRegRsp
        version: ^1.0.4
```

## Quick start
```php
use NhanAZ\libRegRsp\libRegRsp;

class MyPlugin extends PluginBase {
    protected function onEnable() : void{
        // Resources under resources/MyPlugin/ or resources/MyPlugin Pack/
        libRegRsp::compileAndRegister($this);
    }

    protected function onDisable() : void{
        libRegRsp::unregister($this); // removes the .mcpack
    }
}
```

### API reference (concise)
- Build only: `libRegRsp::compile($plugin, ?string $packFolder = null, ?string $zipFileName = null): ?string`
- Register only: `libRegRsp::register($plugin, ?string $zipPath = null, bool $forceRequired = true): void`
- Unregister + delete: `libRegRsp::unregister($plugin, ?string $zipPath = null): void`

`$packFolder` defaults to plugin name; legacy `"<PluginName> Pack"/` is auto-detected when no custom folder is provided.

## Troubleshooting
- **Cannot create data folder:** check write permissions of `plugins/YourPlugin/`.
- **Empty zip:** ensure assets are in `resources/<PluginName>/...` or `resources/<PluginName> Pack/`.
- **Duplicate UUID:** library already evicts duplicates before insert; if it persists, other packs likely share the same UUID.

## License & Contact
- LGPL-3.0-or-later (see `LICENSE`)
- Discord: [NhanAZ #9115](https://discord.gg/j2X83ujT6c)
