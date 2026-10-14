# Changelog

## 1.0.6 - 2026-10-14

- Keep plugin API 5.0.0 support when the server-wide `force_resources` setting already matches the requested value.
- Use `setResourcePacksRequired()` when available and report the required startup configuration on older API builds that cannot change it at runtime.
- Check compatibility before compiling or changing the resource stack.

## 1.0.5 - 2026-10-10

- Target Axolotl-PM 5.49.1 with a locked PHPStan level max development gate.
- Validate a temporary resource pack before replacing an existing pack file.
- Keep the previous pack when compilation or publication fails.
- Reject unsafe output file names and incomplete resource archives.
- Make `unregister()` remove only a pack registered by the current process.
- Keep the existing API methods and resource folder layouts.

Rebuild consumer PHARs after updating their virion declaration to `^1.0.5`. Keep the previous PHAR and generated pack files for rollback, and restart the server after replacement.
