/**
 * KP Support - Admin
 *
 * Fills our own fields into the quick edit row, which core opens up empty
 * because it knows nothing about them.
 *
 * @package KP Support
 * @author  Kevin Pirnie <me@kpirnie.com>
 * @since   1.0.40
 */

(function ($) {
    'use strict';

    // nothing to hook into if the inline editor isn't on this screen
    if (typeof window.inlineEditPost === 'undefined') {
        return;
    }

    // hang on to core's version so we can let it go first
    var original = window.inlineEditPost.edit;

    /**
     * Open the quick edit row, then set our fields to what the ticket has.
     *
     * @param {number|Object} id The row being edited.
     * @return {*} Whatever core handed back.
     */
    window.inlineEditPost.edit = function (id) {

        // let core build the row out
        var result = original.apply(this, arguments);

        // work out which ticket we're on
        var postId = (typeof id === 'object') ? parseInt(window.inlineEditPost.getId(id), 10) : parseInt(id, 10);

        // nothing to fill in
        if (!postId) {
            return result;
        }

        // what it's currently set to, and the row we're filling
        var data = document.getElementById('kpts-inline-' + postId);
        var row = document.getElementById('edit-' + postId);

        // one of them isn't there
        if (!data || !row) {
            return result;
        }

        // drop each of ours onto its current value
        ['status', 'priority', 'dept', 'assignee'].forEach(function (key) {

            // go find it
            var field = row.querySelector('.kpts-quick-' + key);

            // and set it, falling back to nothing picked
            if (field) {
                field.value = data.getAttribute('data-' + key) || '0';
            }
        });

        // hand core's result back
        return result;
    };
}(jQuery));
