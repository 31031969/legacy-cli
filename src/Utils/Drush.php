<?php

namespace CommerceGuys\Platform\Cli\Utils;

use CommerceGuys\Platform\Cli\Local\Toolstack\Drupal;

class Drush {

    /**
     * Create Drush aliases for the provided project and environments.
     *
     * @param array $project The project
     * @param string $projectRoot The project root
     * @param array $environments The environments
     * @param string $homeDir The user's home directory.
     * @param bool $merge Whether to merge existing alias settings.
     *
     * @throws \Exception
     *
     * @return bool Whether any aliases have been created.
     */
    public static function createAliases($project, $projectRoot, $environments, $homeDir = '~', $merge = true)
    {
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot . '/repository')) {
            return false;
        }

        $group = $project['id'];
        if (!empty($project['alias-group'])) {
            $group = $project['alias-group'];
        }

        // Ensure the existence of the .drush directory.
        $drushDir = $homeDir . '/.drush';
        if (!is_dir($drushDir)) {
            mkdir($drushDir);
        }

        $filename = $drushDir . '/' . $group . '.aliases.drushrc.php';
        if (!is_writable($filename)) {
            throw new \Exception("Drush alias file not writable: $filename");
        }

        // Include the alias file so that the user's own modifications can be
        // merged.
        $aliases = array();
        if (file_exists($filename) && $merge) {
            include $filename;
        }

        // Generate aliases for the remote environments.
        $numValidEnvironments = 0;
        $autoGenerated = '';
        foreach ($environments as $environment) {
            if (!isset($environment['_links']['ssh'])) {
                continue;
            }
            $sshUrl = parse_url($environment['_links']['ssh']['href']);
            if (!$sshUrl) {
                continue;
            }
            $newAlias = array(
              'uri' => $environment['_links']['public-url']['href'],
              'remote-host' => $sshUrl['host'],
              'remote-user' => $sshUrl['user'],
              'root' => '/app/public',
              'platformsh-cli-auto-remove' => true,
            );

            // If the alias already exists, recursively replace existing
            // settings with new ones.
            if (isset($aliases[$environment['id']])) {
                $newAlias = array_replace_recursive($aliases[$environment['id']], $newAlias);
                unset($aliases[$environment['id']]);
            }

            $autoGenerated .= "\n// Automatically generated alias for the environment \"" . $environment['title'] . "\".\n";
            $autoGenerated .= "\$aliases['" . $environment['id'] . "'] = " . var_export($newAlias, true) . ";\n";
            $numValidEnvironments++;
        }

        // Generate an alias for the local environment.
        $wwwRoot = $projectRoot . '/www';
        $localAlias = '';
        if (is_dir($wwwRoot)) {
            $local = array(
              'root' => $wwwRoot,
              'platformsh-cli-auto-remove' => true,
            );

            if (isset($aliases['_local'])) {
                $local = array_replace_recursive($aliases['_local'], $local);
                unset($aliases['_local']);
            }

            $localAlias .= "\n// Automatically generated alias for the local environment.\n"
              . "\$aliases['_local'] = " . var_export($local, true) . ";\n";
            $numValidEnvironments++;
        }

        // Add any user-defined (pre-existing) aliases.
        $userDefined = '';
        foreach ($aliases as $name => $alias) {
            if (!empty($alias['platformsh-cli-auto-remove'])) {
                // This is probably for a deleted Platform.sh environment.
                continue;
            }
            $userDefined .= "\$aliases['$name'] = " . var_export($alias, true) . ";\n\n";
        }
        if ($userDefined) {
            $userDefined = "\n// User-defined aliases.\n" . $userDefined;
        }

        $header = "<?php\n"
          . "/**\n * @file"
          . "\n * Drush aliases for the Platform.sh project \"{$project['name']}\"."
          . "\n *"
          . "\n * This file is auto-generated by the Platform.sh CLI."
          . "\n *"
          . "\n * WARNING"
          . "\n * This file may be regenerated at any time."
          . "\n * User-defined aliases and changes to the existing aliases will be preserved."
          . "\n * Other information may be deleted."
          . "\n */\n\n";

        $export = $header . $userDefined . $localAlias . $autoGenerated;

        if ($numValidEnvironments) {
            file_put_contents($filename, $export);
            return true;
        }

        return false;
    }

}
