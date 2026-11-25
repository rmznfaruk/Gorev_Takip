/* ==========================================================================
   GörevTakip — İstemci Tarafı Etkileşimler
   ========================================================================== */

(function () {
    'use strict';

    var STATUS_LABELS = {
        yapilacak: 'Yapılacak',
        devam_ediyor: 'Devam Ediyor',
        tamamlandi: 'Tamamlandı'
    };

    var STATUS_BADGE_CLASS = {
        yapilacak: 'badge-yapilacak',
        devam_ediyor: 'badge-devam',
        tamamlandi: 'badge-tamamlandi'
    };

    var STATUS_CLASSES = ['status-yapilacak', 'status-devam_ediyor', 'status-tamamlandi'];

    var STICKMAN_SPEECH_MESSAGES = [
        'Çizdim gitti!',
        'Bitti bile!',
        'Harika iş!',
        'Tam isabet!',
        'Hedefe +1!'
    ];
    var lastSpeechIndex = -1;

    /* ----------------------------------------------------------------------
       0. Ekranın En Tepesinde İnce Kobalt Yüklenme Çizgisi (#topProgressBar)
       ---------------------------------------------------------------------- */
    var topProgress = (function () {
        var barEl = null;
        var activeCount = 0;
        var trickleTimer = null;
        var hideTimer = null;
        var resetTimer = null;
        var navFallbackTimer = null;
        var currentScale = 0;

        function ensureBar() {
            if (barEl && barEl.parentNode) {
                return barEl;
            }
            barEl = document.getElementById('topProgressBar');
            if (!barEl && document.body) {
                barEl = document.createElement('div');
                barEl.id = 'topProgressBar';
                barEl.className = 'top-progress-bar';
                barEl.setAttribute('aria-hidden', 'true');
                document.body.appendChild(barEl);
            }
            return barEl;
        }

        function setScale(val) {
            currentScale = val;
            var el = ensureBar();
            if (el) {
                el.style.transform = 'scaleX(' + val + ')';
            }
        }

        function start() {
            var el = ensureBar();
            if (!el) {
                return;
            }
            activeCount++;
            if (hideTimer) {
                window.clearTimeout(hideTimer);
                hideTimer = null;
            }
            if (resetTimer) {
                window.clearTimeout(resetTimer);
                resetTimer = null;
            }
            if (trickleTimer) {
                window.clearInterval(trickleTimer);
                trickleTimer = null;
            }

            el.classList.add('is-active');
            if (currentScale < 0.15 || currentScale >= 1) {
                el.style.transition = 'none';
                setScale(0.12);
                void el.offsetWidth;
                el.style.transition = '';
            }
            window.requestAnimationFrame(function () {
                setScale(0.75);
            });

            trickleTimer = window.setInterval(function () {
                if (currentScale < 0.9) {
                    setScale(Math.min(0.9, currentScale + 0.03));
                }
            }, 350);
        }

        function done(forceAll) {
            if (forceAll) {
                activeCount = 0;
            } else if (activeCount > 0) {
                activeCount--;
            }
            if (activeCount > 0) {
                return;
            }
            if (trickleTimer) {
                window.clearInterval(trickleTimer);
                trickleTimer = null;
            }
            if (navFallbackTimer) {
                window.clearTimeout(navFallbackTimer);
                navFallbackTimer = null;
            }
            var el = ensureBar();
            if (!el) {
                return;
            }
            setScale(1);
            hideTimer = window.setTimeout(function () {
                el.classList.remove('is-active');
                resetTimer = window.setTimeout(function () {
                    if (!el.classList.contains('is-active')) {
                        el.style.transition = 'none';
                        setScale(0);
                        void el.offsetWidth;
                        el.style.transition = '';
                    }
                }, 220);
            }, 170);
        }

        function triggerNavigation() {
            start();
            if (navFallbackTimer) {
                window.clearTimeout(navFallbackTimer);
            }
            navFallbackTimer = window.setTimeout(function () {
                done(true);
            }, 2600);
        }

        ensureBar();

        if (typeof window.fetch === 'function' && !window.fetch.__topProgressWrapped) {
            var nativeFetch = window.fetch;
            var wrappedFetch = function () {
                start();
                try {
                    var p = nativeFetch.apply(this, arguments);
                    if (p && typeof p.then === 'function') {
                        p.then(
                            function (res) {
                                done(false);
                                return res;
                            },
                            function (err) {
                                done(false);
                                throw err;
                            }
                        );
                    } else {
                        done(false);
                    }
                    return p;
                } catch (syncErr) {
                    done(false);
                    throw syncErr;
                }
            };
            wrappedFetch.__topProgressWrapped = true;
            window.fetch = wrappedFetch;
        }

        return {
            ensure: ensureBar,
            start: start,
            done: done,
            triggerNavigation: triggerNavigation
        };
    })();

    function initTopProgressBar() {
        topProgress.ensure();

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            var link = e.target && typeof e.target.closest === 'function' ? e.target.closest('a[href]') : null;
            if (!link) {
                return;
            }
            if (link.hasAttribute('download') || link.hasAttribute('data-print-report') || link.hasAttribute('data-modal-open')) {
                return;
            }
            var targetAttr = (link.getAttribute('target') || '').toLowerCase();
            if (targetAttr && targetAttr !== '_self') {
                return;
            }
            var rawHref = (link.getAttribute('href') || '').trim();
            if (!rawHref || rawHref.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(rawHref)) {
                return;
            }
            try {
                var dest = new URL(link.href, window.location.href);
                if (dest.origin !== window.location.origin) {
                    return;
                }
                if (dest.pathname === window.location.pathname && dest.search === window.location.search && dest.hash) {
                    return;
                }
                topProgress.triggerNavigation();
            } catch (err) {
                // Geçersiz URL ise yoksay
            }
        });

        document.addEventListener('submit', function (e) {
            window.requestAnimationFrame(function () {
                if (e.defaultPrevented) {
                    return;
                }
                var form = e.target;
                if (!form || form.tagName !== 'FORM') {
                    return;
                }
                var methodAttr = (form.getAttribute('method') || '').toLowerCase();
                var targetAttr = (form.getAttribute('target') || '').toLowerCase();
                if (methodAttr === 'dialog' || (targetAttr && targetAttr !== '_self')) {
                    return;
                }
                topProgress.triggerNavigation();
            });
        });

        window.addEventListener('pageshow', function () {
            topProgress.done(true);
        });
    }

    /* ----------------------------------------------------------------------
       0b. Görev Yazısının Üstünde Yürüyüp Çizen Çöp Adam (showTaskStickmanScribe)
       ---------------------------------------------------------------------- */
    function pickSpeechMessage() {
        if (STICKMAN_SPEECH_MESSAGES.length <= 1) {
            return STICKMAN_SPEECH_MESSAGES[0] || 'Çizdim gitti!';
        }
        var idx = Math.floor(Math.random() * STICKMAN_SPEECH_MESSAGES.length);
        if (idx === lastSpeechIndex) {
            idx = (idx + 1) % STICKMAN_SPEECH_MESSAGES.length;
        }
        lastSpeechIndex = idx;
        return STICKMAN_SPEECH_MESSAGES[idx];
    }

    function resolveScribeHost(targetElement) {
        if (targetElement && typeof targetElement.closest === 'function') {
            if (targetElement.matches && targetElement.matches('.subtask-title, .task-title-link, .task-card-title, .page-title')) {
                return targetElement;
            }
            var subItem = targetElement.closest('.subtask-item');
            if (subItem) {
                return subItem.querySelector('.subtask-title') || subItem;
            }
            var card = targetElement.closest('.task-card');
            if (card) {
                return card.querySelector('.task-card-title') || card;
            }
            var row = targetElement.closest('tr');
            if (row) {
                return row.querySelector('.task-title-link') || row.querySelector('strong') || row.querySelector('td:first-child') || row;
            }
            var deadlineItem = targetElement.closest('.deadline-item');
            if (deadlineItem) {
                return deadlineItem.querySelector('.deadline-title') || deadlineItem;
            }
        }
        return document.querySelector('.page-title') || document.querySelector('strong') || null;
    }

    function cleanupHostScribe(host) {
        if (!host) {
            return;
        }
        if (host.__scribeTimers && host.__scribeTimers.length) {
            host.__scribeTimers.forEach(function (t) {
                window.clearTimeout(t);
            });
        }
        host.__scribeTimers = [];
        var oldStages = host.querySelectorAll('.stickman-scribe-stage');
        oldStages.forEach(function (el) {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });
        if (host.__scribeOverflowAncestors && host.__scribeOverflowAncestors.length) {
            host.__scribeOverflowAncestors.forEach(function (anc) {
                anc.classList.remove('has-active-scribe');
            });
            host.__scribeOverflowAncestors = [];
        }
        host.classList.remove('stickman-scribe-host');
    }

    function showTaskStickmanScribe(targetElement) {
        var strayCats = document.querySelectorAll('.cat-celebration');
        strayCats.forEach(function (node) {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        });

        var host = resolveScribeHost(targetElement);
        if (!host) {
            return null;
        }

        cleanupHostScribe(host);
        host.classList.add('stickman-scribe-host');

        var ancestors = [];
        ['.subtask-item', '.week-group', 'tr'].forEach(function (sel) {
            var anc = host.closest ? host.closest(sel) : null;
            if (anc) {
                anc.classList.add('has-active-scribe');
                ancestors.push(anc);
            }
        });
        host.__scribeOverflowAncestors = ancestors;

        var stage = document.createElement('span');
        stage.className = 'stickman-scribe-stage';
        stage.setAttribute('aria-hidden', 'true');

        var speechText = pickSpeechMessage();
        stage.innerHTML =
            '<svg class="stickman-scribble-stroke" viewBox="0 0 240 14" preserveAspectRatio="none" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<path class="stickman-scribble-path-main" pathLength="100" d="M1 8 C 44 3, 92 12, 144 6 C 184 3, 214 11, 239 7" stroke="#2563EB" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>' +
                '<path class="stickman-scribble-path-accent" pathLength="100" d="M6 10.5 C 72 6.5, 152 11, 236 8.5" stroke="#059669" stroke-width="1.5" stroke-linecap="round" stroke-opacity="0.82"/>' +
            '</svg>' +
            '<svg class="stickman-scribe-actor" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<g class="stickman-pose-draw">' +
                    '<circle cx="11.5" cy="6" r="3.4" fill="#FFFFFF" stroke="#1E3A8A" stroke-width="1.9"/>' +
                    '<line x1="11.5" y1="9.5" x2="10.6" y2="17.5" stroke="#1E3A8A" stroke-width="1.9" stroke-linecap="round"/>' +
                    '<line x1="11.2" y1="11.8" x2="6.6" y2="15" stroke="#1E3A8A" stroke-width="1.7" stroke-linecap="round"/>' +
                    '<line class="stickman-leg-a" x1="10.6" y1="17.5" x2="7.2" y2="23.6" stroke="#1E3A8A" stroke-width="1.85" stroke-linecap="round"/>' +
                    '<line class="stickman-leg-b" x1="10.6" y1="17.5" x2="14.4" y2="23.6" stroke="#1E3A8A" stroke-width="1.85" stroke-linecap="round"/>' +
                    '<g class="stickman-arm-pencil">' +
                        '<line x1="11.4" y1="11.8" x2="17.2" y2="15.2" stroke="#1E3A8A" stroke-width="1.85" stroke-linecap="round"/>' +
                        '<path d="M15.6 12.6 L18 11.4 L22 19.8 L19.6 21 Z" fill="#2563EB" stroke="#1E3A8A" stroke-width="1.15" stroke-linejoin="round"/>' +
                        '<polygon points="19.6,21 22,19.8 21.6,22.8" fill="#059669"/>' +
                    '</g>' +
                '</g>' +
                '<g class="stickman-pose-cheer">' +
                    '<circle cx="13" cy="6" r="3.4" fill="#FFFFFF" stroke="#059669" stroke-width="1.9"/>' +
                    '<line x1="13" y1="9.5" x2="13" y2="17.6" stroke="#059669" stroke-width="1.9" stroke-linecap="round"/>' +
                    '<line x1="13" y1="12" x2="7.6" y2="6.6" stroke="#059669" stroke-width="1.9" stroke-linecap="round"/>' +
                    '<line x1="13" y1="12" x2="18.6" y2="6.6" stroke="#059669" stroke-width="1.9" stroke-linecap="round"/>' +
                    '<line x1="17.8" y1="7.6" x2="22.4" y2="2.8" stroke="#2563EB" stroke-width="2.1" stroke-linecap="round"/>' +
                    '<line x1="13" y1="17.6" x2="9.4" y2="23.6" stroke="#059669" stroke-width="1.85" stroke-linecap="round"/>' +
                    '<line x1="13" y1="17.6" x2="16.6" y2="23.6" stroke="#059669" stroke-width="1.85" stroke-linecap="round"/>' +
                '</g>' +
            '</svg>' +
            '<span class="stickman-speech"></span>';

        var speechEl = stage.querySelector('.stickman-speech');
        if (speechEl) {
            speechEl.textContent = speechText;
        }

        host.appendChild(stage);

        if (speechEl && typeof host.getBoundingClientRect === 'function') {
            var rect = host.getBoundingClientRect();
            var vw = window.innerWidth || document.documentElement.clientWidth || 360;
            if (rect.right > vw - 108 || rect.width >= 96) {
                speechEl.classList.add('align-right');
            } else if (rect.left < 96) {
                speechEl.classList.add('align-left');
            } else {
                speechEl.classList.add('align-right');
            }
        }

        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var cheerDelay = reducedMotion ? 20 : 850;
        var fadeDelay = cheerDelay + 1100;
        var removeDelay = fadeDelay + 240;

        var t1 = window.setTimeout(function () {
            if (stage && stage.classList) {
                stage.classList.add('is-cheering');
            }
        }, cheerDelay);

        var t2 = window.setTimeout(function () {
            if (stage && stage.classList) {
                stage.classList.add('is-fading');
            }
        }, fadeDelay);

        var t3 = window.setTimeout(function () {
            cleanupHostScribe(host);
        }, removeDelay);

        host.__scribeTimers = [t1, t2, t3];
        return stage;
    }

    window.showTaskStickmanScribe = showTaskStickmanScribe;
    window.showTaskCatCelebration = showTaskStickmanScribe;

    /* ----------------------------------------------------------------------
       0c. Genel Bakış (panel.php) 0.6sn Canlı Sayaçlar & Donut Halkası
       ---------------------------------------------------------------------- */
    function initDashboardCountersAndDonut() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        var counterNodes = [];
        var seen = [];
        var candidates = document.querySelectorAll('.stat-card .stat-value, .donut-percent, [data-count-target]');
        candidates.forEach(function (el) {
            if (seen.indexOf(el) !== -1) {
                return;
            }
            seen.push(el);
            var raw = el.getAttribute('data-count-target');
            var text = (el.textContent || '').trim();
            var match = text.match(/^([^\d-]*)(-?\d+)(.*)$/);
            var targetVal = raw !== null && raw !== '' ? parseInt(raw, 10) : (match ? parseInt(match[2], 10) : NaN);
            if (isNaN(targetVal) || targetVal < 0) {
                return;
            }
            var prefix = match ? match[1] : (text.indexOf('%') === 0 ? '%' : '');
            var suffix = match ? match[3] : '';
            counterNodes.push({
                el: el,
                target: targetVal,
                prefix: prefix,
                suffix: suffix,
                finalText: text || (prefix + targetVal + suffix)
            });
        });

        var donutCircles = [];
        var ringSelector = 'circle.donut-fill, circle.donut-progress, .donut-ring-fill, .donut-svg circle[stroke-dasharray]';
        document.querySelectorAll(ringSelector).forEach(function (circle) {
            var dashArray = parseFloat(circle.getAttribute('stroke-dasharray'));
            var targetOffset = parseFloat(circle.getAttribute('stroke-dashoffset'));
            if (!isNaN(dashArray) && dashArray > 0 && !isNaN(targetOffset)) {
                circle.style.transition = 'none';
                circle.style.strokeDashoffset = String(dashArray);
                donutCircles.push({
                    el: circle,
                    circumference: dashArray,
                    targetOffset: targetOffset
                });
            }
        });

        if (!counterNodes.length && !donutCircles.length) {
            return;
        }

        counterNodes.forEach(function (item) {
            item.el.textContent = item.prefix + '0' + item.suffix;
        });

        var duration = 600;
        var startTime = null;

        function step(timestamp) {
            if (startTime === null) {
                startTime = timestamp;
            }
            var elapsed = timestamp - startTime;
            var progress = Math.min(1, Math.max(0, elapsed / duration));
            var eased = 1 - Math.pow(1 - progress, 3);

            counterNodes.forEach(function (item) {
                if (progress >= 1) {
                    item.el.textContent = item.finalText;
                } else {
                    var current = Math.round(item.target * eased);
                    item.el.textContent = item.prefix + String(current) + item.suffix;
                }
            });

            donutCircles.forEach(function (ring) {
                var offsetNow = ring.circumference + (ring.targetOffset - ring.circumference) * eased;
                ring.el.style.strokeDashoffset = offsetNow.toFixed(2);
                if (progress >= 1) {
                    ring.el.style.transition = '';
                }
            });

            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        }

        window.requestAnimationFrame(step);
    }

    function applyStatusClass(el, status) {
        if (!el) {
            return;
        }
        STATUS_CLASSES.forEach(function (cls) {
            el.classList.remove(cls);
        });
        if (status && STATUS_CLASSES.indexOf('status-' + status) !== -1) {
            el.classList.add('status-' + status);
        }
    }

    function getCsrfToken(el) {
        if (el && el.getAttribute('data-csrf')) {
            return el.getAttribute('data-csrf');
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function showToast(message, type) {
        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast' + (type ? ' toast-' + type : '');
        toast.textContent = message;
        container.appendChild(toast);

        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 3400);
    }

    /* ----------------------------------------------------------------------
       1. Sol Dikey Çekmece Menü (Side Drawer)
       ---------------------------------------------------------------------- */
    function initSideDrawer() {
        var toggle = document.getElementById('drawerToggle');
        var drawer = document.getElementById('sideDrawer');
        var backdrop = document.getElementById('drawerBackdrop');
        var closeBtn = document.getElementById('drawerClose');

        if (!toggle || !drawer || !backdrop) {
            return;
        }

        function openDrawer() {
            drawer.classList.add('is-open');
            backdrop.classList.add('is-open');
            document.body.classList.add('drawer-open');
            toggle.setAttribute('aria-expanded', 'true');
            drawer.setAttribute('aria-hidden', 'false');
            backdrop.setAttribute('aria-hidden', 'false');
            if (closeBtn) {
                closeBtn.focus();
            }
        }

        function closeDrawer(returnFocus) {
            drawer.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            document.body.classList.remove('drawer-open');
            toggle.setAttribute('aria-expanded', 'false');
            drawer.setAttribute('aria-hidden', 'true');
            backdrop.setAttribute('aria-hidden', 'true');
            if (returnFocus) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            if (drawer.classList.contains('is-open')) {
                closeDrawer(false);
            } else {
                openDrawer();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closeDrawer(true);
            });
        }

        backdrop.addEventListener('click', function () {
            closeDrawer(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
                closeDrawer(true);
            }
        });
    }

    /* ----------------------------------------------------------------------
       2. Native <dialog> Modallar
       ---------------------------------------------------------------------- */
    function openDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) {
                dialog.showModal();
            }
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    function closeDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    function initModals() {
        document.addEventListener('click', function (e) {
            var openBtn = e.target.closest('[data-modal-open]');
            if (openBtn) {
                e.preventDefault();
                var targetId = openBtn.getAttribute('data-modal-open');
                openDialog(document.getElementById(targetId));
                return;
            }

            var closeBtn = e.target.closest('[data-modal-close]');
            if (closeBtn) {
                e.preventDefault();
                closeDialog(closeBtn.closest('dialog'));
                return;
            }
        });

        var dialogs = document.querySelectorAll('dialog.modal-dialog');
        dialogs.forEach(function (dialog) {
            dialog.addEventListener('click', function (e) {
                if (e.target === dialog) {
                    closeDialog(dialog);
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       3. Görev Durumu Güncelleme (AJAX)
       ---------------------------------------------------------------------- */
    function updateKanbanCounts() {
        var cols = document.querySelectorAll('.kanban-col[data-status]');
        cols.forEach(function (col) {
            var list = col.querySelector('.kanban-list');
            var countEl = col.querySelector('.kanban-count');
            if (list && countEl) {
                var cards = list.querySelectorAll('.task-card');
                var visible = 0;
                cards.forEach(function (card) {
                    if (card.style.display !== 'none') {
                        visible++;
                    }
                });
                countEl.textContent = String(visible);
            }
        });
    }

    function initTaskStatusSelects() {
        var selects = document.querySelectorAll('[data-task-status]');
        selects.forEach(function (select) {
            select.dataset.prevValue = select.value;

            select.addEventListener('change', function () {
                var taskId = select.getAttribute('data-task-id');
                var newStatus = select.value;
                var prevStatus = select.dataset.prevValue || '';
                var csrf = getCsrfToken(select);

                if (!taskId) {
                    return;
                }

                select.disabled = true;
                applyStatusClass(select, newStatus);

                fetch('api/gorev_durum.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        gorev_id: parseInt(taskId, 10),
                        durum: newStatus,
                        csrf_token: csrf
                    })
                })
                    .then(function (res) {
                        return res.json().then(function (data) {
                            return { ok: res.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                            throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Durum güncellenemedi.');
                        }

                        select.dataset.prevValue = newStatus;
                        applyStatusClass(select, newStatus);

                        var badges = document.querySelectorAll('[data-task-badge="' + taskId + '"]');
                        badges.forEach(function (badge) {
                            if (badge.classList.contains('status-pill')) {
                                applyStatusClass(badge, newStatus);
                                var labelEl = badge.querySelector('.status-pill-text');
                                if (labelEl) {
                                    labelEl.textContent = STATUS_LABELS[newStatus] || newStatus;
                                } else {
                                    badge.textContent = STATUS_LABELS[newStatus] || newStatus;
                                }
                            } else {
                                badge.className = 'badge ' + (STATUS_BADGE_CLASS[newStatus] || 'badge-yapilacak');
                                badge.textContent = STATUS_LABELS[newStatus] || newStatus;
                            }
                        });

                        var card = select.closest('.task-card');
                        var targetCol = document.querySelector('.kanban-col[data-status="' + newStatus + '"] .kanban-list');
                        if (card && targetCol && card.parentNode !== targetCol) {
                            if (document.startViewTransition && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                                document.startViewTransition(() => {
                                    targetCol.appendChild(card);
                                    updateKanbanCounts();
                                });
                            } else {
                                targetCol.appendChild(card);
                                updateKanbanCounts();
                            }
                        }

                        var filterRow = select.closest('[data-filter-item]');
                        if (filterRow) {
                            filterRow.setAttribute('data-status', newStatus);
                        }

                        if (typeof result.data.ilerleme !== 'undefined') {
                            updateLiveProgress(result.data.ilerleme);
                        }

                        if (newStatus === 'tamamlandi') {
                            showTaskStickmanScribe(card || filterRow || select);
                        }

                        showToast('Görev durumu güncellendi.', 'success');
                    })
                    .catch(function (err) {
                        select.value = prevStatus;
                        applyStatusClass(select, prevStatus);
                        showToast(err.message || 'Durum güncellenirken hata oluştu.', 'error');
                    })
                    .finally(function () {
                        select.disabled = false;
                    });
            });
        });
    }

    /* ----------------------------------------------------------------------
       4. Yorum Ekleme ve Silme (AJAX)
       ---------------------------------------------------------------------- */
    function buildCommentElement(yorum, csrfToken) {
        var item = document.createElement('div');
        item.className = 'comment-item';
        item.setAttribute('data-comment-item', String(yorum.id));

        var avatar = document.createElement('div');
        avatar.className = 'avatar' + (yorum.avatar_sinif ? ' ' + yorum.avatar_sinif : '');
        if (yorum.avatar_svg && String(yorum.avatar_svg).indexOf('<svg class="stickman-pp"') === 0) {
            // Sunucunun sabit sablonlardan urettigi cop adam SVG'si (kullanici girdisi icermez)
            avatar.innerHTML = yorum.avatar_svg;
            avatar.title = yorum.ad_soyad || '';
        } else {
            avatar.textContent = yorum.bas_harfler || 'KU';
        }

        var body = document.createElement('div');
        body.className = 'comment-body';

        var meta = document.createElement('div');
        meta.className = 'comment-meta';

        var leftMeta = document.createElement('div');
        var author = document.createElement('span');
        author.className = 'comment-author';
        author.textContent = yorum.ad_soyad || 'Kullanıcı';

        var date = document.createElement('span');
        date.className = 'comment-date';
        date.style.marginLeft = '0.5rem';
        date.textContent = yorum.tarih || 'Az önce';

        leftMeta.appendChild(author);
        leftMeta.appendChild(date);
        meta.appendChild(leftMeta);

        if (yorum.silebilir_mi !== false && yorum.silebilir !== false) {
            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'btn btn-ghost btn-sm text-danger';
            delBtn.setAttribute('data-comment-delete', '');
            delBtn.setAttribute('data-comment-id', String(yorum.id));
            delBtn.setAttribute('data-csrf', csrfToken);
            delBtn.textContent = 'Sil';
            meta.appendChild(delBtn);
        }

        var text = document.createElement('div');
        text.className = 'comment-text';
        text.textContent = yorum.icerik || '';

        body.appendChild(meta);
        body.appendChild(text);

        item.appendChild(avatar);
        item.appendChild(body);
        return item;
    }

    function updateCommentCount(delta) {
        var countEl = document.querySelector('[data-comment-count]');
        if (!countEl) {
            return;
        }
        var current = parseInt(countEl.textContent, 10) || 0;
        countEl.textContent = String(Math.max(0, current + delta));
    }

    function initComments() {
        var form = document.querySelector('[data-comment-form]');
        var list = document.querySelector('[data-comment-list]');

        if (form && list) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var textarea = form.querySelector('textarea[name="icerik"]');
                var submitBtn = form.querySelector('button[type="submit"]');
                var content = textarea ? textarea.value.trim() : '';

                if (content.length < 2) {
                    showToast('Lütfen en az 2 karakterlik bir yorum yazın.', 'error');
                    if (textarea) {
                        textarea.focus();
                    }
                    return;
                }

                var formData = new FormData(form);
                if (!formData.get('action')) {
                    formData.append('action', 'ekle');
                }
                var csrf = formData.get('csrf_token') || getCsrfToken(form);

                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                fetch('api/yorum_ekle.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                    .then(function (res) {
                        return res.json().then(function (data) {
                            return { ok: res.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                            throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Yorum eklenemedi.');
                        }

                        var empty = list.querySelector('.empty-state');
                        if (empty) {
                            empty.parentNode.removeChild(empty);
                        }

                        var yorum = result.data.yorum || {
                            id: result.data.id || Date.now(),
                            ad_soyad: result.data.ad_soyad || 'Siz',
                            bas_harfler: result.data.bas_harfler || 'KU',
                            icerik: content,
                            tarih: result.data.tarih || 'Az önce',
                            silebilir: true
                        };

                        list.appendChild(buildCommentElement(yorum, csrf));
                        updateCommentCount(1);
                        if (textarea) {
                            textarea.value = '';
                        }
                        showToast('Yorum eklendi.', 'success');
                    })
                    .catch(function (err) {
                        showToast(err.message || 'Yorum gönderilirken hata oluştu.', 'error');
                    })
                    .finally(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    });
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-comment-delete]');
            if (!btn) {
                return;
            }

            e.preventDefault();
            if (!window.confirm('Bu yorumu silmek istediğinize emin misiniz?')) {
                return;
            }

            var commentId = btn.getAttribute('data-comment-id');
            var csrf = getCsrfToken(btn);
            if (!commentId) {
                return;
            }

            btn.disabled = true;

            var body = new FormData();
            body.append('action', 'sil');
            body.append('yorum_id', commentId);
            body.append('csrf_token', csrf);

            fetch('api/yorum_ekle.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                        throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Yorum silinemedi.');
                    }

                    var item = btn.closest('.comment-item');
                    if (item && item.parentNode) {
                        item.parentNode.removeChild(item);
                    }
                    updateCommentCount(-1);
                    showToast('Yorum silindi.', 'success');
                })
                .catch(function (err) {
                    btn.disabled = false;
                    showToast(err.message || 'Yorum silinirken hata oluştu.', 'error');
                });
        });
    }

    /* ----------------------------------------------------------------------
       5. İstemci Tarafı Anlık Arama ve Filtreleme
       ---------------------------------------------------------------------- */
    function initClientFilters() {
        var searchInput = document.querySelector('[data-filter-search]');
        var statusSelect = document.querySelector('[data-filter-status]');
        var prioritySelect = document.querySelector('[data-filter-priority]');
        var unitSelect = document.querySelector('[data-filter-unit]');
        var unitButtons = document.querySelectorAll('[data-unit-filter]');
        var items = document.querySelectorAll('[data-filter-item]');
        var emptyEl = document.querySelector('[data-filter-empty]');

        if (!items.length || (!searchInput && !statusSelect && !prioritySelect && !unitSelect && !unitButtons.length)) {
            return;
        }

        var activeUnit = unitSelect ? unitSelect.value : '';
        if (!activeUnit && unitButtons.length) {
            unitButtons.forEach(function (btn) {
                if (btn.classList.contains('is-active')) {
                    activeUnit = btn.getAttribute('data-unit-filter') || '';
                }
            });
        }

        function applyClientFilters() {
            var q = searchInput ? searchInput.value.trim().toLocaleLowerCase('tr-TR') : '';
            var st = statusSelect ? statusSelect.value : '';
            var pr = prioritySelect ? prioritySelect.value : '';
            var un = unitSelect ? unitSelect.value : activeUnit;

            var updateDom = function () {
                var visibleCount = 0;

                items.forEach(function (item) {
                    var text = (item.getAttribute('data-search-text') || item.textContent || '').toLocaleLowerCase('tr-TR');
                    var itemStatus = item.getAttribute('data-status') || '';
                    var itemPriority = item.getAttribute('data-priority') || '';
                    var itemUnit = item.getAttribute('data-unit') || '';

                    var matchSearch = !q || text.indexOf(q) !== -1;
                    var matchStatus = !st || itemStatus === st;
                    var matchPriority = !pr || itemPriority === pr;
                    var matchUnit = !un || itemUnit === un;

                    var show = matchSearch && matchStatus && matchPriority && matchUnit;
                    item.style.display = show ? '' : 'none';
                    if (show) {
                        visibleCount++;
                    }
                });

                if (emptyEl) {
                    emptyEl.style.display = visibleCount === 0 ? '' : 'none';
                }

                if (document.querySelector('.kanban-col[data-status]')) {
                    updateKanbanCounts();
                }
            };

            if (document.startViewTransition && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.startViewTransition(() => {
                    updateDom();
                });
            } else {
                updateDom();
            }
        }

        function initUnitFilterBar() {
            if (!unitButtons.length) {
                return;
            }
            unitButtons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    var bar = btn.closest('[data-server-filtered="1"]');
                    if (bar) {
                        return;
                    }
                    e.preventDefault();
                    activeUnit = btn.getAttribute('data-unit-filter') || '';
                    unitButtons.forEach(function (other) {
                        var isAct = other === btn;
                        other.classList.toggle('is-active', isAct);
                        other.setAttribute('aria-pressed', isAct ? 'true' : 'false');
                    });
                    if (window.history && typeof window.history.replaceState === 'function') {
                        var href = btn.getAttribute('href');
                        if (href) {
                            window.history.replaceState(null, '', href);
                        }
                    }
                    applyClientFilters();
                });
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyClientFilters);
        }
        if (statusSelect) {
            statusSelect.addEventListener('change', applyClientFilters);
        }
        if (prioritySelect) {
            prioritySelect.addEventListener('change', applyClientFilters);
        }
        if (unitSelect) {
            unitSelect.addEventListener('change', function () {
                activeUnit = unitSelect.value;
                applyClientFilters();
            });
        }
        initUnitFilterBar();

        if (activeUnit) {
            applyClientFilters();
        }
    }

    /* ----------------------------------------------------------------------
       6. Form Doğrulama ve Silme Onayı
       ---------------------------------------------------------------------- */
    function setFieldError(input, message) {
        var group = input.closest('.form-group');
        if (!group) {
            return;
        }
        var existing = group.querySelector('.form-error[data-js-error]');
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        if (message) {
            input.classList.add('is-invalid');
            var err = document.createElement('span');
            err.className = 'form-error';
            err.setAttribute('data-js-error', '1');
            err.textContent = message;
            group.appendChild(err);
        } else {
            input.classList.remove('is-invalid');
        }
    }

    function initFormValidation() {
        var forms = document.querySelectorAll('form[data-validate-form]');
        forms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var valid = true;
                var firstInvalid = null;

                var requiredInputs = form.querySelectorAll('[required]');
                requiredInputs.forEach(function (input) {
                    var val = input.value.trim();
                    if (!val) {
                        setFieldError(input, 'Bu alan boş bırakılamaz.');
                        valid = false;
                        if (!firstInvalid) {
                            firstInvalid = input;
                        }
                    } else {
                        setFieldError(input, '');
                    }
                });

                var minLengthInputs = form.querySelectorAll('[data-min-length]');
                minLengthInputs.forEach(function (input) {
                    var minLen = parseInt(input.getAttribute('data-min-length'), 10) || 6;
                    var val = input.value;
                    if (val.length > 0 && val.length < minLen) {
                        setFieldError(input, 'En az ' + minLen + ' karakter girilmelidir.');
                        valid = false;
                        if (!firstInvalid) {
                            firstInvalid = input;
                        }
                    }
                });

                var passSource = form.querySelector('[data-password-source]');
                var passConfirm = form.querySelector('[data-password-confirm]');
                if (passSource && passConfirm && passConfirm.value !== passSource.value) {
                    setFieldError(passConfirm, 'Şifreler birbiriyle eşleşmiyor.');
                    valid = false;
                    if (!firstInvalid) {
                        firstInvalid = passConfirm;
                    }
                }

                if (!valid) {
                    e.preventDefault();
                    if (firstInvalid) {
                        firstInvalid.focus();
                    }
                }
            });
        });

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form && form.hasAttribute('data-confirm')) {
                var msg = form.getAttribute('data-confirm') || 'Bu işlemi onaylıyor musunuz?';
                if (!window.confirm(msg)) {
                    e.preventDefault();
                }
            }
        });

        document.addEventListener('click', function (e) {
            var el = e.target.closest('a[data-confirm], button[data-confirm]');
            if (el) {
                var msg = el.getAttribute('data-confirm') || 'Bu işlemi onaylıyor musunuz?';
                if (!window.confirm(msg)) {
                    e.preventDefault();
                }
            }
        });
    }

    /* ----------------------------------------------------------------------
       7. PDF Rapor Yazdırma, Panoya Kopyalama ve Dosya Yükleme Kontrolü
       ---------------------------------------------------------------------- */
    function initPrintReport() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-print-report]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            window.print();
        });
    }

    function fallbackCopyText(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.top = '-9999px';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (err) {
            copied = false;
        }
        document.body.removeChild(area);
        return copied;
    }

    function initCopyButtons() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy-text]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            var text = btn.getAttribute('data-copy-text') || '';
            if (!text) {
                return;
            }

            var originalLabel = btn.getAttribute('data-original-label') || btn.textContent;
            btn.setAttribute('data-original-label', originalLabel);

            function onCopied() {
                btn.textContent = 'Kopyalandı';
                showToast('Panoya kopyalandı.', 'success');
                window.setTimeout(function () {
                    btn.textContent = originalLabel;
                }, 1800);
            }

            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(text).then(onCopied).catch(function () {
                    if (fallbackCopyText(text)) {
                        onCopied();
                    } else {
                        showToast('Kopyalama başarısız oldu.', 'error');
                    }
                });
            } else if (fallbackCopyText(text)) {
                onCopied();
            } else {
                showToast('Kopyalama desteklenmiyor.', 'error');
            }
        });
    }

    function initFileUploadValidation() {
        var allowedExt = ['pdf', 'png', 'jpg', 'jpeg'];
        var defaultMaxBytes = 8 * 1024 * 1024;

        function validateFileInput(input) {
            if (!input || !input.files || !input.files.length) {
                setFieldError(input, '');
                return true;
            }

            var file = input.files[0];
            var maxBytes = parseInt(input.getAttribute('data-max-size'), 10) || defaultMaxBytes;
            var name = file.name || '';
            var dotIdx = name.lastIndexOf('.');
            var ext = dotIdx !== -1 ? name.slice(dotIdx + 1).toLowerCase() : '';

            if (allowedExt.indexOf(ext) === -1) {
                var extMsg = 'Yalnızca PDF, PNG, JPG veya JPEG dosyaları yüklenebilir.';
                setFieldError(input, extMsg);
                showToast(extMsg, 'error');
                return false;
            }

            if (file.size > maxBytes) {
                var sizeMsg = 'Dosya boyutu en fazla 8 MB olabilir.';
                setFieldError(input, sizeMsg);
                showToast(sizeMsg, 'error');
                return false;
            }

            setFieldError(input, '');
            return true;
        }

        var fileInputs = document.querySelectorAll('input[type="file"][data-file-upload]');
        fileInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                validateFileInput(input);
            });

            var form = input.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!validateFileInput(input)) {
                        e.preventDefault();
                        input.focus();
                    }
                });
            }
        });
    }

    /* ----------------------------------------------------------------------
       8. Bildirim Çanı (api/bildirimler.php)
       ---------------------------------------------------------------------- */
    function initNotifications() {
        var toggle = document.getElementById('notifToggle');
        var panel = document.getElementById('notifPanel');
        var list = document.getElementById('notifList');
        var badge = document.getElementById('notifBadge');
        var readAllBtn = document.querySelector('[data-notif-read-all]');

        if (!toggle || !panel || !list) {
            return;
        }

        var loaded = false;

        function setBadgeCount(count) {
            if (!badge) {
                return;
            }
            var n = parseInt(count, 10) || 0;
            if (n > 0) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.hidden = false;
            } else {
                badge.textContent = '0';
                badge.hidden = true;
            }
        }

        function renderNotifications(items) {
            list.innerHTML = '';
            if (!items || !items.length) {
                var empty = document.createElement('div');
                empty.className = 'notif-empty';
                empty.textContent = 'Henüz yeni bir bildiriminiz yok.';
                list.appendChild(empty);
                return;
            }

            items.forEach(function (item) {
                var link = document.createElement('a');
                link.className = 'notif-item' + (Number(item.okundu) === 0 ? ' is-unread' : '');
                link.href = item.link || '#';
                link.setAttribute('data-notif-id', String(item.id));

                var dot = document.createElement('span');
                dot.className = 'notif-dot';
                dot.setAttribute('aria-hidden', 'true');

                var body = document.createElement('div');
                body.className = 'notif-item-body';

                var msg = document.createElement('div');
                msg.className = 'notif-item-msg';
                msg.textContent = item.mesaj || '';

                var time = document.createElement('div');
                time.className = 'notif-item-time';
                time.textContent = item.tarih || item.olusturma_tarihi || '';

                body.appendChild(msg);
                body.appendChild(time);
                link.appendChild(dot);
                link.appendChild(body);

                link.addEventListener('click', function () {
                    if (link.classList.contains('is-unread')) {
                        markAsRead(item.id, false);
                    }
                });

                list.appendChild(link);
            });
        }

        function fetchNotifications() {
            fetch('api/bildirimler.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (!data || data.basarili === false || data.success === false) {
                        throw new Error('Bildirimler alınamadı.');
                    }
                    var items = data.bildirimler || data.items || [];
                    var unread = typeof data.okunmamis_sayisi !== 'undefined'
                        ? data.okunmamis_sayisi
                        : (typeof data.okunmamis !== 'undefined' ? data.okunmamis : data.unread_count);
                    if (typeof unread !== 'undefined') {
                        setBadgeCount(unread);
                    }
                    renderNotifications(items);
                    loaded = true;
                })
                .catch(function () {
                    list.innerHTML = '<div class="notif-empty">Bildirimler şu an yüklenemedi.</div>';
                });
        }

        function markAsRead(id, all) {
            var csrf = getCsrfToken(toggle);
            var body = new FormData();
            body.append('csrf_token', csrf);
            body.append('action', 'okundu');
            if (all) {
                body.append('tumu', '1');
            } else if (id) {
                body.append('id', String(id));
            }

            fetch('api/bildirimler.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (data && data.basarili !== false && data.success !== false) {
                        if (all) {
                            list.querySelectorAll('.notif-item.is-unread').forEach(function (el) {
                                el.classList.remove('is-unread');
                            });
                            setBadgeCount(0);
                            showToast('Tüm bildirimler okundu işaretlendi.', 'success');
                        } else if (typeof data.okunmamis_sayisi !== 'undefined') {
                            setBadgeCount(data.okunmamis_sayisi);
                        } else if (typeof data.okunmamis !== 'undefined') {
                            setBadgeCount(data.okunmamis);
                        }
                    }
                })
                .catch(function () {
                    // Sessizce yoksay
                });
        }

        function closePanel() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !panel.hidden;
            if (isOpen) {
                closePanel();
            } else {
                panel.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                if (!loaded) {
                    fetchNotifications();
                } else {
                    fetchNotifications();
                }
            }
        });

        if (readAllBtn) {
            readAllBtn.addEventListener('click', function (e) {
                e.preventDefault();
                markAsRead(null, true);
            });
        }

        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target) && !toggle.contains(e.target)) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) {
                closePanel();
            }
        });
    }

    /* ----------------------------------------------------------------------
       9. Haftalık Alt Görevler (api/alt_gorev.php) + Canlı İlerleme %
       ---------------------------------------------------------------------- */
    function updateLiveProgress(pct, doneCount, totalCount) {
        var bars = document.querySelectorAll('[data-progress-bar]');
        var texts = document.querySelectorAll('[data-progress-text]');
        var summaries = document.querySelectorAll('[data-subtask-summary]');
        var safePct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));

        bars.forEach(function (bar) {
            bar.style.width = safePct + '%';
            bar.classList.toggle('is-complete', safePct >= 100);
        });
        texts.forEach(function (text) {
            text.textContent = '%' + safePct;
        });
        if (typeof doneCount !== 'undefined' && typeof totalCount !== 'undefined') {
            summaries.forEach(function (summary) {
                summary.textContent = doneCount + '/' + totalCount + ' tamamlandı';
            });
        }
    }

    function syncTaskStatusUI(newStatus) {
        if (!newStatus) {
            return;
        }
        var detailSelect = document.getElementById('detay-durum-sec') || document.querySelector('select.status-select[data-task-status]');
        if (detailSelect) {
            detailSelect.value = newStatus;
            detailSelect.dataset.prevValue = newStatus;
            applyStatusClass(detailSelect, newStatus);
        }
        var badges = document.querySelectorAll('[data-task-badge]');
        badges.forEach(function (badge) {
            if (badge.classList.contains('status-pill')) {
                applyStatusClass(badge, newStatus);
                var labelEl = badge.querySelector('.status-pill-text');
                if (labelEl) {
                    labelEl.textContent = STATUS_LABELS[newStatus] || newStatus;
                } else {
                    badge.textContent = STATUS_LABELS[newStatus] || newStatus;
                }
            } else {
                badge.className = 'badge ' + (STATUS_BADGE_CLASS[newStatus] || 'badge-yapilacak');
                badge.textContent = STATUS_LABELS[newStatus] || newStatus;
            }
        });
    }

    function updateWeekGroupCounts() {
        document.querySelectorAll('.week-group').forEach(function (group) {
            var items = group.querySelectorAll('.subtask-item');
            var done = group.querySelectorAll('.subtask-item.is-done');
            var badge = group.querySelector('.week-group-badge');
            if (badge) {
                badge.textContent = done.length + '/' + items.length;
            }
            if (items.length === 0 && group.parentNode) {
                group.parentNode.removeChild(group);
            }
        });
    }

    function buildSubtaskRow(item, csrf) {
        var row = document.createElement('div');
        row.className = 'subtask-item' + (Number(item.tamamlandi) === 1 ? ' is-done' : '');
        row.setAttribute('data-subtask-item', String(item.id));

        var left = document.createElement('label');
        left.className = 'subtask-left';

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'subtask-checkbox';
        cb.checked = Number(item.tamamlandi) === 1;
        cb.setAttribute('data-subtask-toggle', String(item.id));
        cb.setAttribute('data-csrf', csrf);
        if (item.degistirebilir_mi === false) {
            cb.disabled = true;
            cb.title = 'Yalnızca atanan kişi veya ekip lideri işaretleyebilir';
        }

        var title = document.createElement('span');
        title.className = 'subtask-title';
        title.textContent = item.baslik || '';

        left.appendChild(cb);
        left.appendChild(title);

        var right = document.createElement('div');
        right.className = 'subtask-right';

        var atananAd = item.atanan_adi || item.atanan_ad || '';
        if (atananAd) {
            var assignee = document.createElement('span');
            assignee.className = 'assignee-cell';
            assignee.title = atananAd;

            var av = document.createElement('span');
            av.className = 'avatar-xs' + (item.atanan_avatar_sinif ? ' ' + item.atanan_avatar_sinif : '');
            if (item.atanan_avatar_svg && String(item.atanan_avatar_svg).indexOf('<svg class="stickman-pp"') === 0) {
                av.innerHTML = item.atanan_avatar_svg;
            } else {
                av.textContent = item.atanan_bas_harfler || item.atanan_bas_harf || atananAd.slice(0, 2).toUpperCase();
            }

            var nm = document.createElement('span');
            nm.textContent = atananAd;

            assignee.appendChild(av);
            assignee.appendChild(nm);
            right.appendChild(assignee);
        }

        if (item.son_tarih_format || item.son_tarih) {
            var dt = document.createElement('span');
            dt.className = 'date-muted';
            dt.textContent = item.son_tarih_format || item.son_tarih;
            right.appendChild(dt);
        }

        if (item.silebilir_mi !== false && item.silebilir !== false) {
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'subtask-delete-btn';
            del.setAttribute('data-subtask-delete', String(item.id));
            del.setAttribute('data-csrf', csrf);
            del.textContent = 'Sil';
            right.appendChild(del);
        }

        row.appendChild(left);
        row.appendChild(right);
        return row;
    }

    function initSubtasks() {
        var container = document.querySelector('[data-week-groups]');
        var addForm = document.querySelector('[data-subtask-form]');
        var weekSelect = document.querySelector('[data-week-select]');
        var weekCustom = document.querySelector('[data-week-custom]');

        if (weekSelect && weekCustom) {
            weekSelect.addEventListener('change', function () {
                if (weekSelect.value === '__ozel__') {
                    weekCustom.hidden = false;
                    weekCustom.required = true;
                    weekCustom.focus();
                } else {
                    weekCustom.hidden = true;
                    weekCustom.required = false;
                    weekCustom.value = '';
                }
            });
        }

        if (addForm && container) {
            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var submitBtn = addForm.querySelector('button[type="submit"]');
                var titleInput = addForm.querySelector('input[name="baslik"]');
                var titleVal = titleInput ? titleInput.value.trim() : '';

                if (titleVal.length < 2) {
                    showToast('Alt görev başlığı en az 2 karakter olmalıdır.', 'error');
                    if (titleInput) {
                        titleInput.focus();
                    }
                    return;
                }

                var formData = new FormData(addForm);
                if (weekSelect && weekSelect.value === '__ozel__' && weekCustom && weekCustom.value.trim()) {
                    formData.set('hafta_etiketi', weekCustom.value.trim());
                }
                formData.set('action', 'ekle');
                var csrf = formData.get('csrf_token') || getCsrfToken(addForm);

                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                fetch('api/alt_gorev.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                    .then(function (res) {
                        return res.json().then(function (data) {
                            return { ok: res.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                            throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Alt görev eklenemedi.');
                        }

                        var empty = container.querySelector('.empty-state');
                        if (empty && empty.parentNode) {
                            empty.parentNode.removeChild(empty);
                        }

                        var item = result.data.alt_gorev || {
                            id: result.data.id || Date.now(),
                            hafta_etiketi: formData.get('hafta_etiketi') || 'Hafta 1',
                            baslik: titleVal,
                            tamamlandi: 0
                        };
                        var weekLabel = item.hafta_etiketi || 'Hafta 1';

                        var group = null;
                        container.querySelectorAll('.week-group').forEach(function (g) {
                            if (g.getAttribute('data-week-label') === weekLabel) {
                                group = g;
                            }
                        });

                        if (!group) {
                            group = document.createElement('div');
                            group.className = 'week-group';
                            group.setAttribute('data-week-label', weekLabel);

                            var head = document.createElement('div');
                            head.className = 'week-group-header';

                            var gTitle = document.createElement('div');
                            gTitle.className = 'week-group-title';
                            gTitle.textContent = weekLabel;

                            var gBadge = document.createElement('span');
                            gBadge.className = 'week-group-badge';
                            gBadge.textContent = '0/0';

                            head.appendChild(gTitle);
                            head.appendChild(gBadge);

                            var listEl = document.createElement('div');
                            listEl.className = 'subtask-list';

                            group.appendChild(head);
                            group.appendChild(listEl);
                            container.appendChild(group);
                        }

                        var listNode = group.querySelector('.subtask-list');
                        if (listNode) {
                            listNode.appendChild(buildSubtaskRow(item, csrf));
                        }

                        updateWeekGroupCounts();
                        if (typeof result.data.ilerleme !== 'undefined') {
                            var doneC = typeof result.data.alt_tamamlanan !== 'undefined' ? result.data.alt_tamamlanan : result.data.tamamlanan;
                            var totC = typeof result.data.alt_toplam !== 'undefined' ? result.data.alt_toplam : result.data.toplam;
                            updateLiveProgress(result.data.ilerleme, doneC, totC);
                        }
                        if (result.data.gorev_durum) {
                            syncTaskStatusUI(result.data.gorev_durum);
                        }

                        if (titleInput) {
                            titleInput.value = '';
                        }
                        showToast('Haftalık alt görev eklendi.', 'success');
                    })
                    .catch(function (err) {
                        showToast(err.message || 'Alt görev eklenirken hata oluştu.', 'error');
                    })
                    .finally(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    });
            });
        }

        document.addEventListener('change', function (e) {
            var cb = e.target.closest('[data-subtask-toggle]');
            if (!cb) {
                return;
            }
            var id = cb.getAttribute('data-subtask-toggle');
            var checked = cb.checked ? 1 : 0;
            var csrf = getCsrfToken(cb);
            var row = cb.closest('.subtask-item');

            cb.disabled = true;
            var body = new FormData();
            body.append('action', 'durum');
            body.append('id', id);
            body.append('alt_gorev_id', id);
            body.append('tamamlandi', String(checked));
            body.append('csrf_token', csrf);

            fetch('api/alt_gorev.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                        throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Durum güncellenemedi.');
                    }
                    if (row) {
                        row.classList.toggle('is-done', checked === 1);
                    }
                    updateWeekGroupCounts();
                    if (typeof result.data.ilerleme !== 'undefined') {
                        var dCount = typeof result.data.alt_tamamlanan !== 'undefined' ? result.data.alt_tamamlanan : result.data.tamamlanan;
                        var tCount = typeof result.data.alt_toplam !== 'undefined' ? result.data.alt_toplam : result.data.toplam;
                        updateLiveProgress(result.data.ilerleme, dCount, tCount);
                    }
                    if (result.data.gorev_durum) {
                        syncTaskStatusUI(result.data.gorev_durum);
                    }
                    if (checked === 1) {
                        showTaskStickmanScribe(row || cb);
                    }
                })
                .catch(function (err) {
                    cb.checked = !cb.checked;
                    showToast(err.message || 'Alt görev güncellenemedi.', 'error');
                })
                .finally(function () {
                    cb.disabled = false;
                });
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-subtask-delete]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            if (!window.confirm('Bu alt görevi silmek istediğinize emin misiniz?')) {
                return;
            }

            var id = btn.getAttribute('data-subtask-delete');
            var csrf = getCsrfToken(btn);
            btn.disabled = true;

            var body = new FormData();
            body.append('action', 'sil');
            body.append('id', id);
            body.append('alt_gorev_id', id);
            body.append('csrf_token', csrf);

            fetch('api/alt_gorev.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data || result.data.basarili === false || result.data.success === false) {
                        throw new Error((result.data && (result.data.mesaj || result.data.error)) || 'Alt görev silinemedi.');
                    }
                    var row = btn.closest('.subtask-item');
                    if (row && row.parentNode) {
                        row.parentNode.removeChild(row);
                    }
                    updateWeekGroupCounts();
                    if (typeof result.data.ilerleme !== 'undefined') {
                        var dCount = typeof result.data.alt_tamamlanan !== 'undefined' ? result.data.alt_tamamlanan : result.data.tamamlanan;
                        var tCount = typeof result.data.alt_toplam !== 'undefined' ? result.data.alt_toplam : result.data.toplam;
                        updateLiveProgress(result.data.ilerleme, dCount, tCount);
                    }
                    if (result.data.gorev_durum) {
                        syncTaskStatusUI(result.data.gorev_durum);
                    }
                    showToast('Alt görev silindi.', 'success');
                })
                .catch(function (err) {
                    btn.disabled = false;
                    showToast(err.message || 'Alt görev silinirken hata oluştu.', 'error');
                });
        });
    }

    /* ----------------------------------------------------------------------
       10. Sekme Geçişleri (proje_detay.php) ve Sürükle-Bırak Dosya Seçici
       ---------------------------------------------------------------------- */
    function initTabs() {
        var tabButtons = document.querySelectorAll('[data-tab-target]');
        var tabPanels = document.querySelectorAll('[data-tab-panel]');
        if (!tabButtons.length || !tabPanels.length) {
            return;
        }

        function activateTab(targetName) {
            tabButtons.forEach(function (btn) {
                var isAct = btn.getAttribute('data-tab-target') === targetName;
                btn.classList.toggle('active', isAct);
                btn.setAttribute('aria-selected', isAct ? 'true' : 'false');
            });
            tabPanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-tab-panel') !== targetName;
            });
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var target = btn.getAttribute('data-tab-target');
                if (target) {
                    activateTab(target);
                }
            });
        });
    }

    function initDropzones() {
        var zones = document.querySelectorAll('[data-dropzone]');
        zones.forEach(function (zone) {
            var input = zone.querySelector('input[type="file"]');
            var nameEl = zone.querySelector('.dropzone-filename');
            if (!input) {
                return;
            }

            input.addEventListener('change', function () {
                if (nameEl) {
                    nameEl.textContent = (input.files && input.files.length) ? input.files[0].name : '';
                }
            });

            ['dragenter', 'dragover'].forEach(function (evt) {
                zone.addEventListener(evt, function () {
                    zone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function (evt) {
                zone.addEventListener(evt, function () {
                    zone.classList.remove('is-dragover');
                });
            });
        });
    }

    function initAutoOpenModal() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('yeni') === '1') {
            var modal = document.getElementById('modal-yeni-gorev') || document.getElementById('yeniGorevModal');
            if (modal) {
                openDialog(modal);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTopProgressBar();
        initDashboardCountersAndDonut();
        initSideDrawer();
        initModals();
        initTaskStatusSelects();
        initComments();
        initClientFilters();
        initFormValidation();
        initPrintReport();
        initCopyButtons();
        initFileUploadValidation();
        initNotifications();
        initSubtasks();
        initTabs();
        initDropzones();
        initAutoOpenModal();
    });
})();
