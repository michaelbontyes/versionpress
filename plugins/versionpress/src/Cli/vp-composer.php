<?php
// NOTE: VersionPress must be fully activated for these commands to be available

// WORD-WRAPPING of the doc comments: 75 chars for option description, 90 chars for everything else,
// see http://wp-cli.org/docs/commands-cookbook/#longdesc.
// In this source file, wrap long desc at col 97 and option desc at col 84.

namespace VersionPress\Cli;

use VersionPress\ChangeInfos\PluginChangeInfo;
use VersionPress\DI\VersionPressServices;
use VersionPress\Git\Committer;
use VersionPress\Utils\Process;
use VersionPress\VersionPress;
use WP_CLI;
use WP_CLI_Command;

/**
 * VersionPress CLI commands for Composer scripts.
 */
class VPComposerCommand extends WP_CLI_Command
{
    /**
     * Commits all changes made by Composer.
     *
     * @subcommand commit-composer-changes
     */
    public function commitComposerChanges($args, $assoc_args)
    {
        global $versionPressContainer;

        if (!VersionPress::isActive()) {
            WP_CLI::error('VersionPress is not active. Changes will be not committed.');
        }

        /** @var Committer $committer */
        $committer = $versionPressContainer->resolve(VersionPressServices::COMMITTER);
        $changes = $this->detectChanges();
        $installedPackages = $changes['installed'];
        $removedPackages = $changes['removed'];
        $updatedPackages = $changes['updated'];

        $changeInfos = array_merge(
            array_map(function ($package) {
                if ($package['type'] === 'wordpress-plugin') {
                    return new PluginChangeInfo($package['name'], 'install', $package['name']);
                }

                return null;

            }, $installedPackages),
            array_map(function ($package) {
                if ($package['type'] === 'wordpress-plugin') {
                    return new PluginChangeInfo($package['name'], 'delete', $package['name']);
                }

                return null;

            }, $removedPackages),
            array_map(function ($package) {
                if ($package['type'] === 'wordpress-plugin') {
                    return new PluginChangeInfo($package['name'], 'update', $package['name']);
                }

                return null;

            }, $updatedPackages)
        );

        var_dump($changeInfos);
    }

    private function detectChanges()
    {
        $currentComposerLock = file_get_contents(VP_PROJECT_ROOT . '/composer.lock');

        $process = new Process(VP_GIT_BINARY . ' show HEAD:composer.lock', VP_PROJECT_ROOT);
        $process->run();

        $previousComposerLock = $process->getOutput();

        $currentPackages = $this->getPackagesFromLockFile($currentComposerLock);
        $previousPackages = $this->getPackagesFromLockFile($previousComposerLock);

        $installedPackages = array_diff_key($currentPackages, $previousPackages);
        $removedPackages = array_diff_key($previousPackages, $currentPackages);

        $packagesWithChangedVersion = array_filter(
            array_intersect_key($previousPackages, $currentPackages),
            function ($package) use ($currentPackages) {
                return $package['version'] !== $currentPackages[$package['name']]['version'];
            }
        );

        return [
            'installed' => $installedPackages,
            'removed' => $removedPackages,
            'updated' => $packagesWithChangedVersion,
        ];
    }

    private function getPackagesFromLockFile($lockFileContent)
    {
        $lockFile = json_decode($lockFileContent, true);
        return array_combine(
            array_column($lockFile['packages'], 'name'),
            array_map(function ($package) {
                return [
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'type' => $package['type'],
                    'homepage' => @$package['homepage'] ?: null,
                ];
            }, $lockFile['packages'])
        );
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('vp-composer', VPComposerCommand::class);
}