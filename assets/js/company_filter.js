/**
 * CollaboraNexio - Company Filter UI (checkbox dropdown, apply-only)
 * Used by includes/company_filter.php
 */

(() => {
    'use strict';

    function qs(root, sel) {
        return root.querySelector(sel);
    }

    function qsa(root, sel) {
        return Array.from(root.querySelectorAll(sel));
    }

    function closeAll(except = null) {
        document.querySelectorAll('[data-cnx-company-filter]').forEach((wrap) => {
            if (except && wrap === except) return;
            const pop = qs(wrap, '[data-cnx-company-filter-popover]');
            const btn = qs(wrap, '[data-cnx-company-filter-trigger]');
            if (pop && !pop.hidden) {
                pop.hidden = true;
                // Belt-and-suspenders: ensure no overlay even if [hidden] is overridden by extensions/CSS
                pop.style.display = 'none';
            }
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    function initCompanyFilter(wrap) {
        const form = qs(wrap, 'form');
        const trigger = qs(wrap, '[data-cnx-company-filter-trigger]');
        const popover = qs(wrap, '[data-cnx-company-filter-popover]');
        const closeBtn = qs(wrap, '[data-cnx-company-filter-close]');
        const applyBtn = qs(wrap, '[data-cnx-company-filter-apply]');
        const searchInput = qs(wrap, '[data-cnx-company-filter-search]');
        const emptyEl = qs(wrap, '[data-cnx-company-filter-empty]');
        const allBox = qs(wrap, 'input[type="checkbox"][value="all"]');
        const boxes = qsa(wrap, 'input[type="checkbox"][name="company_filter[]"]');
        const valueEl = qs(wrap, '.company-filter-value');

        if (!form || !trigger || !popover || !allBox) return;

        function setOpen(open) {
            if (open) {
                closeAll(wrap);
                popover.hidden = false;
                popover.style.display = '';
                trigger.setAttribute('aria-expanded', 'true');
                // Focus search for fast typing
                if (searchInput) {
                    // Reset filter on open so user always sees full list first
                    searchInput.value = '';
                    applySearchFilter('');
                    setTimeout(() => searchInput.focus(), 0);
                }
            } else {
                popover.hidden = true;
                popover.style.display = 'none';
                trigger.setAttribute('aria-expanded', 'false');
            }
        }

        function selectedIds() {
            return boxes.filter(b => b.checked).map(b => b.value);
        }

        function normalizeSelection() {
            const selected = selectedIds();
            const hasAll = selected.includes('all');

            if (hasAll) {
                boxes.forEach((b) => {
                    if (b.value !== 'all') b.checked = false;
                });
            } else {
                // If nothing selected, default back to all
                const anySpecific = selected.length > 0;
                if (!anySpecific) {
                    allBox.checked = true;
                    boxes.forEach((b) => {
                        if (b.value !== 'all') b.checked = false;
                    });
                }
            }
        }

        function updateSummary() {
            if (!valueEl) return;
            const selected = selectedIds();
            if (selected.includes('all')) {
                valueEl.textContent = 'Tutte le aziende';
                return;
            }
            const companies = boxes.filter(b => b.checked && b.value !== 'all');
            if (companies.length === 1) {
                const label = companies[0].closest('label');
                const text = label ? (label.textContent || '').trim() : '1 azienda';
                valueEl.textContent = text || '1 azienda';
            } else {
                valueEl.textContent = `${companies.length} aziende`;
            }
        }

        function applySearchFilter(term) {
            const t = String(term || '').trim().toLowerCase();
            const optionLabels = qsa(wrap, '.company-filter-option');
            let visible = 0;

            optionLabels.forEach((lab) => {
                const input = qs(lab, 'input[type="checkbox"]');
                if (!input) return;
                if (input.value === 'all') {
                    lab.style.display = '';
                    return;
                }
                if (t === '') {
                    lab.style.display = '';
                    visible += 1;
                    return;
                }
                const text = (lab.textContent || '').toLowerCase();
                const ok = text.includes(t);
                lab.style.display = ok ? '' : 'none';
                if (ok) visible += 1;
            });

            if (emptyEl) {
                emptyEl.hidden = !(t !== '' && visible === 0);
            }
        }

        trigger.addEventListener('click', () => {
            setOpen(popover.hidden);
        });

        if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false));
        if (applyBtn) applyBtn.addEventListener('click', () => {
            normalizeSelection();
            form.submit();
        });

        if (searchInput) {
            searchInput.addEventListener('input', () => applySearchFilter(searchInput.value));
            searchInput.addEventListener('keydown', (e) => {
                // Enter should apply immediately (quality of life)
                if (e.key === 'Enter') {
                    e.preventDefault();
                    normalizeSelection();
                    form.submit();
                }
            });
        }

        boxes.forEach((b) => {
            b.addEventListener('change', () => {
                if (b.value === 'all' && b.checked) {
                    boxes.forEach(x => { if (x.value !== 'all') x.checked = false; });
                } else if (b.value !== 'all' && b.checked) {
                    allBox.checked = false;
                }
                normalizeSelection();
                updateSummary();
            });
        });

        normalizeSelection();
        updateSummary();
    }

    document.addEventListener('click', (e) => {
        const inside = e.target.closest('[data-cnx-company-filter]');
        if (!inside) closeAll(null);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAll(null);
    });

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-cnx-company-filter]').forEach(initCompanyFilter);
    });
})();