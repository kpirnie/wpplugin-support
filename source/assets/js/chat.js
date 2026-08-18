/**
 * KP Support - Chat
 *
 * Drives the corner docked chat panel and, on the agent screen, the queue that
 * sits alongside it. Plain vanilla JavaScript, no jQuery, same script both
 * sides, the config object tells it which one it's on.
 *
 * @package KP Support
 * @author  Kevin Pirnie <me@kpirnie.com>
 * @since   1.0.0
 */

(function () {
    'use strict';

    // grab the configuration WordPress handed us
    var cfg = window.kptsChat || {};
    var strings = cfg.strings || {};

    // where we keep track of things
    var state = {
        latest: '',
        chatId: 0,
        nonce: cfg.nonce || '',
        sending: false,
        timer: null,
        failures: 0,
        stopped: false,
        live: true,
        filter: 'mine',
        queueTimer: null,
        confirm: ''
    };

    /**
     * Fire a request at our AJAX endpoint.
     *
     * @param {FormData} data The form data to send.
     * @return {Promise} Resolves with the parsed response.
     */
    function request(data) {

        // every request carries the current nonce, which the server keeps
        // rotating for us so a panel left open all day doesn't go stale
        data.append('nonce', state.nonce);

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
     * Take the fresh nonce off a response, if it carried one.
     *
     * @param {Object} response The parsed response.
     * @return {void}
     */
    function takeNonce(response) {

        // swap it in so the next request uses it
        if (response && response.data && response.data.nonce) {
            state.nonce = response.data.nonce;
        }
    }

    /**
     * Show a message in the panel's notice block.
     *
     * @param {string} message The message to show.
     * @return {void}
     */
    function showNotice(message) {

        // go find it
        var notice = document.querySelector('.kpts-chat-notice');

        // nothing to show it in
        if (!notice) {
            return;
        }

        // if there's no message, hide it again
        if (!message) {
            notice.hidden = true;
            notice.textContent = '';
            return;
        }

        // otherwise show it, textContent so nothing can be injected
        notice.textContent = message;
        notice.hidden = false;
    }

    /**
     * Build a message element out of what the server sent back.
     *
     * @param {Object} message The message details.
     * @return {Element} The list item.
     */
    function buildMessage(message) {

        // the row itself
        var li = document.createElement('li');
        li.className = 'kpts-chat-message kpts-chat-' + (message.isMine ? 'mine' : 'theirs');
        li.setAttribute('data-message-id', message.id);

        // agents get their own hook for styling
        if (message.isAgent) {
            li.className += ' kpts-chat-agent';
        }

        // their avatar
        var avatar = document.createElement('img');
        avatar.className = 'kpts-chat-avatar';
        avatar.src = message.avatar || '';
        avatar.alt = '';
        avatar.width = 32;
        avatar.height = 32;
        li.appendChild(avatar);

        // the bubble everything sits in
        var bubble = document.createElement('div');
        bubble.className = 'kpts-chat-bubble';

        // who said it and when
        var meta = document.createElement('div');
        meta.className = 'kpts-chat-meta';

        // their name, textContent so nothing can be injected
        var author = document.createElement('span');
        author.className = 'kpts-chat-author';
        author.textContent = message.author || '';
        meta.appendChild(author);

        // and the time
        var time = document.createElement('span');
        time.className = 'kpts-chat-time';
        time.textContent = message.date || '';
        meta.appendChild(time);
        bubble.appendChild(meta);

        // the message body, already run through kses server side
        var content = document.createElement('div');
        content.className = 'kpts-chat-content';
        content.innerHTML = message.content || '';
        bubble.appendChild(content);

        // and hand the whole row back
        li.appendChild(bubble);
        return li;
    }

    /**
     * Drop a message into the list, unless it's already there.
     *
     * @param {Object} message The message details.
     * @return {void}
     */
    function insertMessage(message) {

        // where they go
        var list = document.querySelector('.kpts-chat-messages');

        // nowhere to put it
        if (!list) {
            return;
        }

        // the poll and the send can race, so never render the same one twice
        if (list.querySelector('[data-message-id="' + message.id + '"]')) {
            return;
        }

        // in it goes, and scroll down to it
        list.appendChild(buildMessage(message));
        list.scrollTop = list.scrollHeight;
    }

    /**
     * Reflect the chat's current state in the panel.
     *
     * @param {Object} chatState The state details.
     * @return {void}
     */
    function updateState(chatState) {

        // nothing to reflect
        if (!chatState) {
            return;
        }

        // hang on to whether it's still running
        state.live = !!chatState.live;

        // the panel wrapper carries it for the styling
        var wrap = document.querySelector('.kpts-chat');
        if (wrap) {
            wrap.setAttribute('data-state', chatState.state || '');
        }

        // say who has it, or that nobody does yet
        var status = document.querySelector('.kpts-chat-status');
        if (status) {
            if (chatState.agentName) {
                status.textContent = (strings.agentJoined || '%s').replace('%s', chatState.agentName);
            } else if (state.live) {
                status.textContent = strings.waiting || '';
            } else {
                status.textContent = strings.closed || '';
            }
        }

        // a closed chat takes no more messages
        var form = document.querySelector('.kpts-chat-form');
        if (form) {
            form.hidden = !state.live || !state.chatId;
        }

        // the agent screen has a toolbar to keep in step, the visitor just has the end button
        if (cfg.isAgent) {
            updateAgentControls(chatState);
        } else {
            var end = document.querySelector('.kpts-chat-end');
            if (end) {
                end.hidden = !state.live || !state.chatId;
            }
        }

        // once it's closed the poller has nothing left to ask about
        if (!state.live) {
            stopPolling();
        }
    }

    /**
     * Keep the agent toolbar in step with the chat.
     *
     * @param {Object} chatState The state details.
     * @return {void}
     */
    function updateAgentControls(chatState) {

        // whether anything is actionable right now
        var actionable = !!state.chatId && state.live;

        // the assignment dropdown, which reflects who currently has it
        var assignee = document.querySelector('.kpts-chat-assignee');
        if (assignee) {
            assignee.disabled = !actionable;
            assignee.value = String(chatState.agentId || 0);
        }

        // converting and closing
        var convert = document.querySelector('.kpts-chat-convert');
        if (convert) {
            convert.disabled = !actionable;
        }

        // the close button here is a disabled toolbar button, not a hidden one
        var end = document.querySelector('.kpts-chat-end');
        if (end) {
            end.disabled = !actionable;
        }

        // and the link out to whatever it became
        var link = document.querySelector('.kpts-chat-ticket-link');
        if (link) {
            if (chatState.ticketId && chatState.ticketUrl) {
                link.href = chatState.ticketUrl;
                link.hidden = false;
            } else {
                link.hidden = true;
            }
        }
    }
    
    /**
     * Stop the poller for good.
     *
     * @return {void}
     */
    function stopPolling() {

        // kill anything pending
        if (state.timer) {
            window.clearTimeout(state.timer);
            state.timer = null;
        }

        // and don't schedule anything else
        state.stopped = true;
    }

    /**
     * Queue the next poll up.
     *
     * @return {void}
     */
    function schedule() {

        // nothing more to do
        if (state.stopped || !state.chatId) {
            return;
        }

        // back off as failures stack up so we're not hammering a broken endpoint
        var interval = cfg.pollInterval || 10000;
        if (state.failures > 0) {
            interval = Math.min(interval * Math.pow(2, state.failures), 300000);
        }

        // and queue it
        state.timer = window.setTimeout(poll, interval);
    }

    /**
     * Ask the server what's happened since we last looked.
     *
     * @return {void}
     */
    function poll() {

        // nothing to poll about
        if (!state.chatId || state.stopped) {
            return;
        }

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_poll');
        data.append('chat_id', state.chatId);
        data.append('since', state.latest);

        // off it goes
        request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // if it went wrong, work out whether it's worth trying again
            if (!response.success) {

                // an expired session or a lost chat is terminal, stop asking
                if (response.data && (response.data.code === 'expired' || response.data.code === 'forbidden')) {
                    stopPolling();
                    showNotice(response.data.message || strings.expired);
                    return;
                }

                // otherwise back off and try again
                state.failures++;
                schedule();
                return;
            }

            // we're talking again
            state.failures = 0;

            // drop in anything new
            if (response.data.messages && response.data.messages.length) {
                response.data.messages.forEach(insertMessage);
            }

            // move our cutoff forward
            if (response.data.latest) {
                state.latest = response.data.latest;
            }

            // and reflect where the chat stands
            updateState(response.data.state);

            // round we go again
            schedule();
        }).catch(function () {

            // the network dropped, back off and retry
            state.failures++;
            schedule();
        });
    }

    /**
     * Send whatever is in the box.
     *
     * @param {HTMLFormElement} form The chat form.
     * @return {void}
     */
    function send(form) {

        // don't stack sends on top of each other
        if (state.sending || !state.chatId) {
            return;
        }

        // what they typed
        var input = form.querySelector('.kpts-chat-input');
        var content = input ? input.value.trim() : '';

        // nothing to send
        if (!content) {
            showNotice(strings.emptyMessage);
            return;
        }

        // lock it down while we're working
        state.sending = true;
        showNotice('');

        // the send button says what's going on
        var button = form.querySelector('.kpts-chat-send');
        var label = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = strings.sending || label;
        }

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_send');
        data.append('chat_id', state.chatId);
        data.append('content', content);

        // off it goes
        request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // if it failed, say why and leave what they typed alone
            if (!response.success) {
                showNotice((response.data && response.data.message) || strings.sendFailed);
                return;
            }

            // clear the box now we know it landed
            if (input) {
                input.value = '';
            }

            // render it and move our cutoff forward
            insertMessage(response.data.message);
            if (response.data.latest) {
                state.latest = response.data.latest;
            }

            // and reflect where the chat stands
            updateState(response.data.state);
        }).catch(function () {

            // the network dropped
            showNotice(strings.sendFailed);
        }).then(function () {

            // give the form back either way
            state.sending = false;
            if (button) {
                button.disabled = false;
                button.textContent = label;
            }
        });
    }

    /**
     * Open a chat, or pick up the one already going.
     *
     * @return {Promise} Resolves once we have a chat.
     */
    function start() {

        // already got one
        if (state.chatId) {
            return Promise.resolve(true);
        }

        // say what we're doing
        showNotice(strings.starting);

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_start');

        // off it goes
        return request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // if it failed, say why
            if (!response.success) {
                showNotice((response.data && response.data.message) || strings.startFailed);
                return false;
            }

            // clear the notice and hang on to the chat
            showNotice('');
            state.chatId = response.data.chatId;
            state.stopped = false;

            // remember it on the wrapper too
            var wrap = document.querySelector('.kpts-chat');
            if (wrap) {
                wrap.setAttribute('data-chat-id', state.chatId);
            }

            // render anything already on it
            if (response.data.messages && response.data.messages.length) {
                response.data.messages.forEach(insertMessage);
            }

            // move our cutoff forward
            if (response.data.latest) {
                state.latest = response.data.latest;
            }

            // reflect where it stands and start watching it
            updateState(response.data.state);
            schedule();
            return true;
        }).catch(function () {

            // the network dropped
            showNotice(strings.startFailed);
            return false;
        });
    }

    /**
     * Close the chat out, which archives it as a ticket.
     *
     * @return {void}
     */
    function close() {

        // nothing to close
        if (!state.chatId || !state.live) {
            return;
        }

        // make them mean it
        if (!window.confirm(strings.confirmClose)) {
            return;
        }

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_close');
        data.append('chat_id', state.chatId);

        // off it goes
        request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // if it failed, say why
            if (!response.success) {
                showNotice((response.data && response.data.message) || strings.sendFailed);
                return;
            }

            // it's done, reflect it and say so
            updateState(response.data.state);
            showNotice((response.data && response.data.message) || strings.closed);
        }).catch(function () {

            // the network dropped
            showNotice(strings.sendFailed);
        });
    }

    /**
     * Wire the panel up.
     *
     * @return {void}
     */
    function initPanel() {

        // go find it
        var wrap = document.querySelector('.kpts-chat');

        // nothing to wire up
        if (!wrap) {
            return;
        }

        // pick up whatever came down with the page
        state.chatId = parseInt(wrap.getAttribute('data-chat-id'), 10) || 0;
        state.latest = wrap.getAttribute('data-latest') || '';

        // the bits we're working with
        var launcher = wrap.querySelector('.kpts-chat-launcher');
        var panel = wrap.querySelector('.kpts-chat-panel');
        var form = wrap.querySelector('.kpts-chat-form');

        // opening it starts a chat if they haven't got one going
        if (launcher && panel) {
            launcher.addEventListener('click', function () {

                // flip it
                var opening = panel.hidden;
                panel.hidden = !opening;
                launcher.setAttribute('aria-expanded', opening ? 'true' : 'false');
                wrap.classList.toggle('kpts-chat-open', opening);

                // nothing else to do on the way closed
                if (!opening) {
                    return;
                }

                // scroll to the bottom of whatever is already there
                var list = wrap.querySelector('.kpts-chat-messages');
                if (list) {
                    list.scrollTop = list.scrollHeight;
                }

                // and get a chat going
                start().then(function () {
                    var input = wrap.querySelector('.kpts-chat-input');
                    if (input) {
                        input.focus();
                    }
                });
            });
        }

        // minimizing just hides the panel, the chat stays open
        var minimize = wrap.querySelector('.kpts-chat-minimize');
        if (minimize && panel && launcher) {
            minimize.addEventListener('click', function () {
                panel.hidden = true;
                launcher.setAttribute('aria-expanded', 'false');
                wrap.classList.remove('kpts-chat-open');
            });
        }

        // ending it for real
        var end = wrap.querySelector('.kpts-chat-end');
        if (end) {
            end.addEventListener('click', close);
        }

        // sending
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                send(form);
            });

            // and enter sends, shift enter breaks the line
            var input = form.querySelector('.kpts-chat-input');
            if (input) {
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        send(form);
                    }
                });
            }
        }

        // if they already had one going, start watching it straight away
        if (state.chatId) {
            schedule();
        }

        // catch up quickly when they come back to the tab
        document.addEventListener('visibilitychange', function () {

            // nothing to catch up on
            if (document.hidden || state.stopped || !state.chatId) {
                return;
            }

            // pull the next poll forward
            if (state.timer) {
                window.clearTimeout(state.timer);
            }
            state.timer = window.setTimeout(poll, 500);
        });
    }

        /**
     * Load a chat into the workspace.
     *
     * @param {number} chatId  The chat to load.
     * @param {string} confirm The convert nonce that came with its queue row.
     * @return {void}
     */
    function selectChat(chatId, confirm) {

        // stop watching whatever we had
        stopPolling();

        // start clean on the new one
        state.chatId = chatId;
        state.latest = '';
        state.stopped = false;
        state.failures = 0;
        state.live = true;
        state.confirm = confirm || '';

        // clear the conversation out
        var list = document.querySelector('.kpts-chat-messages');
        if (list) {
            list.textContent = '';
        }

        // and the notice with it
        showNotice('');

        // mark the row they picked
        document.querySelectorAll('.kpts-chat-queue-item').forEach(function (item) {
            item.classList.toggle('is-active', parseInt(item.getAttribute('data-chat-id'), 10) === chatId);
        });

        // remember it on the wrapper
        var wrap = document.querySelector('.kpts-chat');
        if (wrap) {
            wrap.setAttribute('data-chat-id', String(chatId));
        }

        // pull the whole conversation down, then start watching it
        poll();
    }

    /**
     * Build a queue row.
     *
     * @param {Object} chat The chat details.
     * @return {Element} The list item.
     */
    function buildQueueRow(chat) {

        // the row itself
        var li = document.createElement('li');

        // it's a button so it's reachable by keyboard
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'kpts-chat-queue-item';
        button.setAttribute('data-chat-id', chat.chatId);
        button.setAttribute('data-confirm', chat.confirm || '');

        // it's the active one if it's what we're already looking at
        if (state.chatId === chat.chatId) {
            button.className += ' is-active';
        }

        // their avatar
        var avatar = document.createElement('img');
        avatar.className = 'kpts-chat-avatar';
        avatar.src = chat.avatar || '';
        avatar.alt = '';
        avatar.width = 32;
        avatar.height = 32;
        button.appendChild(avatar);

        // who they are and what they last said
        var body = document.createElement('span');

        // their name, textContent so nothing can be injected
        var name = document.createElement('span');
        name.className = 'kpts-chat-queue-visitor';
        name.textContent = chat.visitor || '';
        body.appendChild(name);

        // and the preview
        var preview = document.createElement('span');
        preview.className = 'kpts-chat-queue-preview';
        preview.textContent = chat.preview || '';
        body.appendChild(preview);

        // hand the whole row back
        button.appendChild(body);
        li.appendChild(button);
        return li;
    }

    /**
     * Pull the queue down and render it.
     *
     * @return {void}
     */
    function loadQueue() {

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_queue');
        data.append('filter', state.filter);

        // off it goes
        request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // where they go
            var list = document.querySelector('.kpts-chat-queue-list');
            if (!list) {
                return;
            }

            // if it failed, leave whatever is on screen alone unless it's terminal
            if (!response.success) {
                if (response.data && response.data.code === 'expired') {
                    showNotice(response.data.message || strings.expired);
                    return;
                }
                scheduleQueue();
                return;
            }

            // start clean
            list.textContent = '';

            // nothing waiting
            if (!response.data.chats || !response.data.chats.length) {
                var empty = document.createElement('li');
                empty.className = 'kpts-chat-queue-empty';
                empty.textContent = strings.noChats || '';
                list.appendChild(empty);
                scheduleQueue();
                return;
            }

            // render each one
            response.data.chats.forEach(function (chat) {
                list.appendChild(buildQueueRow(chat));
            });

            // and round we go again
            scheduleQueue();
        }).catch(function () {

            // the network dropped, try again on the next tick
            scheduleQueue();
        });
    }

    /**
     * Queue the next queue refresh up.
     *
     * @return {void}
     */
    function scheduleQueue() {

        // clear anything pending first
        if (state.queueTimer) {
            window.clearTimeout(state.queueTimer);
        }

        // and set the next one going
        state.queueTimer = window.setTimeout(loadQueue, cfg.queueInterval || 10000);
    }

    /**
     * Hand the current chat to somebody.
     *
     * @param {number} agentId The agent to hand it to, 0 to unassign.
     * @return {void}
     */
    function assign(agentId) {

        // nothing to assign
        if (!state.chatId) {
            return;
        }

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_assign');
        data.append('chat_id', state.chatId);
        data.append('agent', agentId);

        // off it goes
        request(data).then(function (response) {

            // take the fresh nonce whatever else happened
            takeNonce(response);

            // if it failed, say why
            if (!response.success) {
                showNotice((response.data && response.data.message) || strings.assignFailed);
                return;
            }

            // reflect it, say so, and refresh the queue so it moves lists
            updateState(response.data.state);
            showNotice(response.data.message || strings.assigned);
            loadQueue();
        }).catch(function () {

            // the network dropped
            showNotice(strings.assignFailed);
        });
    }

    /**
     * Turn the current chat into a live ticket.
     *
     * @return {void}
     */
    function convert() {

        // nothing to convert, and it needs the nonce its queue row carried
        if (!state.chatId || !state.confirm) {
            return;
        }

        // make them mean it
        if (!window.confirm(strings.confirmConvert)) {
            return;
        }

        // say what we're doing
        showNotice(strings.converting);

        // build the request
        var data = new FormData();
        data.append('action', 'kpts_chat_convert');
        data.append('chat_id', state.chatId);
        data.append('confirm', state.confirm);

        // off it goes
        request(data).then(function (response) {

            // if it failed, say why
            if (!response.success) {
                showNotice((response.data && response.data.message) || strings.sendFailed);
                return;
            }

            // and send them straight to the new ticket
            if (response.data.redirect) {
                window.location.href = response.data.redirect;
            }
        }).catch(function () {

            // the network dropped
            showNotice(strings.sendFailed);
        });
    }

    /**
     * Wire the agent screen up.
     *
     * @return {void}
     */
    function initAgent() {

        // go find it
        var wrap = document.querySelector('.kpts-chat-admin');

        // nothing to wire up
        if (!wrap) {
            return;
        }

        // which slice we're showing
        state.filter = 'mine';

        // the tabs across the top of the queue
        wrap.querySelectorAll('.kpts-chat-queue-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {

                // mark it
                wrap.querySelectorAll('.kpts-chat-queue-tab').forEach(function (other) {
                    other.classList.remove('is-active');
                });
                tab.classList.add('is-active');

                // and reload with it
                state.filter = tab.getAttribute('data-filter') || 'all';
                loadQueue();
            });
        });

        // picking a chat out of the queue
        var list = wrap.querySelector('.kpts-chat-queue-list');
        if (list) {
            list.addEventListener('click', function (event) {

                // did they hit a row
                var item = event.target.closest('.kpts-chat-queue-item');
                if (!item) {
                    return;
                }

                // load it up
                selectChat(
                    parseInt(item.getAttribute('data-chat-id'), 10) || 0,
                    item.getAttribute('data-confirm') || ''
                );
            });
        }

        // sending
        var form = wrap.querySelector('.kpts-chat-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                send(form);
            });

            // and enter sends, shift enter breaks the line
            var input = form.querySelector('.kpts-chat-input');
            if (input) {
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        send(form);
                    }
                });
            }
        }

        // the assignment dropdown
        var assignee = wrap.querySelector('.kpts-chat-assignee');
        if (assignee) {
            assignee.addEventListener('change', function () {
                assign(parseInt(assignee.value, 10) || 0);
            });
        }

        // converting
        var convertButton = wrap.querySelector('.kpts-chat-convert');
        if (convertButton) {
            convertButton.addEventListener('click', convert);
        }

        // and closing out
        var end = wrap.querySelector('.kpts-chat-end');
        if (end) {
            end.addEventListener('click', close);
        }

        // pull the queue down to get going
        loadQueue();
    }

    /**
     * Kick everything off.
     *
     * @return {void}
     */
    function init() {

        // the agent screen and the visitor panel are two different things
        if (cfg.isAgent) {
            initAgent();
            return;
        }

        // otherwise it's the corner panel
        initPanel();
    }

    // go when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
