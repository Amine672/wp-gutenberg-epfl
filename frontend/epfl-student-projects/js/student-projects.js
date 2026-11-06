/**
 * Front-end behaviour of the "EPFL Student Projects" block (Project Portal source):
 * search, sort, level and keyword filters, pagination, project details modal,
 * and keeping that state in the page URL (so it can be shared / survives going back to the page).
 *
 * This code lives in its own file instead of an inline <script> in the block output, because WordPress runs
 * wptexturize on post content, which turned "&&" into "&#038;&#038;" and broke the inline script.
 */
(function () {
    function initStudentProjects(root, index) {
        if (root.getAttribute('data-sp-ready') === '1') return;
        root.setAttribute('data-sp-ready', '1');

        var grid = root.querySelector('.epfl-sp-grid');
        var items = Array.prototype.slice.call(root.querySelectorAll('.epfl-sp-item'));
        var searchInput = root.querySelector('.epfl-sp-search');
        var countEl = root.querySelector('.epfl-sp-count');
        var countLabelEl = root.querySelector('.epfl-sp-count-label');
        var emptyEl = root.querySelector('.epfl-sp-empty');
        var sortBtns = Array.prototype.slice.call(root.querySelectorAll('.epfl-sp-sort'));
        var levelBtns = Array.prototype.slice.call(root.querySelectorAll('.epfl-sp-level'));
        var activeLevels = []; // empty = all levels
        var activeKeyword = ''; // '' = no keyword filter
        var currentSort = 'title';

        var keywordBox = root.querySelector('.epfl-sp-keyword-filter');
        var keywordName = keywordBox ? keywordBox.querySelector('.epfl-sp-keyword-name') : null;
        var keywordClear = keywordBox ? keywordBox.querySelector('.epfl-sp-keyword-clear') : null;

        // URL parameters prefix: "sp_" for the first block of the page, "sp2_", "sp3_"... for the next ones
        var urlPrefix = index ? 'sp' + (index + 1) + '_' : 'sp_';

        var pager = root.querySelector('.epfl-sp-pagination');
        var pagerList = pager ? pager.querySelector('.pagination') : null;
        var rangeEl = pager ? pager.querySelector('.epfl-sp-range') : null;
        var pageSize = pager ? (parseInt(pager.dataset.pageSize, 10) || 30) : Infinity;
        var currentPage = 1;

        var modal = root.querySelector('.epfl-sp-modal');
        var modalTitle = modal.querySelector('.epfl-sp-modal-title');
        var modalBadges = modal.querySelector('.epfl-sp-modal-badges');
        var modalBody = modal.querySelector('.epfl-sp-modal-body');
        var modalView = modal.querySelector('.epfl-sp-modal-view');
        var modalMeta = modal.querySelector('.epfl-sp-modal-meta');
        var modalClosers = Array.prototype.slice.call(modal.querySelectorAll('.epfl-sp-modal-close'));
        var backdrop = null;
        var lastFocused = null;

        function matches(item, q){
            var matchesSearch = !q || (item.dataset.search || '').indexOf(q) !== -1;
            var itemLevels = (item.dataset.level || '').split(',');
            // A project matches if it has at least one of the selected levels
            var matchesLevel = activeLevels.length === 0 || activeLevels.some(function(l){ return itemLevels.indexOf(l) !== -1; });
            var matchesKeyword = !activeKeyword || ('|' + (item.dataset.keywords || '') + '|').indexOf('|' + activeKeyword.toLowerCase() + '|') !== -1;
            return matchesSearch && matchesLevel && matchesKeyword;
        }

        /**
         * Shows the projects matching the search / level filters, one page at a time.
         * keepPage: true when only the page changes; otherwise (new search, filter or sort) go back to page 1.
         */
        function apply(keepPage){
            if (!keepPage) currentPage = 1;
            var q = (searchInput.value || '').toLowerCase().trim();
            // Current DOM order = current sort order
            var ordered = Array.prototype.filter.call(grid.children, function(el){ return el.classList.contains('epfl-sp-item'); });
            var matching = ordered.filter(function(item){ return matches(item, q); });

            var total = matching.length;
            var pages = Math.max(1, Math.ceil(total / pageSize));
            currentPage = Math.min(Math.max(1, currentPage), pages);
            var start = (currentPage - 1) * pageSize;
            var end = Math.min(start + pageSize, total);

            items.forEach(function(item){ item.style.display = 'none'; });
            for (var i = start; i < end; i++) matching[i].style.display = '';

            if (countEl) countEl.textContent = total;
            if (countLabelEl) countLabelEl.textContent = total === 1 ? countLabelEl.dataset.labelOne : countLabelEl.dataset.labelMany;
            if (emptyEl) emptyEl.style.display = total === 0 ? '' : 'none';
            renderPager(total, pages, start, end);
            renderKeyword();
            writeUrl();
        }

        function renderKeyword(){
            if (!keywordBox) return;
            keywordBox.style.display = activeKeyword ? '' : 'none';
            if (keywordName) keywordName.textContent = activeKeyword;
            if (keywordClear) keywordClear.setAttribute('aria-label', format(keywordClear.dataset.labelRemove || '%s', [activeKeyword]));
        }

        // --- State <-> URL (e.g. ?sp_level=Master,Bachelor&sp_page=2), so a filtered list can be shared,
        // and the filters are still there when coming back to the page.
        function writeUrl(){
            if (!window.history || !window.history.replaceState || !window.URL) return;
            var url = new URL(window.location.href);
            var params = {
                q: (searchInput.value || '').trim(),
                level: activeLevels.join(','),
                kw: activeKeyword,
                sort: currentSort !== 'title' ? currentSort : '',
                page: currentPage > 1 ? String(currentPage) : ''
            };
            Object.keys(params).forEach(function(k){
                if (params[k]) url.searchParams.set(urlPrefix + k, params[k]);
                else url.searchParams.delete(urlPrefix + k);
            });
            if (url.href !== window.location.href) {
                window.history.replaceState(window.history.state, '', url.href);
            }
        }

        function readUrl(){
            if (!window.URLSearchParams) return;
            var params = new URLSearchParams(window.location.search);
            var get = function(k){ return params.get(urlPrefix + k) || ''; };

            var sort = get('sort');
            if (sort && sortBtns.some(function(b){ return b.dataset.sort === sort; })) {
                currentSort = sort;
                sortBtns.forEach(function(b){ b.classList.toggle('active', b.dataset.sort === sort); });
                sortItems(sort);
            }
            var knownLevels = levelBtns.map(function(b){ return b.dataset.level; });
            activeLevels = get('level').split(',').filter(function(l){ return l && l !== 'all' && knownLevels.indexOf(l) !== -1; });
            activeKeyword = get('kw');
            searchInput.value = get('q');
            currentPage = parseInt(get('page'), 10) || 1;
        }

        function format(label, values){
            var i = 0;
            return label.replace(/%(?:(\d)\$)?[sd]/g, function(m, pos){
                return String(values[pos ? parseInt(pos, 10) - 1 : i++]);
            });
        }

        // Page numbers to display: all of them when there are few pages, otherwise
        // first, last and the pages around the current one, with "…" in between.
        function pageNumbers(current, pages){
            if (pages <= 7) {
                var all = [];
                for (var p = 1; p <= pages; p++) all.push(p);
                return all;
            }
            var list = [1];
            var from = Math.max(2, current - 1);
            var to = Math.min(pages - 1, current + 1);
            if (current <= 3) { from = 2; to = 4; }
            if (current >= pages - 2) { from = pages - 3; to = pages - 1; }
            // Only use "…" when it hides at least 2 pages, otherwise show the page itself
            if (from === 3) list.push(2); else if (from > 3) list.push('…');
            for (var n = from; n <= to; n++) list.push(n);
            if (to === pages - 2) list.push(pages - 1); else if (to < pages - 2) list.push('…');
            list.push(pages);
            return list;
        }

        function pageItem(label, page, opts){
            var li = document.createElement('li');
            li.className = 'page-item' + (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
            var el;
            if (opts.disabled || opts.active || page === null) {
                el = document.createElement('span');
                if (opts.active) el.setAttribute('aria-current', 'page');
                if (opts.disabled) el.setAttribute('aria-disabled', 'true');
            } else {
                el = document.createElement('a');
                el.href = '#';
                el.setAttribute('data-page', page);
            }
            el.className = 'page-link';
            el.textContent = label;
            if (opts.ariaLabel) el.setAttribute('aria-label', opts.ariaLabel);
            li.appendChild(el);
            return li;
        }

        function renderPager(total, pages, start, end){
            if (!pager) return;
            if (pages <= 1) {
                pager.style.display = 'none';
                return;
            }
            pager.style.display = '';
            if (rangeEl) rangeEl.textContent = format(pager.dataset.labelRange || '%1$s–%2$s / %3$s', [start + 1, end, total]);

            var pageLabel = pager.dataset.labelPage || '%d';
            pagerList.innerHTML = '';
            pagerList.appendChild(pageItem('‹ ' + (pager.dataset.labelPrevious || ''), currentPage - 1, { disabled: currentPage === 1 }));
            pageNumbers(currentPage, pages).forEach(function(p){
                if (p === '…') {
                    pagerList.appendChild(pageItem('…', null, { disabled: true }));
                } else {
                    pagerList.appendChild(pageItem(String(p), p, { active: p === currentPage, ariaLabel: format(pageLabel, [p]) }));
                }
            });
            pagerList.appendChild(pageItem((pager.dataset.labelNext || '') + ' ›', currentPage + 1, { disabled: currentPage === pages }));
        }

        function goToPage(page){
            currentPage = page;
            apply(true);
            // Bring the user back to the top of the list, and move keyboard focus to the first project
            var first = items.filter(function(item){ return item.style.display !== 'none'; })[0];
            var firstBtn = first ? first.querySelector('.epfl-sp-details') : null;
            if (firstBtn) firstBtn.focus({ preventScroll: true });
            if (root.getBoundingClientRect().top < 0) {
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        if (pagerList) {
            pagerList.addEventListener('click', function(e){
                var link = e.target.closest('a[data-page]');
                if (!link) return;
                e.preventDefault();
                goToPage(parseInt(link.getAttribute('data-page'), 10));
            });
        }

        function sortItems(type){
            items.slice().sort(function(a, b){
                if (type === 'id') return parseInt(a.dataset.id, 10) - parseInt(b.dataset.id, 10);
                if (type === 'date') return new Date(b.dataset.date) - new Date(a.dataset.date);
                return a.dataset.title.toLowerCase() < b.dataset.title.toLowerCase() ? -1 : 1;
            }).forEach(function(el){ grid.appendChild(el); });
        }

        searchInput.addEventListener('input', function(){ apply(); });
        sortBtns.forEach(function(btn){
            btn.addEventListener('click', function(){
                sortBtns.forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                currentSort = btn.dataset.sort;
                sortItems(currentSort);
                apply();
            });
        });
        // Level chips are multi-select: click to add / remove a level, "All" clears the selection.
        // When nothing is selected, "All" becomes active again.
        function refreshLevelButtons(){
            levelBtns.forEach(function(b){
                var on = b.dataset.level === 'all' ? activeLevels.length === 0 : activeLevels.indexOf(b.dataset.level) !== -1;
                b.classList.toggle('active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        levelBtns.forEach(function(btn){
            btn.addEventListener('click', function(){
                var level = btn.dataset.level;
                if (level === 'all') {
                    activeLevels = [];
                } else {
                    var idx = activeLevels.indexOf(level);
                    if (idx === -1) {
                        activeLevels.push(level);
                    } else {
                        activeLevels.splice(idx, 1);
                    }
                    // Every level selected is the same as "All"
                    if (activeLevels.length === levelBtns.length - 1) {
                        activeLevels = [];
                    }
                }
                refreshLevelButtons();
                apply();
            });
        });
        refreshLevelButtons();

        function onKey(e){
            if (e.key === 'Escape') { closeModal(); return; }
            // Keep keyboard focus inside the dialog while it is open
            if (e.key === 'Tab') {
                var focusables = Array.prototype.filter.call(
                    modal.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'),
                    function(el){ return el.offsetParent !== null; }
                );
                if (!focusables.length) return;
                var first = focusables[0], last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        function openModal(item){
            lastFocused = document.activeElement;
            var tpl = item.querySelector('.epfl-sp-source');
            modalBody.innerHTML = tpl ? tpl.innerHTML : '';
            // The meta line (unit, date) is displayed in the header, under the title
            if (modalMeta) {
                modalMeta.innerHTML = '';
                var meta = modalBody.querySelector('.epfl-sp-detail-meta');
                if (meta) { while (meta.firstChild) modalMeta.appendChild(meta.firstChild); meta.parentNode.removeChild(meta); }
            }
            modalBody.scrollTop = 0;
            modalTitle.textContent = item.dataset.title || '';
            var badges = item.querySelector('.epfl-sp-badges');
            modalBadges.innerHTML = badges ? badges.innerHTML : '';
            if (modalView && item.dataset.portal) modalView.setAttribute('href', item.dataset.portal);
            modal.style.display = 'block';
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', closeModal);
            document.addEventListener('keydown', onKey);
            var firstClose = modal.querySelector('.epfl-sp-modal-close');
            if (firstClose) firstClose.focus();
        }
        function closeModal(){
            modal.style.display = 'none';
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            if (backdrop) { backdrop.parentNode && backdrop.parentNode.removeChild(backdrop); backdrop = null; }
            document.removeEventListener('keydown', onKey);
            if (lastFocused) lastFocused.focus();
        }

        items.forEach(function(item){
            var btn = item.querySelector('.epfl-sp-details');
            if (btn) btn.addEventListener('click', function(){ openModal(item); });
        });
        modalClosers.forEach(function(c){ c.addEventListener('click', closeModal); });
        // Clicking outside the dialog (on the dark area) closes it. The .modal layer covers the whole screen,
        // above the backdrop, so the click lands on it and not on the backdrop.
        modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

        // Clicking a keyword in the project details shows all the projects having that keyword
        modalBody.addEventListener('click', function(e){
            var kw = e.target.closest('.epfl-sp-keyword');
            if (!kw) return;
            activeKeyword = kw.getAttribute('data-keyword') || '';
            closeModal();
            apply();
            if (root.getBoundingClientRect().top < 0) {
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (keywordClear) keywordClear.focus({ preventScroll: true });
        });
        if (keywordClear) {
            keywordClear.addEventListener('click', function(){
                activeKeyword = '';
                apply();
                searchInput.focus();
            });
        }

        readUrl();
        refreshLevelButtons();
        apply(true);
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('.epfl-student-projects'), function(root, i){ initStudentProjects(root, i); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
