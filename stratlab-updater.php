<?php
/*
Plugin Name: StratLab Updater
Description: Centralized GitHub updater for StratLab plugins.
Version: 1.0.1
Author: StratLab Marketing
Author URI: https://strategylab.ca/
Text Domain: stratlab-updater
Requires at least: 6.0
Requires PHP: 7.0
Update URI: https://github.com/carterfromsl/stratlab-updater/
*/

class StratLabUpdater {
    private static $instance = null;
    private $registeredPlugins = [];
    private $selfUpdaterRepoUrl = 'https://api.github.com/repos/carterfromsl/stratlab-updater/releases/latest';
    private $pluginFile;

    private function __construct() {
        // Register action and filters
        add_action('stratlab_register_plugin', [$this, 'registerPlugin']);
        add_filter("pre_set_site_transient_update_plugins", [$this, "checkForUpdates"]);
        add_filter("plugins_api", [$this, "setPluginInfo"], 10, 3);
        add_filter("upgrader_post_install", [$this, "postInstall"], 10, 3);
        add_filter("pre_set_site_transient_update_plugins", [$this, "checkForSelfUpdate"]);
        add_filter("upgrader_post_install", [$this, "selfPostInstall"], 10, 3);

        // Enable auto-updates by default for this plugin
        add_filter("auto_update_plugin", [$this, "enableAutoUpdate"], 10, 2);

        // Set the plugin file for self-updating
        $this->pluginFile = plugin_basename(__FILE__);
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new StratLabUpdater();
        }
        return self::$instance;
    }

    public function registerPlugin($pluginData) {
        if (isset($pluginData['slug'], $pluginData['repo_url'], $pluginData['version'])) {
            $this->registeredPlugins[] = $pluginData;
        }
    }

    public function checkForUpdates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        foreach ($this->registeredPlugins as $plugin) {
            $repoInfo = $this->getRepositoryInfo($plugin['repo_url'], $plugin['access_token'] ?? '');
            if ($repoInfo && version_compare($plugin['version'], $repoInfo->tag_name, '<')) {
                $pluginSlug = $plugin['slug'];
                $transient->response[$pluginSlug] = (object)[
                    'slug' => $pluginSlug,
                    'new_version' => $repoInfo->tag_name,
                    'url' => $repoInfo->html_url,
                    'package' => $repoInfo->zipball_url,
                ];
            }
        }

        return $transient;
    }

    private function getRepositoryInfo($repoUrl, $accessToken = '') {
        $args = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress',
            ],
        ];

        if (!empty($accessToken)) {
            $args['headers']['Authorization'] = "token {$accessToken}";
        }

        $response = wp_remote_get($repoUrl, $args);
        if (is_wp_error($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    public function setPluginInfo($false, $action, $response) {
        foreach ($this->registeredPlugins as $plugin) {
            if (isset($response->slug) && $response->slug === $plugin['slug']) {
                $repoInfo = $this->getRepositoryInfo($plugin['repo_url'], $plugin['access_token'] ?? '');
                if ($repoInfo) {
                    $response->last_updated = $repoInfo->published_at;
                    $response->slug = $plugin['slug'];
                    $response->plugin_name = $plugin['name'] ?? '';
                    $response->version = $repoInfo->tag_name;
                    $response->author = $plugin['author'] ?? '';
                    $response->homepage = $plugin['homepage'] ?? '';
                    $response->sections = [
                        'description' => $plugin['description'] ?? '',
                        'changelog' => $repoInfo->body,
                    ];
                    $response->download_link = $repoInfo->zipball_url;
                }
            }
        }
        return $response;
    }

    public function postInstall($true, $hook_extra, $result) {
        global $wp_filesystem;
        foreach ($this->registeredPlugins as $plugin) {
            if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $plugin['slug']) {
                $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($plugin['slug']);
                $wp_filesystem->move($result['destination'], $pluginFolder);
                $result['destination'] = $pluginFolder;
                break;
            }
        }
        return $result;
    }

    public function checkForSelfUpdate($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $repoInfo = $this->getRepositoryInfo($this->selfUpdaterRepoUrl);
        if ($repoInfo && version_compare('1.0.0', $repoInfo->tag_name, '<')) {
            $transient->response[$this->pluginFile] = (object)[
                'slug' => $this->pluginFile,
                'new_version' => $repoInfo->tag_name,
                'url' => $repoInfo->html_url,
                'package' => $repoInfo->zipball_url,
            ];
        }

        return $transient;
    }

    public function selfPostInstall($true, $hook_extra, $result) {
        global $wp_filesystem;
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->pluginFile) {
            $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->pluginFile);
            $wp_filesystem->move($result['destination'], $pluginFolder);
            $result['destination'] = $pluginFolder;
        }
        return $result;
    }

    public function enableAutoUpdate($update, $item) {
        if (isset($item->slug) && $item->slug === $this->pluginFile) {
            return true;
        }
        return $update;
    }
}

StratLabUpdater::getInstance();
