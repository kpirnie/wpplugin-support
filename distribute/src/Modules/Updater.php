<?php

/**
 * Updater - GitHub Releases update checker
 *
 * Hooks into WordPress's plugin update system so releases pushed to the
 * GitHub repository come through the normal "Update now" flow.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.21
 */

declare(strict_types=1);

namespace KP\Support\Modules;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Updater')) {

    /**
     * Class Updater
     *
     * Checks the GitHub Releases API for a newer tag and injects the result
     * into WordPress's update pipeline.
     *
     * @since 1.0.21
     */
    class Updater extends AbstractModule
    {
        /**
         * The owner/repo we pull releases from.
         *
         * @since 1.0.21
         * @var string
         */
        private const GH_REPO = 'kpirnie/wpplugin-support';

        /**
         * Our plugin slug.
         *
         * @since 1.0.21
         * @var string
         */
        private const SLUG = 'kp-support';

        /**
         * Where the cached release data lives.
         *
         * @since 1.0.21
         * @var string
         */
        private const TRANSIENT = 'kpts_gh_update_check';

        /**
         * How long we hold a successful lookup, 12 hours.
         *
         * @since 1.0.21
         * @var int
         */
        private const CACHE_SECS = 43200;

        /**
         * How long we hold a failed lookup, 1 hour.
         *
         * @since 1.0.21
         * @var int
         */
        private const FAIL_SECS = 3600;

        /**
         * Where the cached readme lives.
         *
         * @since 1.0.47
         * @var string
         */
        private const README_TRANSIENT = 'kpts_gh_readme';

        /**
         * The raw content base for the repo.
         *
         * @since 1.0.47
         * @var string
         */
        private const RAW_BASE = 'https://raw.githubusercontent.com/' . self::GH_REPO . '/';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.21
         * @access public
         * @return void
         */
        public function register(): void
        {

            // wire ourselves into the update pipeline, on both the write and the read
            add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdate'));
            add_filter('site_transient_update_plugins', array($this, 'injectUpdate'));
            add_filter('plugins_api', array($this, 'pluginInfo'), 10, 3);
            add_action('upgrader_process_complete', array($this, 'purgeCache'), 10, 2);
            add_filter('upgrader_source_selection', array($this, 'fixSourceDir'), 10, 4);
        }

        /**
         * Pull the latest release from GitHub, cached either way.
         *
         * @since  1.0.21
         * @access private
         * @return object|false The release object, or false if we couldn't get one.
         */
        private function fetchRelease(): object|false
        {

            // hand back whatever we've already got, including a cached failure
            $cached = get_transient(self::TRANSIENT);
            if ($cached !== false) {
                return ($cached === 'fail') ? false : $cached;
            }

            // go ask GitHub
            $response = wp_remote_get(
                'https://api.github.com/repos/' . self::GH_REPO . '/releases/latest',
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                    ),
                )
            );

            // if that went sideways, remember it for a bit so we're not hammering them
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                set_transient(self::TRANSIENT, 'fail', self::FAIL_SECS);
                return false;
            }

            // decode it, and make sure it's actually a release
            $release = json_decode(wp_remote_retrieve_body($response));
            if (empty($release->tag_name)) {
                set_transient(self::TRANSIENT, 'fail', self::FAIL_SECS);
                return false;
            }

            // cache it and hand it back
            set_transient(self::TRANSIENT, $release, self::CACHE_SECS);

            return $release;
        }

        /**
         * Pull the readme.txt that shipped with a tag, cached either way.
         *
         * @since  1.0.47
         * @access private
         * @param  string $tag The release tag to read from.
         * @return string The raw readme, or an empty string if we couldn't get it.
         */
        private function fetchReadme(string $tag): string
        {

            // hand back whatever we've already got, so long as it's for this tag
            $cached = get_transient(self::README_TRANSIENT);
            if (is_array($cached) && ($cached['tag'] ?? '') === $tag) {
                return (string) ($cached['body'] ?? '');
            }

            // go grab it straight off the tag
            $response = wp_remote_get(
                self::RAW_BASE . $tag . '/source/readme.txt',
                array(
                    'timeout' => 10,
                    'headers' => array(
                        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                    ),
                )
            );

            // if that went sideways, remember it for a bit so we're not hammering them
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                set_transient(self::README_TRANSIENT, array('tag' => $tag, 'body' => ''), self::FAIL_SECS);
                return '';
            }

            // hold on to it and hand it back
            $body = (string) wp_remote_retrieve_body($response);
            set_transient(self::README_TRANSIENT, array('tag' => $tag, 'body' => $body), self::CACHE_SECS);

            return $body;
        }

        /**
         * Carve a single == Section == out of a readme.txt.
         *
         * @since  1.0.47
         * @access private
         * @param  string $readme The raw readme contents.
         * @param  string $name   The section name, without the equals signs.
         * @return string The section body, or an empty string if it isn't there.
         */
        private function readmeSection(string $readme, string $name): string
        {

            // nothing in, nothing out
            if (empty($readme)) {
                return '';
            }

            // normalize the line endings so our anchors behave
            $readme = str_replace("\r\n", "\n", $readme);

            // everything from our header to the next one, or the end of the file
            $pattern = '/^==\s*' . preg_quote($name, '/') . '\s*==\s*$(.*?)(?=^==\s|\z)/ms';

            // pull it out if it's actually in there
            if (! preg_match($pattern, $readme, $matches)) {
                return '';
            }

            return trim((string) $matches[1]);
        }

        /**
         * Find the release zip, falling back to the auto generated zipball.
         *
         * @since  1.0.21
         * @access private
         * @param  object $release The release object.
         * @return string The download url.
         */
        private function zipUrl(object $release): string
        {

            // walk the attached assets looking for our zip
            foreach ($release->assets ?? array() as $_asset) {
                if (str_ends_with((string) $_asset->name, '.zip')) {
                    return (string) $_asset->browser_download_url;
                }
            }

            // nothing attached, so the zipball it is
            return (string) ($release->zipball_url ?? '');
        }

        /**
         * Drop our update into the transient when there's a newer release out there.
         *
         * @since  1.0.21
         * @access public
         * @param  mixed $transient The current update_plugins transient value.
         * @return mixed The transient, modified or not.
         */
        public function injectUpdate(mixed $transient): mixed
        {

            // WordPress hands us an empty object early in the boot, skip it
            if (empty($transient->checked)) {
                return $transient;
            }

            // grab the release
            $release = $this->fetchRelease();
            if (! $release) {
                return $transient;
            }

            // tags come through as v1.0.21, we want 1.0.21
            $remote_version = ltrim((string) $release->tag_name, 'v');

            // build out what WordPress expects to see
            $update                = new \stdClass();
            $update->slug          = self::SLUG;
            $update->plugin        = KP_SUPPORT_BASENAME;
            $update->new_version   = $remote_version;
            $update->url           = 'https://github.com/' . self::GH_REPO;
            $update->package       = $this->zipUrl($release);
            $update->icons         = array(
                'svg' => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
                '1x'  => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
                '2x'  => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
            );
            $update->banners       = array();
            $update->banners_rtl   = array();
            $update->tested        = '7.1';
            $update->requires      = '6.8';
            $update->requires_php  = '8.2';
            $update->compatibility = new \stdClass();

            // tell WordPress what folder name to expect inside the zip
            $update->new_files = self::SLUG;

            // make sure the buckets are actually there
            if (! isset($transient->response)) {
                $transient->response = array();
            }
            if (! isset($transient->no_update)) {
                $transient->no_update = array();
            }

            // nothing newer out there, so drop any stale offer and list ourselves as current
            if (! version_compare($remote_version, KP_SUPPORT_VERSION, '>')) {
                unset($transient->response[KP_SUPPORT_BASENAME]);
                $update->new_version = KP_SUPPORT_VERSION;
                $transient->no_update[KP_SUPPORT_BASENAME] = $update;
                return $transient;
            }

            // there is, so in it goes
            unset($transient->no_update[KP_SUPPORT_BASENAME]);
            $transient->response[KP_SUPPORT_BASENAME] = $update;

            return $transient;
        }

        /**
         * Fill in the version details modal from the release data.
         *
         * @since  1.0.21
         * @access public
         * @param  false|object|array $result The default result.
         * @param  string             $action The api action being performed.
         * @param  object             $args   The request arguments.
         * @return false|object|array Our info, or whatever we were handed.
         */
        public function pluginInfo(false|object|array $result, string $action, object $args): false|object|array
        {

            // this is only ever about us
            if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
                return $result;
            }

            // grab the release
            $release = $this->fetchRelease();
            if (! $release) {
                return $result;
            }

            // the readme that shipped with this tag
            $readme = $this->fetchReadme((string) $release->tag_name);

            // the two sections we actually want
            $description = $this->markdownToHtml($this->readmeSection($readme, 'Description'));
            $changelog   = $this->markdownToHtml($this->readmeSection($readme, 'Changelog'));

            // the release notes, or a pointer at GitHub if there aren't any
            $body = ! empty($release->body) ? $this->markdownToHtml((string) $release->body) : '';
            if ($body === '') {
                $body = sprintf(
                    '<p><a href="%1$s" target="_blank">%2$s</a></p>',
                    esc_url('https://github.com/' . self::GH_REPO . '/releases'),
                    esc_html__('See the GitHub releases for the full notes.', 'kp-support')
                );
            }

            // and fall back to them for whichever section came up empty
            $description = ($description !== '') ? $description : $body;
            $changelog   = ($changelog !== '') ? $changelog : $body;

            // build out the info object
            $info                = new \stdClass();
            $info->name          = 'KP Support';
            $info->slug          = self::SLUG;
            $info->version       = ltrim((string) $release->tag_name, 'v');
            $info->author        = '<a href="https://kevinpirnie.com">Kevin Pirnie</a>';
            $info->homepage      = 'https://github.com/' . self::GH_REPO;
            $info->icons         = array(
                'svg' => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
                '1x'  => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
                '2x'  => 'https://cdn.kcp.im/logos/kevinpirnie-favicon-initials.svg',
            );
            $info->requires      = '6.8';
            $info->tested        = '7.1';
            $info->requires_php  = '8.2';
            $info->last_updated  = $release->published_at ?? '';
            $info->sections      = array(
                'description' => $description,
                'changelog'   => $changelog,
            );
            $info->download_link = $this->zipUrl($release);

            return $info;
        }

        /**
         * Dump the cached release once an update has run.
         *
         * @since  1.0.21
         * @access public
         * @param  object $upgrader The upgrader instance, we don't use it.
         * @param  array  $options  The completion data.
         * @return void
         */
        public function purgeCache(object $upgrader, array $options): void
        {

            // only plugin updates matter here
            if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
                return;
            }

            // and only if we were one of them
            if (! in_array(KP_SUPPORT_BASENAME, (array) ($options['plugins'] ?? array()), true)) {
                return;
            }

            // clear them out
            delete_transient(self::TRANSIENT);
            delete_transient(self::README_TRANSIENT);

            // and force a fresh check so the notice doesn't linger
            delete_site_transient('update_plugins');
        }

        /**
         * Rename the extracted folder to match our slug.
         *
         * A GitHub zipball extracts as owner-repo-sha, which loses WordPress
         * entirely. Our release asset already has the right root, so this is
         * only ever a safety net for the zipball fallback.
         *
         * @since  1.0.21
         * @access public
         * @param  string $source        The extracted source path.
         * @param  string $remote_source The temp directory path.
         * @param  object $upgrader      The upgrader instance, we don't use it.
         * @param  array  $hook_extra    The extra hook data.
         * @return string|\WP_Error The corrected path, or an error.
         */
        public function fixSourceDir(string $source, string $remote_source, object $upgrader, array $hook_extra): string|\WP_Error
        {

            // only act on ourselves
            if (($hook_extra['plugin'] ?? '') !== KP_SUPPORT_BASENAME) {
                return $source;
            }

            // where it ought to be
            $corrected = trailingslashit($remote_source) . self::SLUG . '/';

            // already right, which is the release asset case
            if ($source === $corrected) {
                return $source;
            }

            // move it into place
            global $wp_filesystem;
            if (! $wp_filesystem->move($source, $corrected)) {
                return new \WP_Error(
                    'kpts_rename_failed',
                    esc_html__('Could not rename the plugin folder during the update.', 'kp-support')
                );
            }

            return $corrected;
        }

        /**
         * Turn a small subset of markdown into html for the details modal.
         *
         * @since  1.0.21
         * @access private
         * @param  string $md The raw markdown.
         * @return string The html.
         */
        private function markdownToHtml(string $md): string
        {

            // nothing in, nothing out
            if (empty($md)) {
                return '';
            }

            // normalize the line endings
            $md = str_replace("\r\n", "\n", $md);

            // fenced code blocks
            $md = preg_replace('/```[a-z]*\n(.*?)\n```/si', '<pre><code>$1</code></pre>', $md);

            // headings
            $md = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $md);
            $md = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $md);
            $md = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $md);
            $md = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $md);

            // readme.txt style headings
            $md = preg_replace('/^=\s*(.+?)\s*=$/m', '<h4>$1</h4>', $md);

            // unordered lists, before the emphasis pass so bullets aren't read as italics
            $md = preg_replace_callback('/(?:^[-*] .+\n?)+/m', function (array $match): string {
                $items = preg_replace('/^[-*] (.+)$/m', '<li>$1</li>', trim($match[0]));
                return '<ul>' . $items . '</ul>';
            }, $md);

            // ordered lists
            $md = preg_replace_callback('/(?:^\d+\. .+\n?)+/m', function (array $match): string {
                $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($match[0]));
                return '<ol>' . $items . '</ol>';
            }, $md);

            // bold and italic
            $md = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $md);
            $md = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $md);
            $md = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $md);

            // inline code
            $md = preg_replace('/`([^`]+)`/', '<code>$1</code>', $md);

            // links
            $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $md);

            // horizontal rules
            $md = preg_replace('/^[-*]{3,}$/m', '<hr>', $md);

            // wrap anything that isn't already a block element in a paragraph
            $blocks     = preg_split('/\n{2,}/', $md);
            $block_tags = 'h1|h2|h3|h4|ul|ol|pre|hr';
            $html       = '';

            // loop them and build the output
            foreach ($blocks as $_block) {

                // skip the empties
                $_block = trim($_block);
                if (empty($_block)) {
                    continue;
                }

                // already a block, leave it be
                if (preg_match('/^<(' . $block_tags . ')[\s>]/i', $_block)) {
                    $html .= $_block . "\n";
                    continue;
                }

                // otherwise it's a paragraph
                $html .= '<p>' . nl2br($_block) . '</p>' . "\n";
            }

            return wp_kses_post($html);
        }
    }
}
