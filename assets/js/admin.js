/**
 * BBSE - Admin JavaScript
 * Toggle blocks, notifications, modal, AJAX
 */

(function ($) {
    'use strict';

    const AddonBB = {
        notificationTimeout: null,
        codeEditors: {},
        currentPage: 1,
        blocksPerPage: 12,

        init: function () {
            this.blocksPerPage = (bbse.pagination && bbse.pagination.perPage) ? bbse.pagination.perPage : 12;
            this.bindEvents();
            this.applySearchAndSort(false);
            this.highlightNewBlock();
        },

        bindEvents: function () {
            $(document).on('click', '#bbse-add-block, #bbse-add-first', this.addBlock.bind(this));
            $(document).on('change', '.bbse-switch-input', this.toggleBlock.bind(this));
            $(document).on('click', '.bbse-edit-block', this.openEditModal.bind(this));
            $(document).on('click', '.bbse-delete-block', this.deleteBlock.bind(this));
            $(document).on('click', '#bbse-save-block', this.saveBlock.bind(this));
            $(document).on('click', '.bbse-modal-close, .bbse-modal-backdrop', this.closeModal.bind(this));
            $(document).on('click', '.bbse-tab', this.switchTab.bind(this));
            $(document).on('click', '#bbse-search-clear', this.clearSearch.bind(this));
            $(document).on('input', '#bbse-search', this.debounce(() => this.applySearchAndSort(true), 150));
            $(document).on('change', '#bbse-sort', () => this.applySearchAndSort(true));
            $(document).on('click', '#bbse-sync-remote', this.syncRemoteBlocks.bind(this));
            $(document).on('click', '#bbse-delete-all', this.deleteAllBlocks.bind(this));
            $(document).on('keydown', this.handleKeydown.bind(this));
            $(document).on('click', '.bbse-page-btn:not([disabled])', this.goToPage.bind(this));
        },

        debounce: function (fn, delay) {
            let timer = null;
            return function () {
                const ctx = this;
                const args = arguments;
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(ctx, args), delay);
            };
        },

        clearSearch: function () {
            $('#bbse-search').val('').focus();
            $('.bbse-search-wrap').removeClass('has-value');
            this.applySearchAndSort(true);
        },

        syncRemoteBlocks: function () {
            const $btn = $('#bbse-sync-remote');
            const oldText = $btn.text();
            $btn.prop('disabled', true).text(bbse.strings.syncing || 'Syncing...');

            $.ajax({
                url: bbse.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bbse_sync_remote_blocks',
                    nonce: bbse.nonce
                },
                success: (res) => {
                    if (res.success) {
                        this.showNotification(res.data.message, 'success');
                        this.reloadPage();
                    } else {
                        this.showNotification((res.data && res.data.message) ? res.data.message : bbse.strings.error, 'error');
                    }
                },
                error: (xhr) => {
                    const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                        ? xhr.responseJSON.data.message
                        : bbse.strings.error;
                    this.showNotification(msg, 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).text(oldText);
                }
            });
        },

        applySearchAndSort: function (resetPage) {
            if (resetPage) {
                this.currentPage = 1;
            }

            const query = $('#bbse-search').val().toLowerCase().trim();
            const sortBy = $('#bbse-sort').val();
            const $container = $('#bbse-blocks-container');
            const $cards = $container.find('.bbse-card');
            const $noResults = $('#bbse-no-results');

            if ($cards.length === 0) return;

            $('.bbse-search-wrap').toggleClass('has-value', query.length > 0);

            // 1. Filter
            const filtered = $cards.filter(function () {
                if (!query) return true;
                const name = $(this).data('block-name') || '';
                const types = $(this).data('block-types') || '';
                return (name + ' ' + types).indexOf(query) !== -1;
            }).get();

            // 2. Sort
            filtered.sort((a, b) => {
                const $a = $(a), $b = $(b);
                const nameA = ($a.find('.bbse-card-title').text() || '').toLowerCase();
                const nameB = ($b.find('.bbse-card-title').text() || '').toLowerCase();
                const statusA = $a.data('block-status');
                const statusB = $b.data('block-status');
                const typesA = ($a.data('block-types') || '').toLowerCase();
                const typesB = ($b.data('block-types') || '').toLowerCase();

                switch (sortBy) {
                    case 'name-asc':       return nameA.localeCompare(nameB);
                    case 'name-desc':      return nameB.localeCompare(nameA);
                    case 'active-first':   return (statusB === 'active' ? 1 : 0) - (statusA === 'active' ? 1 : 0) || nameA.localeCompare(nameB);
                    case 'inactive-first': return (statusA === 'active' ? 1 : 0) - (statusB === 'active' ? 1 : 0) || nameA.localeCompare(nameB);
                    case 'type':           return typesA.localeCompare(typesB) || nameA.localeCompare(nameB);
                    default:               return 0;
                }
            });

            // Reorder DOM: filtered+sorted first, excluded cards after
            const $excluded = $cards.not($(filtered));
            filtered.forEach(el => $container.append(el));
            $excluded.appendTo($container);

            // 3. Paginate
            const total = filtered.length;
            const perPage = this.blocksPerPage;
            const totalPages = Math.max(1, Math.ceil(total / perPage));
            if (this.currentPage > totalPages) this.currentPage = totalPages;

            const start = (this.currentPage - 1) * perPage;
            const end = start + perPage;

            $cards.addClass('bbse-card-hidden');
            filtered.slice(start, end).forEach(el => $(el).removeClass('bbse-card-hidden'));

            if (total === 0 && query) {
                $noResults.addClass('show');
            } else {
                $noResults.removeClass('show');
            }

            this.renderPagination(total);
        },

        renderPagination: function (total) {
            const $el = $('#bbse-pagination');
            const perPage = this.blocksPerPage;
            const totalPages = Math.ceil(total / perPage);

            if (totalPages <= 1) {
                $el.empty();
                return;
            }

            const cur = this.currentPage;
            const from = (cur - 1) * perPage + 1;
            const to = Math.min(cur * perPage, total);

            let btns = '';
            btns += `<button class="bbse-page-btn bbse-page-prev" data-page="${cur - 1}" ${cur === 1 ? 'disabled' : ''} aria-label="Previous page">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                     </button>`;

            this.getPaginationRange(cur, totalPages).forEach(p => {
                if (p === '…') {
                    btns += `<span class="bbse-page-ellipsis">…</span>`;
                } else {
                    btns += `<button class="bbse-page-btn bbse-page-num${p === cur ? ' active' : ''}" data-page="${p}" aria-label="Page ${p}" ${p === cur ? 'aria-current="page"' : ''}>${p}</button>`;
                }
            });

            btns += `<button class="bbse-page-btn bbse-page-next" data-page="${cur + 1}" ${cur === totalPages ? 'disabled' : ''} aria-label="Next page">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                     </button>`;

            $el.html(
                `<div class="bbse-pagination-inner">${btns}</div>` +
                `<span class="bbse-pagination-info">Showing ${from}–${to} of ${total}</span>`
            );
        },

        getPaginationRange: function (cur, total) {
            if (total <= 7) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }
            if (cur <= 4) {
                return [1, 2, 3, 4, 5, '…', total];
            }
            if (cur >= total - 3) {
                return [1, '…', total - 4, total - 3, total - 2, total - 1, total];
            }
            return [1, '…', cur - 1, cur, cur + 1, '…', total];
        },

        goToPage: function (e) {
            const page = parseInt($(e.currentTarget).data('page'), 10);
            if (!isNaN(page)) {
                this.currentPage = page;
                this.applySearchAndSort(false);
                const top = $('#bbse-blocks-container').offset().top - 60;
                $('html, body').animate({ scrollTop: top }, 180);
            }
        },

        updateStats: function () {
            const $all = $('.bbse-card');
            const $active = $all.filter('[data-block-status="active"]');
            $('#bbse-stats-active').text($active.length);
            $('#bbse-stats-total').text($all.length);
        },

        showNotification: function (message, type) {
            type = type || 'success';
            const $el = $('#bbse-notification');
            $el.removeClass('success error deactivated').addClass(type).text(message);

            clearTimeout(this.notificationTimeout);
            $el.addClass('show');

            this.notificationTimeout = setTimeout(() => {
                $el.removeClass('show');
                this.notificationTimeout = null;
            }, 3500);
        },

        fireConfetti: function () {
            const duration = 2500;
            const end = Date.now() + duration;

            const frame = () => {
                confetti({
                    particleCount: 3,
                    spread: 80,
                    origin: { x: 0.5, y: 1 }, // bottom center
                    colors: ['#e86d2a', '#f4a574', '#f9c9a8', '#fff5ef', '#2e7d32']
                });

                if (Date.now() < end) {
                    requestAnimationFrame(frame);
                }
            };

            frame();

            // Left and right bursts
            setTimeout(() => {
                confetti({
                    particleCount: 50,
                    angle: 60,
                    spread: 55,
                    origin: { x: 0, y: 1 } // bottom left
                });

                confetti({
                    particleCount: 50,
                    angle: 120,
                    spread: 55,
                    origin: { x: 1, y: 1 } // bottom right
                });
            }, 100);
        },

        toggleBlock: function (e) {
            const $switch = $(e.currentTarget);
            const $card = $switch.closest('.bbse-card');
            const blockId = $card.data('block-id');
            const wasChecked = $switch.prop('checked');

            $.ajax({
                url: bbse.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bbse_toggle_block',
                    nonce: bbse.nonce,
                    block_id: blockId,
                },
                success: (res) => {
                    if (res.success) {
                        const notifType = res.data.is_active ? 'success' : 'deactivated';
                        this.showNotification(res.data.message, notifType);
                        $card.data('block-status', res.data.is_active ? 'active' : 'inactive');
                        $card.attr('data-block-status', res.data.is_active ? 'active' : 'inactive');
                        $card.find('.bbse-status')
                            .toggleClass('active inactive')
                            .text(res.data.is_active ? 'Active' : 'Inactive');
                        this.updateStats();
                        if (res.data.is_active && typeof confetti === 'function') {
                            this.fireConfetti();
                        }
                    } else {
                        const errMsg = (res.data && res.data.message) ? res.data.message : bbse.strings.error;
                        this.showNotification(errMsg, 'error');
                        $switch.prop('checked', !wasChecked);
                    }
                },
                error: (xhr) => {
                    let errMsg = bbse.strings.error;
                    try {
                        const res = xhr.responseJSON;
                        if (res && res.data && res.data.message) {
                            errMsg = res.data.message;
                        }
                    } catch (e) { /* ignore */ }
                    this.showNotification(errMsg, 'error');
                    $switch.prop('checked', !wasChecked);
                },
            });
        },

        addBlock: function () {
            this.destroyCodeEditors();
            $('#bbse-edit-block-id').val('0');
            $('#bbse-edit-name').val('');
            $('#bbse-edit-css, #bbse-edit-js, #bbse-edit-php').val('');
            $('#bbse-modal-title').text(bbse.strings.addBlock);
            $('#bbse-edit-modal').attr('aria-hidden', 'false').addClass('show');
            $('.bbse-tab').first().click();
            this.initCodeEditors();
            setTimeout(() => $('#bbse-edit-name').focus(), 50);
        },

        reloadPage: function () {
            window.location.reload();
        },

        openEditModal: function (e) {
            const blockId = $(e.currentTarget).data('block-id');
            const $card = $(`.bbse-card[data-block-id="${blockId}"]`);
            const name = $card.find('.bbse-card-title').text();

            $('#bbse-edit-block-id').val(blockId);
            $('#bbse-edit-name').val(name);
            $('#bbse-edit-css, #bbse-edit-js, #bbse-edit-php').val('');
            $('#bbse-modal-title').text(bbse.strings.editBlock);
            $('#bbse-edit-modal').attr('aria-hidden', 'false').addClass('show');
            $('.bbse-tab').first().click();

            $.ajax({
                url: bbse.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'bbse_get_block',
                    nonce: bbse.nonce,
                    block_id: blockId,
                },
                success: (res) => {
                    if (res.success) {
                        $('#bbse-edit-css').val(res.data.css_code || '');
                        $('#bbse-edit-js').val(res.data.js_code || '');
                        $('#bbse-edit-php').val(res.data.php_code || '');
                        this.initCodeEditors();
                    }
                },
            });
        },

        initCodeEditors: function () {
            this.destroyCodeEditors();

            if (!bbse.codeEditor || !bbse.codeEditor.available || !window.wp || !wp.codeEditor) {
                return;
            }

            const settings = bbse.codeEditor.settings;
            if (settings.css) {
                this.codeEditors.css = wp.codeEditor.initialize('bbse-edit-css', settings.css);
            }
            if (settings.js) {
                this.codeEditors.js = wp.codeEditor.initialize('bbse-edit-js', settings.js);
            }
            if (settings.php) {
                this.codeEditors.php = wp.codeEditor.initialize('bbse-edit-php', settings.php);
            }
        },

        destroyCodeEditors: function () {
            ['css', 'js', 'php'].forEach((key) => {
                if (this.codeEditors[key] && this.codeEditors[key].codemirror) {
                    this.codeEditors[key].codemirror.toTextArea();
                    this.codeEditors[key] = null;
                }
            });
        },

        closeModal: function () {
            this.destroyCodeEditors();
            $('#bbse-edit-modal').attr('aria-hidden', 'true').removeClass('show');
        },

        switchTab: function (e) {
            const tab = $(e.currentTarget).data('tab');
            $('.bbse-tab').removeClass('active');
            $('.bbse-tab-panel').removeClass('active');
            $(`.bbse-tab[data-tab="${tab}"]`).addClass('active');
            $(`#tab-${tab}`).addClass('active');
            // Refresh CodeMirror when switching to a previously hidden tab
            const editorKey = { css: 'css', js: 'js', php: 'php' }[tab];
            if (this.codeEditors[editorKey] && this.codeEditors[editorKey].codemirror) {
                this.codeEditors[editorKey].codemirror.refresh();
            }
        },

        saveBlock: function () {
            const blockId = parseInt($('#bbse-edit-block-id').val(), 10) || 0;
            const name = $('#bbse-edit-name').val();
            let css = $('#bbse-edit-css').val();
            let js  = $('#bbse-edit-js').val();
            let php = $('#bbse-edit-php').val();
            if (this.codeEditors.css && this.codeEditors.css.codemirror) css = this.codeEditors.css.codemirror.getValue();
            if (this.codeEditors.js  && this.codeEditors.js.codemirror)  js  = this.codeEditors.js.codemirror.getValue();
            if (this.codeEditors.php && this.codeEditors.php.codemirror) php = this.codeEditors.php.codemirror.getValue();

            const $saveBtn = $('#bbse-save-block');
            $saveBtn.prop('disabled', true);

            const onError = (xhr) => {
                let errMsg = bbse.strings.error;
                try {
                    const r = xhr ? xhr.responseJSON : null;
                    if (r && r.data && r.data.message) errMsg = r.data.message;
                } catch (e) { /* ignore */ }
                this.showNotification(errMsg, 'error');
                $saveBtn.prop('disabled', false);
            };

            const doSave = (id, isNew) => {
                $.ajax({
                    url: bbse.ajaxUrl,
                    type: 'POST',
                    data: { action: 'bbse_save_block', nonce: bbse.nonce, block_id: id, name, css_code: css, js_code: js, php_code: php },
                    success: (res) => {
                        if (res.success) {
                            this.showNotification(res.data.message, 'success');
                            this.closeModal();
                            if (isNew) sessionStorage.setItem('bbse_new_block', id);
                            this.reloadPage();
                        } else {
                            const errMsg = (res.data && res.data.message) ? res.data.message : bbse.strings.error;
                            this.showNotification(errMsg, 'error');
                            $saveBtn.prop('disabled', false);
                        }
                    },
                    error: onError,
                });
            };

            if (blockId) {
                doSave(blockId, false);
            } else {
                // Create mode: first create the record, then save the content
                $.ajax({
                    url: bbse.ajaxUrl,
                    type: 'POST',
                    data: { action: 'bbse_create_block', nonce: bbse.nonce, name: name || bbse.strings.newBlock },
                    success: (res) => {
                        if (res.success) {
                            doSave(res.data.id, true);
                        } else {
                            this.showNotification(res.data?.message || bbse.strings.error, 'error');
                            $saveBtn.prop('disabled', false);
                        }
                    },
                    error: onError,
                });
            }
        },

        deleteBlock: function (e) {
            if (!confirm(bbse.strings.confirmDelete)) {
                return;
            }

            const blockId = $(e.currentTarget).data('block-id');
            const $card = $(`.bbse-card[data-block-id="${blockId}"]`);

            $.ajax({
                url: bbse.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bbse_delete_block',
                    nonce: bbse.nonce,
                    block_id: blockId,
                },
                success: (res) => {
                    if (res.success) {
                        this.showNotification(res.data.message, 'success');
                        $card.fadeOut(300, () => {
                            $card.remove();
                            if ($('.bbse-card').length === 0) {
                                this.reloadPage();
                            } else {
                                this.updateStats();
                                this.applySearchAndSort(false);
                            }
                        });
                    } else {
                        this.showNotification(res.data?.message || bbse.strings.error, 'error');
                    }
                },
                error: () => {
                    this.showNotification(bbse.strings.error, 'error');
                },
            });
        },

        deleteAllBlocks: function () {
            if (!confirm(bbse.strings.confirmDeleteAll)) {
                return;
            }

            const $btn = $('#bbse-delete-all');
            $btn.prop('disabled', true);

            $.ajax({
                url: bbse.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bbse_delete_all_blocks',
                    nonce: bbse.nonce,
                },
                success: (res) => {
                    if (res.success) {
                        this.showNotification(res.data.message, 'success');
                        this.reloadPage();
                    } else {
                        this.showNotification(res.data?.message || bbse.strings.error, 'error');
                        $btn.prop('disabled', false);
                    }
                },
                error: () => {
                    this.showNotification(bbse.strings.error, 'error');
                    $btn.prop('disabled', false);
                },
            });
        },

        highlightNewBlock: function () {
            const newId = sessionStorage.getItem('bbse_new_block');
            if (!newId) return;
            sessionStorage.removeItem('bbse_new_block');

            const $container = $('#bbse-blocks-container');
            const $card = $container.find(`.bbse-card[data-block-id="${newId}"]`);
            if (!$card.length) return;

            $card.prependTo($container).removeClass('bbse-card-hidden');

            const $badge = $('<span class="bbse-new-badge">New</span>');
            $card.find('.bbse-card-title').prepend($badge);
            $card.addClass('bbse-card-new');

            setTimeout(() => {
                $card.removeClass('bbse-card-new');
                $badge.remove();
            }, 5000);
        },

        handleKeydown: function (e) {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        },
    };

    $(document).ready(() => AddonBB.init());

})(jQuery);
