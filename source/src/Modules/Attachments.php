<?php

/**
 * Attachments - Protected ticket file handling
 *
 * Ticket attachments routinely carry private information, so they never land in
 * the media library and they never get a directly reachable URL. They go into a
 * hardened uploads subdirectory and only ever come back out through a delivery
 * endpoint that re-checks ticket access on every single request.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

use KP\Support\Helpers\Access;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\Attachments')) {

    /**
     * Class Attachments
     *
     * Upload, storage and gated delivery of ticket attachments.
     *
     * @since 1.0.0
     */
    class Attachments extends AbstractModule
    {
        /**
         * The directory name we store attachments under, inside uploads.
         *
         * @since 1.0.0
         * @var string
         */
        public const DIR_NAME = 'kpts-attachments';

        /**
         * Ticket meta key holding the index of every file on the ticket.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_INDEX = '_kpts_file_index';

        /**
         * Comment meta key holding a reply's attachment records.
         *
         * @since 1.0.0
         * @var string
         */
        public const META_REPLY_FILES = '_kpts_attachments';

        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // watch for a download request nice and early
            add_action('init', array($this, 'handleDownload'), 20);

            // clean the files up when a ticket gets deleted for good
            add_action('before_delete_post', array($this, 'cleanupTicket'), 10, 2);
        }

        /**
         * Get the base directory we store attachments in.
         *
         * @since  1.0.0
         * @access public
         * @return string The absolute base path, with no trailing slash.
         */
        public static function baseDir(): string
        {

            // hang off the standard uploads directory
            $uploads = wp_upload_dir();

            // and build our path underneath it
            return untrailingslashit($uploads['basedir']) . '/' . self::DIR_NAME;
        }

        /**
         * Create the attachment directory and lock it down.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public static function protectUploadDir(): void
        {

            // where we're putting things
            $dir = self::baseDir();

            // make sure it exists
            if (! wp_mkdir_p($dir)) {
                return;
            }

            // block direct web access on apache
            $htaccess = $dir . '/.htaccess';
            if (! file_exists($htaccess)) {
                file_put_contents(
                    $htaccess,
                    "# KP Support - these files are served through PHP only\n"
                    . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
                );
            }

            // and on IIS
            $webconfig = $dir . '/web.config';
            if (! file_exists($webconfig)) {
                file_put_contents(
                    $webconfig,
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n"
                    . "\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
                    . "\t</system.webServer>\n</configuration>\n"
                );
            }

            // stop directory listings anywhere the above doesn't apply
            $index = $dir . '/index.php';
            if (! file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }

        /**
         * Work out the largest file size we'll take, in bytes.
         *
         * @since  1.0.0
         * @access public
         * @return int The maximum size in bytes.
         */
        public static function maxSize(): int
        {

            // what the admin configured, in megabytes
            $configured = (int) \KP\Support\Plugin::opt('max_attachment_size', 5);

            // fall back to something sane
            if ($configured < 1) {
                $configured = 5;
            }

            // convert it up, but never go past what PHP itself will take
            $ours = $configured * MB_IN_BYTES;
            $php = wp_max_upload_size();

            // whichever is smaller wins
            return ($php > 0 && $php < $ours) ? (int) $php : $ours;
        }

        /**
         * Get the file extensions we're willing to accept.
         *
         * @since  1.0.0
         * @access public
         * @return array<int, string> The allowed extensions, lowercased.
         */
        public static function allowedTypes(): array
        {

            // what the admin configured
            $configured = (string) \KP\Support\Plugin::opt(
                'allowed_file_types',
                'jpg,jpeg,png,gif,webp,pdf,txt,log,csv,zip,doc,docx,xls,xlsx'
            );

            // break it apart and tidy it up
            $types = array_map('trim', explode(',', strtolower($configured)));

            // drop the empties, and never allow anything executable through
            $types = array_filter($types, static function (string $ext): bool {

                // the extensions we refuse no matter what anybody configures
                $never = array(
                    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
                    'htaccess', 'htm', 'html', 'shtml', 'js', 'jsp', 'asp', 'aspx',
                    'exe', 'sh', 'bash', 'cgi', 'pl', 'py', 'svg', 'swf',
                );

                // keep it only if it's real and not on the never list
                return $ext !== '' && ! in_array($ext, $never, true);
            });

            // hand back a clean list
            return array_values(array_unique($types));
        }

        /**
         * Take everything uploaded on a form field and store it.
         *
         * @since  1.0.0
         * @access public
         * @param  string $field   The $_FILES field name.
         * @param  int    $user_id The uploader's user id.
         * @return array<int, array<string, mixed>>|\WP_Error The stored records, or an error.
         */
        public static function processUploads(string $field, int $user_id): array|\WP_Error
        {

            // if attachments are turned off, there's nothing to do
            if (! \KP\Support\Plugin::opt('allow_attachments', true)) {
                return array();
            }

            // nothing was sent up
            if (empty($_FILES[$field]) || ! isset($_FILES[$field]['name'])) {
                return array();
            }

            // normalize the PHP files array into something we can loop
            $files = self::normalizeFiles($field);

            // nothing usable in there
            if (empty($files)) {
                return array();
            }

            // don't let them blow past the file count limit
            $max_files = (int) \KP\Support\Plugin::opt('max_attachments', 5);
            if ($max_files > 0 && count($files) > $max_files) {
                return new \WP_Error(
                    'kpts_too_many_files',
                    sprintf(
                        /* translators: %d: maximum number of files */
                        __('You can only attach up to %d files.', 'kp-support'),
                        $max_files
                    )
                );
            }

            // make sure our directory is there and locked down
            self::protectUploadDir();

            // store each one
            $records = array();
            foreach ($files as $_file) {

                // run it through the wringer
                $record = self::storeFile($_file, $user_id);

                // bail out entirely if any single file is bad
                if (is_wp_error($record)) {
                    return $record;
                }

                // hang on to it
                $records[] = $record;
            }

            // hand back everything we stored
            return $records;
        }

        /**
         * Flatten PHP's multi-file $_FILES structure into a simple list.
         *
         * @since  1.0.0
         * @access private
         * @param  string $field The $_FILES field name.
         * @return array<int, array<string, mixed>> One entry per uploaded file.
         */
        private static function normalizeFiles(string $field): array
        {

            // grab the raw structure
            $raw = $_FILES[$field]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each member is validated in storeFile()

            // a single file comes through flat
            if (! is_array($raw['name'])) {
                return (($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ? array() : array($raw);
            }

            // multiple files come through as parallel arrays, so pivot them
            $files = array();
            foreach (array_keys($raw['name']) as $_index) {

                // skip the slots nothing was actually put in
                if (($raw['error'][$_index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                // rebuild it as a normal single-file entry
                $files[] = array(
                    'name'     => $raw['name'][$_index],
                    'type'     => $raw['type'][$_index],
                    'tmp_name' => $raw['tmp_name'][$_index],
                    'error'    => $raw['error'][$_index],
                    'size'     => $raw['size'][$_index],
                );
            }

            // hand back the flattened list
            return $files;
        }

        /**
         * Validate a single uploaded file and move it into place.
         *
         * @since  1.0.0
         * @access private
         * @param  array<string, mixed> $file    The single file entry.
         * @param  int                  $user_id The uploader's user id.
         * @return array<string, mixed>|\WP_Error The stored record, or an error.
         */
        private static function storeFile(array $file, int $user_id): array|\WP_Error
        {

            // PHP has to have told us the upload went fine
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return new \WP_Error('kpts_upload_error', __('That file failed to upload. Please try again.', 'kp-support'));
            }

            // and it genuinely has to be an uploaded file, not something we were pointed at
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || ! is_uploaded_file($tmp)) {
                return new \WP_Error('kpts_bad_upload', __('That upload could not be verified.', 'kp-support'));
            }

            // check it against our size limit
            $size = (int) ($file['size'] ?? 0);
            $max = self::maxSize();
            if ($size < 1 || $size > $max) {
                return new \WP_Error(
                    'kpts_file_too_big',
                    sprintf(
                        /* translators: %s: human readable maximum file size */
                        __('Files must be smaller than %s.', 'kp-support'),
                        size_format($max)
                    )
                );
            }

            // clean the original name up, we only ever use it for display
            $original = sanitize_file_name((string) ($file['name'] ?? ''));
            if ($original === '') {
                return new \WP_Error('kpts_bad_filename', __('That file name could not be used.', 'kp-support'));
            }

            // let WordPress tell us what it really thinks this file is
            $checked = wp_check_filetype_and_ext($tmp, $original);
            $extension = strtolower((string) ($checked['ext'] ?: pathinfo($original, PATHINFO_EXTENSION)));
            $mime = (string) ($checked['type'] ?: '');

            // if WordPress couldn't work out a real type, we don't want it
            if ($mime === '' || $extension === '') {
                return new \WP_Error('kpts_unknown_type', __('That file type is not allowed.', 'kp-support'));
            }

            // and it has to be on our allow list
            if (! in_array($extension, self::allowedTypes(), true)) {
                return new \WP_Error(
                    'kpts_type_not_allowed',
                    sprintf(
                        /* translators: %s: the rejected file extension */
                        __('Files of type "%s" are not allowed.', 'kp-support'),
                        $extension
                    )
                );
            }

            // build out this month's folder
            $subdir = gmdate('Y/m');
            $target_dir = self::baseDir() . '/' . $subdir;
            if (! wp_mkdir_p($target_dir)) {
                return new \WP_Error('kpts_mkdir_failed', __('The attachment directory could not be created.', 'kp-support'));
            }

            // generate our own file name, we never trust theirs on disk
            $key = wp_generate_password(32, false, false);
            $stored_name = $key . '.' . $extension;
            $target = $target_dir . '/' . $stored_name;

            // move it into place
            if (! @move_uploaded_file($tmp, $target)) {
                return new \WP_Error('kpts_move_failed', __('That file could not be saved.', 'kp-support'));
            }

            // tighten the permissions up
            @chmod($target, 0644);

            // and hand back the record we'll store in the database
            return array(
                'key'  => $key,
                'name' => $original,
                'file' => $subdir . '/' . $stored_name,
                'size' => $size,
                'type' => $mime,
                'user' => $user_id,
                'date' => current_time('mysql'),
            );
        }

        /**
         * Add a set of file records to a ticket's lookup index.
         *
         * The index is what the delivery endpoint reads, so a file is only ever
         * reachable through the ticket it belongs to.
         *
         * @since  1.0.0
         * @access public
         * @param  int                              $ticket_id  The ticket id.
         * @param  array<int, array<string, mixed>> $records    The file records.
         * @param  int                              $comment_id The reply it belongs to, 0 for the ticket itself.
         * @return void
         */
        public static function indexFiles(int $ticket_id, array $records, int $comment_id = 0): void
        {

            // nothing to add
            if (empty($records)) {
                return;
            }

            // grab what we've already got indexed
            $index = get_post_meta($ticket_id, self::META_INDEX, true);
            if (! is_array($index)) {
                $index = array();
            }

            // add each record in, keyed by its lookup key
            foreach ($records as $_record) {

                // it has to have a key to be useful
                if (empty($_record['key'])) {
                    continue;
                }

                // note which reply it came in on and store it
                $_record['comment'] = $comment_id;
                $index[(string) $_record['key']] = $_record;
            }

            // save the index back
            update_post_meta($ticket_id, self::META_INDEX, $index);
        }

        /**
         * Build the download URL for a file.
         *
         * @since  1.0.0
         * @access public
         * @param  int    $ticket_id The ticket id.
         * @param  string $key       The file key.
         * @return string The download URL.
         */
        public static function url(int $ticket_id, string $key): string
        {

            // point at the site root with our query args on it
            return add_query_arg(
                array(
                    'kpts_file'   => $key,
                    'kpts_ticket' => $ticket_id,
                ),
                home_url('/')
            );
        }

        /**
         * Serve up a file, assuming the person asking is allowed to have it.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function handleDownload(): void
        {

            // nothing to do unless they're asking for a file
            if (empty($_GET['kpts_file']) || empty($_GET['kpts_ticket'])) {
                return;
            }

            // clean up what came in, the key is ours so it's strictly alphanumeric
            $key = preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_GET['kpts_file']));
            $ticket_id = absint(wp_unslash($_GET['kpts_ticket']));

            // both have to be real
            if ($key === '' || $ticket_id < 1) {
                wp_die(esc_html__('That file could not be found.', 'kp-support'), '', array('response' => 404));
            }

            // and this is the important bit, they have to be allowed on the ticket
            if (! Access::canViewTicket($ticket_id)) {
                wp_die(esc_html__('You are not allowed to access that file.', 'kp-support'), '', array('response' => 403));
            }

            // look the file up in the ticket's index
            $index = get_post_meta($ticket_id, self::META_INDEX, true);
            if (! is_array($index) || ! isset($index[$key])) {
                wp_die(esc_html__('That file could not be found.', 'kp-support'), '', array('response' => 404));
            }

            // grab the record
            $record = $index[$key];

            // if it came in on an internal note, only internal folks get it
            $comment_id = (int) ($record['comment'] ?? 0);
            if ($comment_id > 0 && Replies::isInternal($comment_id) && ! Access::canSeeInternal($ticket_id)) {
                wp_die(esc_html__('You are not allowed to access that file.', 'kp-support'), '', array('response' => 403));
            }

            // work out where it lives on disk
            $base = self::baseDir();
            $path = $base . '/' . ltrim((string) ($record['file'] ?? ''), '/');

            // make absolutely sure the resolved path is still inside our directory,
            // we don't run these through sanitize_text_field because that mangles
            // legitimate path characters, so we resolve and compare instead
            $real_path = realpath($path);
            $real_base = realpath($base);

            // if it doesn't resolve, or it escaped our directory, it's a no
            if ($real_path === false || $real_base === false || ! str_starts_with($real_path, $real_base . DIRECTORY_SEPARATOR)) {
                wp_die(esc_html__('That file could not be found.', 'kp-support'), '', array('response' => 404));
            }

            // and it actually has to be a readable file
            if (! is_file($real_path) || ! is_readable($real_path)) {
                wp_die(esc_html__('That file could not be found.', 'kp-support'), '', array('response' => 404));
            }

            // clear anything already buffered so we don't corrupt the file
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // send it down as a download, never inline, so nothing can execute in the browser
            nocache_headers();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode((string) ($record['name'] ?? 'download')) . '"');
            header('Content-Length: ' . (string) filesize($real_path));
            header('X-Content-Type-Options: nosniff');
            header('Content-Transfer-Encoding: binary');

            // push the file out and get out of here
            readfile($real_path);
            exit;
        }

        /**
         * Delete every file belonging to a ticket that's being removed.
         *
         * @since  1.0.0
         * @access public
         * @param  int      $post_id The post being deleted.
         * @param  \WP_Post $post    The post object.
         * @return void
         */
        public function cleanupTicket(int $post_id, $post = null): void
        {

            // only care about our own tickets
            if (! $post instanceof \WP_Post || $post->post_type !== PostTypes::POST_TYPE) {
                return;
            }

            // grab the file index
            $index = get_post_meta($post_id, self::META_INDEX, true);
            if (! is_array($index)) {
                return;
            }

            // where everything lives
            $base = self::baseDir();
            $real_base = realpath($base);

            // if our directory has gone missing there's nothing to clean
            if ($real_base === false) {
                return;
            }

            // and unlink each file, staying inside our directory
            foreach ($index as $_record) {

                // resolve the path
                $real_path = realpath($base . '/' . ltrim((string) ($_record['file'] ?? ''), '/'));

                // only delete it if it resolved inside our directory
                if ($real_path !== false && str_starts_with($real_path, $real_base . DIRECTORY_SEPARATOR) && is_file($real_path)) {
                    @unlink($real_path);
                }
            }
        }
    }
}
