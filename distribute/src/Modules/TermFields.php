<?php

/**
 * TermFields - Extra fields on our taxonomy terms
 *
 * Lets people colour code their priorities and statuses, control the order they
 * come out in, and flag which statuses mean a ticket is finished.
 *
 * @package     KP Support
 * @author      Kevin Pirnie <me@kpirnie.com>
 * @since       1.0.0
 */

declare(strict_types=1);

namespace KP\Support\Modules;

// We don't want to allow direct access to this
defined('ABSPATH') || die('No direct script access allowed');

// make sure the class is not already defined
if (! class_exists('\KP\Support\Modules\TermFields')) {

    /**
     * Class TermFields
     *
     * Adds colour, ordering and closed-state fields to our taxonomy terms.
     *
     * @since 1.0.0
     */
    class TermFields extends AbstractModule
    {
        /**
         * Hook this module into WordPress.
         *
         * @since  1.0.0
         * @access public
         * @return void
         */
        public function register(): void
        {

            // the taxonomies that get these extra fields
            $taxonomies = array(
                PostTypes::TAX_STATUS,
                PostTypes::TAX_PRIORITY,
                PostTypes::TAX_DEPARTMENT,
                PostTypes::TAX_CATEGORY,
            );

            // wire the form fields and the save handlers up for each
            foreach ($taxonomies as $_taxonomy) {
                add_action($_taxonomy . '_add_form_fields', array($this, 'renderAddFields'), 10, 1);
                add_action($_taxonomy . '_edit_form_fields', array($this, 'renderEditFields'), 10, 2);
                add_action('created_' . $_taxonomy, array($this, 'saveFields'), 10, 1);
                add_action('edited_' . $_taxonomy, array($this, 'saveFields'), 10, 1);
            }

            // and add the colour swatch to the term list tables
            add_filter('manage_edit-' . PostTypes::TAX_STATUS . '_columns', array($this, 'addColorColumn'));
            add_filter('manage_edit-' . PostTypes::TAX_PRIORITY . '_columns', array($this, 'addColorColumn'));
            add_filter('manage_' . PostTypes::TAX_STATUS . '_custom_column', array($this, 'renderColorColumn'), 10, 3);
            add_filter('manage_' . PostTypes::TAX_PRIORITY . '_custom_column', array($this, 'renderColorColumn'), 10, 3);
        }

        /**
         * Render our fields on the add term form.
         *
         * @since  1.0.0
         * @access public
         * @param  string $taxonomy The taxonomy being added to.
         * @return void
         */
        public function renderAddFields($taxonomy): void
        {

            // only the folks who manage settings get these
            if (! current_user_can('kpts_manage_settings')) {
                return;
            }

            // our nonce
            wp_nonce_field('kpts_term_fields', 'kpts_term_nonce');

            // the colour picker
?>
            <div class="form-field">
                <label for="kpts_color"><?php esc_html_e('Colour', 'kp-support'); ?></label>
                <input type="color" name="kpts_color" id="kpts_color" value="#0073aa" />
                <p><?php esc_html_e('Used for the badge shown on tickets.', 'kp-support'); ?></p>
            </div>
            <div class="form-field">
                <label for="kpts_weight"><?php esc_html_e('Sort Order', 'kp-support'); ?></label>
                <input type="number" name="kpts_weight" id="kpts_weight" value="0" step="1" />
                <p><?php esc_html_e('Lower numbers come first in dropdowns.', 'kp-support'); ?></p>
            </div>
            <?php

            // and the closed flag, which only makes sense on statuses
            if ($taxonomy === PostTypes::TAX_STATUS) {
            ?>
                <div class="form-field">
                    <label for="kpts_is_closed">
                        <input type="checkbox" name="kpts_is_closed" id="kpts_is_closed" value="1" />
                        <?php esc_html_e('Tickets in this status count as closed', 'kp-support'); ?>
                    </label>
                </div>
            <?php
            }
        }

        /**
         * Render our fields on the edit term form.
         *
         * @since  1.0.0
         * @access public
         * @param  \WP_Term $term     The term being edited.
         * @param  string   $taxonomy The taxonomy it's in.
         * @return void
         */
        public function renderEditFields($term, $taxonomy): void
        {

            // only the folks who manage settings get these
            if (! current_user_can('kpts_manage_settings') || ! $term instanceof \WP_Term) {
                return;
            }

            // pull what's already saved
            $color = (string) get_term_meta($term->term_id, 'kpts_color', true);
            $weight = (string) get_term_meta($term->term_id, 'kpts_weight', true);
            $is_closed = (bool) get_term_meta($term->term_id, 'kpts_is_closed', true);

            // our nonce
            wp_nonce_field('kpts_term_fields', 'kpts_term_nonce');

            // the colour picker
            ?>
            <tr class="form-field">
                <th scope="row"><label for="kpts_color"><?php esc_html_e('Colour', 'kp-support'); ?></label></th>
                <td>
                    <input type="color" name="kpts_color" id="kpts_color" value="<?php echo esc_attr($color !== '' ? $color : '#0073aa'); ?>" />
                    <p class="description"><?php esc_html_e('Used for the badge shown on tickets.', 'kp-support'); ?></p>
                </td>
            </tr>
            <tr class="form-field">
                <th scope="row"><label for="kpts_weight"><?php esc_html_e('Sort Order', 'kp-support'); ?></label></th>
                <td>
                    <input type="number" name="kpts_weight" id="kpts_weight" value="<?php echo esc_attr($weight !== '' ? $weight : '0'); ?>" step="1" />
                    <p class="description"><?php esc_html_e('Lower numbers come first in dropdowns.', 'kp-support'); ?></p>
                </td>
            </tr>
            <?php

            // and the closed flag, which only makes sense on statuses
            if ($taxonomy === PostTypes::TAX_STATUS) {
            ?>
                <tr class="form-field">
                    <th scope="row"><?php esc_html_e('Closed State', 'kp-support'); ?></th>
                    <td>
                        <label for="kpts_is_closed">
                            <input type="checkbox" name="kpts_is_closed" id="kpts_is_closed" value="1" <?php checked($is_closed); ?> />
                            <?php esc_html_e('Tickets in this status count as closed', 'kp-support'); ?>
                        </label>
                    </td>
                </tr>
<?php
            }
        }

        /**
         * Save our term fields.
         *
         * @since  1.0.0
         * @access public
         * @param  int $term_id The term being saved.
         * @return void
         */
        public function saveFields($term_id): void
        {

            // cast it down
            $term_id = absint($term_id);

            // the nonce has to check out
            if (! isset($_POST['kpts_term_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['kpts_term_nonce'])), 'kpts_term_fields')) {
                return;
            }

            // and they have to be allowed to be doing this
            if (! current_user_can('kpts_manage_settings')) {
                return;
            }

            // save the colour, but only if it's a real hex value
            if (isset($_POST['kpts_color'])) {

                // sanitize it down to a hex colour
                $color = sanitize_hex_color(sanitize_text_field(wp_unslash($_POST['kpts_color'])));

                // and store it if it survived
                if ($color !== null && $color !== '') {
                    update_term_meta($term_id, 'kpts_color', $color);
                }
            }

            // save the sort weight
            if (isset($_POST['kpts_weight'])) {
                update_term_meta($term_id, 'kpts_weight', (int) sanitize_text_field(wp_unslash($_POST['kpts_weight'])));
            }

            // and the closed flag, which is only ever posted from a status form
            if (isset($_POST['taxonomy']) && sanitize_key(wp_unslash($_POST['taxonomy'])) === PostTypes::TAX_STATUS) {
                update_term_meta($term_id, 'kpts_is_closed', empty($_POST['kpts_is_closed']) ? 0 : 1);
            }
        }

        /**
         * Add a colour column to the term list tables.
         *
         * @since  1.0.0
         * @access public
         * @param  array<string, string> $columns The existing columns.
         * @return array<string, string> The columns with ours added.
         */
        public function addColorColumn($columns): array
        {

            // tack ours on the end
            $columns['kpts_color'] = __('Colour', 'kp-support');

            // and hand them back
            return (array) $columns;
        }

        /**
         * Render the colour swatch in the term list table.
         *
         * @since  1.0.0
         * @access public
         * @param  string $content The current column content.
         * @param  string $column  The column being rendered.
         * @param  int    $term_id The term id.
         * @return string The column content.
         */
        public function renderColorColumn($content, $column, $term_id): string
        {

            // only our column
            if ($column !== 'kpts_color') {
                return (string) $content;
            }

            // pull the colour
            $color = (string) get_term_meta((int) $term_id, 'kpts_color', true);

            // nothing set, nothing to show
            if ($color === '') {
                return '&mdash;';
            }

            // and render a little swatch
            return sprintf(
                '<span style="display:inline-block;width:20px;height:20px;border-radius:3px;border:1px solid #ccc;background:%1$s;" title="%2$s"></span>',
                esc_attr($color),
                esc_attr($color)
            );
        }
    }
}
