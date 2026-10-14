<?php
declare(strict_types=1);

namespace NhanAZ\libRegRsp;

use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use WeakMap;
use ZipArchive;
use function array_unshift;
use function count;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_writable;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;
use function bin2hex;
use function random_bytes;
use function rename;
use function basename;
use function strpbrk;
use function preg_match;
use const DIRECTORY_SEPARATOR;

final class libRegRsp {
    /** @var WeakMap<PluginBase, array<string, true>>|null */
    private static ?WeakMap $registeredPaths = null;

    /**
     * Build a resource pack ZIP from plugin resources.
     * @param string|null $packFolder Folder under resources/ (default = plugin name)
     * @param string|null $zipFileName Override output name (default = plugin name)
     * @return string|null Path to the built mcpack or null on failure (plugin gets disabled on fatal issues)
     */
    public static function compile(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null): ?string {
        $packFolder ??= $plugin->getName();
        $zipFileName ??= $plugin->getName();
        if ($zipFileName === '' || $zipFileName === '.' || $zipFileName === '..' || basename($zipFileName) !== $zipFileName || strpbrk($zipFileName, '<>:"/\\|?*') !== false || preg_match('/[\x00-\x1f]/', $zipFileName) === 1) {
            $plugin->getLogger()->critical('[libRegRsp] Invalid resource pack output name');
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }
        $dataFolder = rtrim($plugin->getDataFolder(), "/\\") . DIRECTORY_SEPARATOR;

        if (!is_dir($dataFolder)) {
            if (!@mkdir($dataFolder, 0755, true) && !is_dir($dataFolder)) {
                $plugin->getLogger()->critical("[libRegRsp] Failed to create data folder: {$dataFolder}");
                $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
                return null;
            }
        }
        if (!is_writable($dataFolder)) {
            $plugin->getLogger()->critical("[libRegRsp] Data folder not writable: {$dataFolder}");
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }

        $zipPath = $dataFolder . $zipFileName . '.mcpack';
        $temporaryPath = $zipPath . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $zip = new ZipArchive();
        $openResult = $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            $plugin->getLogger()->critical("[libRegRsp] ZipArchive::open() failed (code {$openResult}) on {$temporaryPath}");
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }

        $entries = [];
        $prefixes = [rtrim($packFolder, '/\\') . '/'];
        // Fallback for older layout "<PluginName> Pack" when no custom folder is given
        if ($packFolder === $plugin->getName()) {
            $prefixes[] = rtrim($plugin->getName() . ' Pack', '/\\') . '/';
        }

        foreach ($plugin->getResources() as $resourceKey => $resource) {
            $resourceKey = (string)$resourceKey;

            $matchedPrefix = null;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($resourceKey, $prefix)) {
                    $matchedPrefix = $prefix;
                    break;
                }
            }
            if ($matchedPrefix === null) {
                continue;
            }

            $inPackPath = substr($resourceKey, strlen($matchedPrefix));
            $content = file_get_contents($resource->getPathname());
            if ($content === false) {
                $zip->close();
                @unlink($temporaryPath);
                $plugin->getLogger()->critical("[libRegRsp] Could not read resource: {$resourceKey}");
                $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
                return null;
            }
            if (!$zip->addFromString($inPackPath, $content)) {
                $zip->close();
                @unlink($temporaryPath);
                $plugin->getLogger()->critical("[libRegRsp] Could not add resource to pack: {$resourceKey}");
                $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
                return null;
            }
            $entries[] = $inPackPath;
        }

        if (!$zip->close() || count($entries) === 0) {
            @unlink($temporaryPath);
            $plugin->getLogger()->critical('[libRegRsp] Resource pack build failed or contained no resources');
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }

        try {
            new ZippedResourcePack($temporaryPath);
        } catch (\Throwable $error) {
            @unlink($temporaryPath);
            $plugin->getLogger()->critical('[libRegRsp] Invalid resource pack: ' . $error->getMessage());
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }

        $backupPath = null;
        if (is_file($zipPath)) {
            $backupPath = $zipPath . '.' . bin2hex(random_bytes(8)) . '.bak';
            if (!@rename($zipPath, $backupPath)) {
                @unlink($temporaryPath);
                $plugin->getLogger()->critical('[libRegRsp] Could not preserve the previous resource pack');
                $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
                return null;
            }
        }
        if (!@rename($temporaryPath, $zipPath)) {
            if ($backupPath !== null) {
                @rename($backupPath, $zipPath);
            }
            @unlink($temporaryPath);
            $plugin->getLogger()->critical('[libRegRsp] Could not publish the new resource pack');
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return null;
        }
        if ($backupPath !== null) {
            @unlink($backupPath);
        }

        $prettyPath = str_replace('\\', '/', $zipPath);
        $plugin->getLogger()->debug("[libRegRsp] ZIP entries (" . count($entries) . "):");
        foreach ($entries as $entry) {
            $plugin->getLogger()->debug("[libRegRsp] \u{2192} " . $entry);
        }
        $plugin->getLogger()->debug("[libRegRsp] Pack built \u{2192} " . $prettyPath);

        return $zipPath;
    }

    /** Register the pack on top of the stack (public API only). */
    public static function register(PluginBase $plugin, ?string $zipPath = null, bool $forceRequired = true): void {
        if (!self::assertForceRequiredConfigCompatible($plugin, $forceRequired)) {
            return;
        }
        $zipPath ??= self::defaultZipPath($plugin);
        try {
            $pack = new ZippedResourcePack($zipPath);
        } catch (\Throwable $e) {
            $plugin->getLogger()->error("[libRegRsp] Failed to load pack: " . $e->getMessage());
            return;
        }

        $manager = $plugin->getServer()->getResourcePackManager();
        $stack = $manager->getResourceStack();

        foreach ($stack as $key => $existing) {
            if ($existing instanceof ZippedResourcePack &&
                ($existing->getPackId() === $pack->getPackId() || $existing->getPath() === $pack->getPath())) {
                unset($stack[$key]); // remove duplicates so we can reinsert on top
            }
        }

        array_unshift($stack, $pack);
        $manager->setResourceStack(array_values($stack));
        if (method_exists($manager, 'setResourcePacksRequired')) {
            $manager->setResourcePacksRequired($forceRequired);
        }
        self::$registeredPaths ??= new WeakMap();
        $registered = self::$registeredPaths[$plugin] ?? [];
        $registered[$zipPath] = true;
        self::$registeredPaths[$plugin] = $registered;

        $plugin->getLogger()->debug("[libRegRsp] Registered pack UUID=" . $pack->getPackId());
    }

    /** Remove our pack and delete the ZIP. */
    public static function unregister(PluginBase $plugin, ?string $zipPath = null): void {
        $zipPath ??= self::defaultZipPath($plugin);
        $registered = self::$registeredPaths[$plugin] ?? [];
        if (!isset($registered[$zipPath])) {
            return;
        }
        $manager = $plugin->getServer()->getResourcePackManager();
        $stack = $manager->getResourceStack();

        foreach ($stack as $key => $pack) {
            if ($pack instanceof ZippedResourcePack && $pack->getPath() === $zipPath) {
                unset($stack[$key]);
                break;
            }
        }

        $manager->setResourceStack(array_values($stack));
        @unlink($zipPath);
        unset($registered[$zipPath]);
        self::$registeredPaths[$plugin] = $registered;
        $plugin->getLogger()->debug("[libRegRsp] Unregistered pack.");
    }

    /** Convenience: build + register in one call. */
    public static function compileAndRegister(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null, bool $forceRequired = true): void {
        if (!self::assertForceRequiredConfigCompatible($plugin, $forceRequired)) {
            return;
        }
        $zipPath = self::compile($plugin, $packFolder, $zipFileName);
        if ($zipPath === null) {
            return;
        }
        self::register($plugin, $zipPath, $forceRequired);
    }

    /** Disable the plugin before compiling or changing the stack if this API cannot apply the requested setting. */
    private static function assertForceRequiredConfigCompatible(PluginBase $plugin, bool $forceRequired): bool {
        $manager = $plugin->getServer()->getResourcePackManager();
        if (!method_exists($manager, 'setResourcePacksRequired') && $manager->resourcePacksRequired() !== $forceRequired) {
            $expected = $forceRequired ? 'true' : 'false';
            $plugin->getLogger()->error(
                '[libRegRsp] This Axolotl-PM API cannot change force_resources after startup. ' .
                "Set force_resources: $expected in resource_packs/resource_packs.yml before starting the server."
            );
            $plugin->getServer()->getPluginManager()->disablePlugin($plugin);
            return false;
        }
        return true;
    }

    private static function defaultZipPath(PluginBase $plugin): string {
        return rtrim($plugin->getDataFolder(), "/\\") . DIRECTORY_SEPARATOR . $plugin->getName() . '.mcpack';
    }
}
