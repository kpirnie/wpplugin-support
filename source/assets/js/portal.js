/**
 * KP Support - Portal
 *
 * Drives the AJAX chat, the new ticket form, and the agent controls. Plain
 * vanilla JavaScript, no jQuery, so it loads the same on the front end as it
 * does inside wp-admin.
 *
 * @package KP Support
 * @author  Kevin Pirnie <me@kpirnie.com>
 * @since   1.0.0
 */

(function () {
    'use strict';

    // grab the configuration WordPress handed us
    var cfg = window.kptsPortal || {};
    var strings = cfg.strings || {};

    // where we keep track of things
    var state = {
        latest: '',
        ticketId: 0,
        polling: false,
        timer: null,
        failures: 0,
        stopped: false
    };

    /**
     * Fire a request at our AJAX endpoint.
     *
     * @param {FormData} data The form data to send.
     * @return {Promise} Resolves with the parsed response.
     */
    function request(data) {

        // every request carries the nonce
        data.append('nonce', cfg.nonce || '');

        // and off it goes
        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        }).then(function (response) {

            // we always want the JSON, even on an error status
            return response.json().catch(function () {
                return { success: false, data: { message: strings.sendFailed } };
            });
        });
    }

    /**
     * Show a message in one of our error blocks.
     *
     * @param {Element} el      The error element.
     * @param {string}  message The message to show.
     * @return {void}
     */
    function showError(el, message) {

        // nothing to show it in
        if (!el) {
            return;
        }

        // if there's no message, hide it again
        if (!message) {
            el.hidden = true;
            el.textContent = '';
            return;
        }

        // otherwise show it, textContent so nothing can be injected
        el.textContent = message;
        el.hidden = false;
    }

    /**
     * Check the files somebody picked against our limits.
     *
     * @param {HTMLInputElement} input The file input.
     * @return {string} An error message, or an empty string if they're fine.
     */
    function validateFiles(input) {

        // nothing picked
        if (!input || !input.files || !input.files.length) {
            return '';
        }

        // too many of them
        if (cfg.maxFiles && input.files.length > cfg.maxFiles) {
            return strings.tooManyFiles || 'Too many files.';
        }

        // and check each one's size
        for (var i = 0; i < input.files.length; i++) {
            if (cfg.maxFileSize && input.files[i].size > cfg.maxFileSize) {
                return strings.fileTooBig || 'That file is too large.';
            }
        }

        // they're good
        return '';
    }

    /**
     * List out the files somebody picked, so they can see what's attached.
     *
     * @param {HTMLInputElement} input The file input.
     * @param {Element}          list  The list to render into.
     * @return {void}
     */
    function renderFileList(input, list) {

        // nothing to render into
        if (!list) {
            return;
        }

        // start fresh
        list.innerHTML = '';

        // nothing picked
        if (!input.files || !input.files.length) {
            return;
        }

        // add each one
        for (var i = 0; i < input.files.length; i++) {
            var item = document.createElement('li');
            item.className = 'kpts-file-item';
            item.textContent = input.files[i].name;
            list.appendChild(item);
        }
    }

    /**
     * Drop a new reply into the thread, in the right spot.
     *
     * @param {Object} reply The reply payload from the server.
     * @return {void}
     */
    function insertReply(reply) {

        // find the thread
        var thread = document.querySelector('.kpts-thread');
        if (!thread || !reply || !reply.html) {
            return;
        }

        // if it's already on the page, leave it alone
        if (thread.querySelector('[data-reply-id="' + reply.id + '"]')) {
            return;
        }

        // parse the markup the server rendered for us
        var holder = document.createElement('template');
        holder.innerHTML = String(reply.html).trim();

        // nothing usable came back
        var node = holder.content.firstElementChild;
        if (!node) {
            return;
        }

        // work out where it belongs, nested under its parent if we can find it
        var list = null;
        if (reply.parent) {

            // go looking for the parent reply
            var parentNode = thread.querySelector('[data-reply-id="' + reply.parent + '"]');

            // and find or build its child list
            if (parentNode) {
                list = parentNode.querySelector(':scope > ul.kpts-replies-nested');
                if (!list) {
                    list = document.createElement('ul');
                    list.className = 'kpts-replies kpts-replies-nested';
                    parentNode.appendChild(list);
                }
            }
        }

        // fall back to the top level
        if (!list) {
            list = thread.querySelector('.kpts-replies-root');
        }

        // still nowhere to put it
        if (!list) {
            return;
        }

        // clear the empty placeholder out of the way
        var empty = thread.querySelector('.kpts-no-replies');
        if (empty) {
            empty.parentNode.removeChild(empty);
        }

        // drop it in and give it a moment of highlight
        list.appendChild(node);
        node.classList.add('kpts-reply-new');
        window.setTimeout(function () {
            node.classList.remove('kpts-reply-new');
        }, 2000);
    }

    /**
     * Update the status badge after something changed.
     *
     * @param {Object} status The status payload from the server.
     * @return {void}
     */
    function updateStatus(status) {

        // nothing to update with
        if (!status || !status.name) {
            return;
        }

        // find the badge and refresh it
        var badge = document.querySelector('.kpts-ticket-badges .kpts-badge');
        if (badge) {
            badge.textContent = status.name;
            if (status.color) {
                badge.style.background = status.color;
            }
        }
    }

    /**
     * Schedule the next poll.
     *
     * @return {void}
     */
    function schedule() {

        // we've been told to stop
        if (state.stopped) {
            return;
        }

        // clear anything pending
        if (state.timer) {
            window.clearTimeout(state.timer);
        }

        // back off a bit each time we fail, so a broken endpoint doesn't hammer the server
        var interval = (cfg.pollInterval || 10000) * Math.min(8, Math.pow(2, state.failures));

        // and queue the next one up
        state.timer = window.setTimeout(poll, interval);
    }

    /**
     * Go and see whether anybody has replied.
     *
     * @return {void}
     */
    function poll() {

        // nothing to poll for
        if (!state.ticketId || state.stopped) {
            return;
        }

        // if the tab isn't visible, don't bother, just check again later
        if (document.hidden) {
            schedule();
            return;
        }

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_fetch_replies');
        data.append('ticket_id', state.ticketId);
        data.append('since', state.latest);

        // and send it
        request(data).then(function (response) {

            // if the session died, stop polling entirely rather than spinning
            if (!response.success) {
                if (response.data && response.data.code === 'expired') {
                    state.stopped = true;
                    return;
                }

                // otherwise count it as a failure and back off
                state.failures++;
                schedule();
                return;
            }

            // we're healthy again
            state.failures = 0;

            // drop in anything new
            if (response.data.replies && response.data.replies.length) {
                response.data.replies.forEach(insertReply);
            }

            // move our cutoff along
            if (response.data.latest) {
                state.latest = response.data.latest;
            }

            // and refresh the status
            updateStatus(response.data.status);

            // go round again
            schedule();
        }).catch(function () {

            // network trouble, back off and try again
            state.failures++;
            schedule();
        });
    }

    /**
     * Wire up the reply form and the thread.
     *
     * @return {void}
     */
    function initThread() {

        // find the thread
        var thread = document.querySelector('.kpts-thread');
        if (!thread) {
            return;
        }

        // pick up where the server left off
        state.ticketId = parseInt(thread.getAttribute('data-ticket-id'), 10) || 0;
        state.latest = thread.getAttribute('data-latest') || '';

        // the bits of the reply form
        var form = thread.querySelector('.kpts-reply-form');
        var errorBox = thread.querySelector('.kpts-reply-error');
        var parentInput = thread.querySelector('.kpts-reply-parent');
        var replyingTo = thread.querySelector('.kpts-replying-to');
        var replyingToText = thread.querySelector('.kpts-replying-to-text');
        var fileInput = thread.querySelector('.kpts-file-input');
        var fileList = thread.querySelector('.kpts-file-list');

        // show the picked files as they're chosen
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                renderFileList(fileInput, fileList);
                showError(errorBox, validateFiles(fileInput));
            });
        }

        // clicking reply on a message threads underneath it
        thread.addEventListener('click', function (event) {

            // the reply button
            var button = event.target.closest('.kpts-reply-to');
            if (button && parentInput) {

                // set the parent and tell them who they're replying to
                parentInput.value = button.getAttribute('data-parent') || '0';

                // update the little banner
                if (replyingTo && replyingToText) {
                    var template = strings.replyTo || 'Replying to %s';
                    replyingToText.textContent = template.replace('%s', button.getAttribute('data-author') || '');
                    replyingTo.hidden = false;
                }

                // and put the cursor in the box
                var box = thread.querySelector('.kpts-reply-content-input');
                if (box) {
                    box.focus();
                }
                return;
            }

            // and cancelling puts it back to a top level reply
            if (event.target.closest('.kpts-cancel-reply')) {
                if (parentInput) {
                    parentInput.value = '0';
                }
                if (replyingTo) {
                    replyingTo.hidden = true;
                }
            }
        });

        // sending a reply
        if (form) {
            form.addEventListener('submit', function (event) {

                // we're handling this ourselves
                event.preventDefault();

                // the bits we need
                var content = form.querySelector('.kpts-reply-content-input');
                var internal = form.querySelector('.kpts-internal-input');
                var button = form.querySelector('.kpts-send-reply');

                // clear any previous error
                showError(errorBox, '');

                // check the files over before we send anything
                var fileError = validateFiles(fileInput);
                if (fileError) {
                    showError(errorBox, fileError);
                    return;
                }

                // they have to have written something, or attached something
                var hasFiles = fileInput && fileInput.files && fileInput.files.length;
                if ((!content || !content.value.trim()) && !hasFiles) {
                    showError(errorBox, strings.emptyReply || 'Please enter a reply.');
                    return;
                }

                // build the request out of the form itself so the files come along
                var data = new FormData(form);
                data.append('action', 'kpts_send_reply');
                data.append('ticket_id', state.ticketId);

                // make sure the internal flag reflects the checkbox
                if (internal && !internal.checked) {
                    data.delete('internal');
                }

                // lock the button while it's in flight
                if (button) {
                    button.disabled = true;
                    button.dataset.label = button.textContent;
                    button.textContent = strings.sending || 'Sending...';
                }

                // and send it
                request(data).then(function (response) {

                    // put the button back however it goes
                    if (button) {
                        button.disabled = false;
                        button.textContent = button.dataset.label || 'Send Reply';
                    }

                    // if it failed, say why
                    if (!response.success) {
                        showError(errorBox, (response.data && response.data.message) || strings.sendFailed);
                        return;
                    }

                    // drop the new reply in
                    insertReply(response.data.reply);

                    // move our cutoff along
                    if (response.data.latest) {
                        state.latest = response.data.latest;
                    }

                    // refresh the status badge
                    updateStatus(response.data.status);

                    // and reset the form back to a clean top level reply
                    form.reset();
                    if (parentInput) {
                        parentInput.value = '0';
                    }
                    if (replyingTo) {
                        replyingTo.hidden = true;
                    }
                    if (fileList) {
                        fileList.innerHTML = '';
                    }
                }).catch(function () {

                    // put the button back and say something went wrong
                    if (button) {
                        button.disabled = false;
                        button.textContent = button.dataset.label || 'Send Reply';
                    }
                    showError(errorBox, strings.sendFailed);
                });
            });
        }

        // start watching for new replies
        if (state.ticketId) {
            schedule();
        }

        // and check straight away when they come back to the tab
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !state.stopped) {
                state.failures = 0;
                if (state.timer) {
                    window.clearTimeout(state.timer);
                }
                state.timer = window.setTimeout(poll, 500);
            }
        });
    }

    /**
     * Wire the new ticket form up.
     *
     * @return {void}
     */
    function initNewTicket() {

        // find it
        var form = document.querySelector('.kpts-new-ticket-form');
        if (!form) {
            return;
        }

        // the bits we need
        var errorBox = form.querySelector('.kpts-form-error');
        var fileInput = form.querySelector('.kpts-file-input');
        var fileList = form.querySelector('.kpts-file-list');

        // show the picked files as they're chosen
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                renderFileList(fileInput, fileList);
                showError(errorBox, validateFiles(fileInput));
            });
        }

        // and handle the submit
        form.addEventListener('submit', function (event) {

            // we're handling this ourselves
            event.preventDefault();

            // clear any previous error
            showError(errorBox, '');

            // check the files over before we send anything
            var fileError = validateFiles(fileInput);
            if (fileError) {
                showError(errorBox, fileError);
                return;
            }

            // lock the button
            var button = form.querySelector('.kpts-submit-ticket');
            if (button) {
                button.disabled = true;
                button.dataset.label = button.textContent;
                button.textContent = strings.sending || 'Sending...';
            }

            // build the request out of the form
            var data = new FormData(form);
            data.append('action', 'kpts_create_ticket');

            // and send it
            request(data).then(function (response) {

                // if it failed, put the button back and say why
                if (!response.success) {
                    if (button) {
                        button.disabled = false;
                        button.textContent = button.dataset.label || 'Open Ticket';
                    }
                    showError(errorBox, (response.data && response.data.message) || strings.sendFailed);
                    return;
                }

                // otherwise send them straight to their new ticket
                window.location.href = response.data.redirect;
            }).catch(function () {

                // put the button back and say something went wrong
                if (button) {
                    button.disabled = false;
                    button.textContent = button.dataset.label || 'Open Ticket';
                }
                showError(errorBox, strings.sendFailed);
            });
        });
    }

    /**
     * Wire the agent control panel up.
     *
     * @return {void}
     */
    function initAgentControls() {

        // find it
        var panel = document.querySelector('.kpts-agent-panel');
        if (!panel) {
            return;
        }

        // where we tell them what happened
        var feedback = panel.querySelector('.kpts-agent-feedback');
        var ticketId = parseInt(panel.getAttribute('data-ticket-id'), 10) || 0;

        // watch every dropdown in the panel
        panel.addEventListener('change', function (event) {

            // only our fields
            var field = event.target.closest('.kpts-ticket-field');
            if (!field) {
                return;
            }

            // build the request
            var data = new FormData();
            data.append('action', 'kpts_update_ticket');
            data.append('ticket_id', ticketId);
            data.append(field.getAttribute('data-field'), field.value);

            // and send it
            request(data).then(function (response) {

                // say how it went
                if (feedback) {
                    feedback.textContent = response.success
                        ? (response.data.message || '')
                        : ((response.data && response.data.message) || strings.sendFailed);
                    feedback.hidden = false;

                    // and fade the message back out
                    window.setTimeout(function () {
                        feedback.hidden = true;
                    }, 3000);
                }

                // refresh the badge if the status moved
                if (response.success) {
                    updateStatus(response.data.status);
                }
            });
        });
    }

    /**
     * Wire the login and registration tabs up.
     *
     * @return {void}
     */
    function initTabs() {

        // find them
        var tabs = document.querySelectorAll('.kpts-tab');
        if (!tabs.length) {
            return;
        }

        // and switch panels on click
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {

                // which one did they click
                var target = tab.getAttribute('data-tab');

                // mark the right tab active
                tabs.forEach(function (other) {
                    other.classList.toggle('is-active', other === tab);
                });

                // and show the matching panel
                document.querySelectorAll('.kpts-tab-panel').forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.getAttribute('data-panel') === target);
                });
            });
        });
    }

    /**
     * Fire everything up once the page is ready.
     *
     * @return {void}
     */
    function init() {
        initTabs();
        initThread();
        initNewTicket();
        initAgentControls();
    }

    // and go, whether we got here before or after the DOM was ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
