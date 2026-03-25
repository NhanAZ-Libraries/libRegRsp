<?php
declare(strict_types=1);

namespace NhanAZ\libRegRsp;

use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use ZipArchive;
use function array_unshift;
use function count;
use function file_get_contents;
use function is_dir;
use function is_writable;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;
use const DIRECTORY_SEPARATOR;

final class libRegRsp {
    /**
     * Build a resource pack ZIP from plugin resources.
     * @param string|null $packFolder Folder under resources/ (default = plugin name)
     * @param string|null $zipFileName Override output name (default = plugin name)
     * @return string|null Path to the built mcpack or null on failure (plugin gets disabled on fatal issues)
     */
    public static function compile(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null): ?string {
        $packFolder ??= $plugin->getName();
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

        $zipPath = $dataFolder . ($zipFileName ?? $plugin->getName()) . '.mcpack';
        @unlink($zipPath);

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            $plugin->getLogger()->critical("[libRegRsp] ZipArchive::open() failed (code {$openResult}) on {$zipPath}");
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
                $plugin->getLogger()->warning("[libRegRsp] Could not read: {$resourceKey}");
                continue;
            }
            $zip->addFromString($inPackPath, $content);
            $entries[] = $inPackPath;
        }

        $zip->close();

        $prettyPath = str_replace('\\', '/', $zipPath);
        if (count($entries) === 0) {
            $plugin->getLogger()->warning("[libRegRsp] No resources found under prefixes: " . implode(', ', $prefixes));
        } else {
            $plugin->getLogger()->debug("[libRegRsp] ZIP entries (" . count($entries) . "):");
            foreach ($entries as $entry) {
                $plugin->getLogger()->debug("[libRegRsp] \u{2192} " . $entry);
            }
        }
        $plugin->getLogger()->debug("[libRegRsp] Pack built \u{2192} " . $prettyPath);

        return $zipPath;
    }

    /** Register the pack on top of the stack (public API only). */
    public static function register(PluginBase $plugin, ?string $zipPath = null, bool $forceRequired = true): void {
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
        $manager->setResourcePacksRequired($forceRequired);

        $plugin->getLogger()->debug("[libRegRsp] Registered pack UUID=" . $pack->getPackId());
    }

    /** Remove our pack and delete the ZIP. */
    public static function unregister(PluginBase $plugin, ?string $zipPath = null): void {
        $zipPath ??= self::defaultZipPath($plugin);
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
        $plugin->getLogger()->debug("[libRegRsp] Unregistered pack.");
    }

    /** Convenience: build + register in one call. */
    public static function compileAndRegister(PluginBase $plugin, ?string $packFolder = null, ?string $zipFileName = null, bool $forceRequired = true): void {
        $zipPath = self::compile($plugin, $packFolder, $zipFileName);
        if ($zipPath === null) {
            return;
        }
        self::register($plugin, $zipPath, $forceRequired);
    }

    private static function defaultZipPath(PluginBase $plugin): string {
        return rtrim($plugin->getDataFolder(), "/\\") . DIRECTORY_SEPARATOR . $plugin->getName() . '.mcpack';
    }
}
