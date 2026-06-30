// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Live quiz monitor polling and DOM sync.
 *
 * @module     quiz_livequizmonitor/monitor
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {BaseComponent} from 'core/reactive';
import {matchesFilters, countVisible} from 'quiz_livequizmonitor/filter_utils';
import {createMonitorReactive, formatDuration} from 'quiz_livequizmonitor/reactive/monitor_state';
import {showExtendModal} from 'quiz_livequizmonitor/extend_time_modal';

/**
 * Reactive component that syncs monitor state to the page DOM.
 */
class MonitorComponent extends BaseComponent {
    /**
     * @param {object} descriptor Component descriptor
     */
    create(descriptor) {
        this.selectors = {
            LASTUPDATED: '[data-region="last-updated"]',
            STALE: '[data-region="stale-indicator"]',
            SUMMARYCOUNT: '[data-summary]',
            SUMMARYPCT: '[data-summary-pct]',
            STUDENTROW: 'tr[data-userid]',
            STATUSBADGE: '.badge',
            PROGRESS: '[data-field="progress"]',
            PROGRESSBAR: '[data-field="progress"] .progress-bar',
            PROGRESSCONTAINER: '[data-field="progress"] .progress[role="progressbar"]',
            PROGRESSLABEL: '[data-field="progress"] .livequizmonitor-progress-label',
            TIMER: '[data-field="timer"]',
            SEARCHINPUT: '[data-action="search"]',
            FILTERCHIP: '[data-action="filter-status"]',
            CLEARFILTERS: '[data-action="clear-filters"]',
            FILTEREMPTY: '[data-region="filter-empty"]',
            STUDENTTABLE: '[data-region="student-table"]',
            SUMMARYTILE: '.livequizmonitor-summary-tile',
            EXTENDBULK: '[data-action="extend-bulk"]',
        };
        this.pollTimer = null;
        this.tickTimer = null;
        this.pollInFlight = false;
        const root = descriptor.element ?? this.element;
        this.cmid = parseInt(descriptor.cmid ?? root.dataset.cmid, 10);
        this.groupid = parseInt(descriptor.groupid ?? root.dataset.groupid ?? 0, 10);
        this.pollinterval = parseInt(descriptor.pollinterval ?? root.dataset.pollinterval ?? 5, 10);
        this.lastUpdatedPrefix = root.dataset.lastupdatedPrefix ?? '';
        this.extendRowLabel = root.dataset.extendRowLabel ?? 'Extend time';
        this.actionsMenuLabel = root.dataset.actionsMenuLabel ?? 'Actions';
    }

    /**
     * Current reactive state.
     *
     * @returns {object}
     */
    getState() {
        return this.reactive.stateManager.state;
    }

    /**
     * Watch monitor state fields.
     *
     * @returns {Array}
     */
    getWatchers() {
        return [
            {watch: 'meta.updatedat:updated', handler: this.renderLastUpdated},
            {watch: 'meta.stale:updated', handler: this.renderStaleIndicator},
            {watch: 'summary.notstarted:updated', handler: this.renderSummary},
            {watch: 'summary.inprogress:updated', handler: this.renderSummary},
            {watch: 'summary.completed:updated', handler: this.renderSummary},
            {watch: 'students:created', handler: this.renderStudents},
            {watch: 'students:updated', handler: this.renderStudents},
            {watch: 'students.timeremaining:updated', handler: this.renderStudents},
            {watch: 'students.progresstext:updated', handler: this.renderStudents},
            {watch: 'students.progresspercent:updated', handler: this.renderStudents},
            {watch: 'students.progressbarclass:updated', handler: this.renderStudents},
            {watch: 'students.statuslabel:updated', handler: this.renderStudents},
            {watch: 'students.statusclass:updated', handler: this.renderStudents},
            {watch: 'students.status:updated', handler: this.renderStudents},
            {watch: 'students.canextend:updated', handler: this.renderStudents},
            {watch: 'students.attemptendat:updated', handler: this.renderStudents},
            {watch: 'summary.notstarted:updated', handler: this.renderFilterToolbar},
            {watch: 'summary.inprogress:updated', handler: this.renderFilterToolbar},
            {watch: 'summary.inprogress:updated', handler: this.renderBulkExtendButton},
            {watch: 'summary.completed:updated', handler: this.renderFilterToolbar},
            {watch: 'meta.totalstudents:updated', handler: this.renderFilterToolbar},
        ];
    }

    /**
     * Start polling once state is ready.
     */
    stateReady() {
        this.bindFilterEvents();
        this.bindExtendEvents();
        this.startPolling();
        this.startTimerTick();
        this.renderFilterToolbar();
        this.renderBulkExtendButton();
        this.applyRowVisibility();
    }

    /**
     * Bind bulk and individual extend controls.
     */
    bindExtendEvents() {
        this.addEventListener(this.element, 'click', this.handleExtendClick);
    }

    /**
     * Delegate extend bulk and individual action menu clicks.
     *
     * @param {Event} event
     */
    handleExtendClick(event) {
        const bulkBtn = event.target.closest(this.selectors.EXTENDBULK);
        if (bulkBtn && this.element.contains(bulkBtn)) {
            event.preventDefault();
            if (bulkBtn.disabled) {
                return;
            }
            this.openBulkExtendModal();
            return;
        }

        const individualLink = event.target.closest('[data-action="extend-individual"]');
        if (individualLink && this.element.contains(individualLink)) {
            event.preventDefault();
            if (individualLink.classList.contains('disabled')
                || individualLink.getAttribute('aria-disabled') === 'true') {
                return;
            }
            this.openIndividualExtendModal(individualLink);
        }
    }

    /**
     * Open bulk extend modal and refresh on success.
     */
    async openBulkExtendModal() {
        const state = this.getState();
        const inprogresscount = state?.summary?.inprogress?.count ?? state?.meta?.inprogresscount ?? 0;
        const response = await showExtendModal({
            mode: 'bulk',
            cmid: this.cmid,
            groupid: this.groupid,
            inprogresscount,
        });
        if (response) {
            this.poll();
        }
    }

    /**
     * Open individual extend modal for one student.
     *
     * @param {HTMLElement} trigger Action menu link element
     */
    async openIndividualExtendModal(trigger) {
        const response = await showExtendModal({
            mode: 'individual',
            cmid: this.cmid,
            groupid: this.groupid,
            userid: parseInt(trigger.dataset.userid, 10),
            studentname: trigger.dataset.studentname ?? '',
            attemptendat: parseInt(trigger.dataset.attemptendat, 10) || null,
        });
        if (response) {
            this.poll();
        }
    }

    /**
     * Enable or disable bulk extend button from reactive in-progress count.
     */
    renderBulkExtendButton() {
        const button = this.getElement(this.selectors.EXTENDBULK);
        if (!button) {
            return;
        }
        const count = this.getState()?.summary?.inprogress?.count ?? 0;
        button.disabled = count === 0;
    }

    /**
     * Bind search, status filter, and clear control events.
     */
    bindFilterEvents() {
        const searchInput = this.getElement(this.selectors.SEARCHINPUT);
        if (searchInput) {
            this.addEventListener(searchInput, 'input', this.handleSearchInput);
        }

        this.addEventListener(this.element, 'click', this.handleFilterClick);

        const clearBtn = this.getElement(this.selectors.CLEARFILTERS);
        if (clearBtn) {
            this.addEventListener(clearBtn, 'click', this.handleClearFilters);
        }

        this.addEventListener(this.element, 'keydown', this.handleFilterKeydown);
    }

    /**
     * Handle search input changes.
     *
     * @param {Event} event
     */
    handleSearchInput(event) {
        this.reactive.dispatch('setSearch', event.target.value);
        this.afterFilterChange();
    }

    /**
     * Delegate clicks on status chips and summary tiles.
     *
     * @param {Event} event
     */
    handleFilterClick(event) {
        const trigger = event.target.closest(this.selectors.FILTERCHIP);
        if (!trigger || !this.element.contains(trigger)) {
            return;
        }
        const status = trigger.dataset.status;
        if (!status) {
            return;
        }
        event.preventDefault();
        this.reactive.dispatch('setStatusFilter', status);
        this.afterFilterChange();
    }

    /**
     * Support keyboard activation on summary tiles.
     *
     * @param {KeyboardEvent} event
     */
    handleFilterKeydown(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const tile = event.target.closest(this.selectors.SUMMARYTILE);
        if (!tile || !this.element.contains(tile)) {
            return;
        }
        event.preventDefault();
        const status = tile.dataset.status;
        if (status) {
            this.reactive.dispatch('setStatusFilter', status);
            this.afterFilterChange();
        }
    }

    /**
     * Reset all filters and restore the full student list.
     *
     * @param {Event} event
     */
    handleClearFilters(event) {
        event.preventDefault();
        this.reactive.dispatch('clearFilters');
        const searchInput = this.getElement(this.selectors.SEARCHINPUT);
        if (searchInput) {
            searchInput.value = '';
        }
        this.afterFilterChange();
    }

    /**
     * Re-apply all filter-driven DOM updates.
     */
    afterFilterChange() {
        this.renderFilterToolbar();
        this.applyRowVisibility();
        this.renderFilterEmpty();
    }

    /**
     * Clean up timers on destroy.
     */
    destroy() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
        }
        if (this.tickTimer) {
            clearInterval(this.tickTimer);
        }
        super.destroy();
    }

    /**
     * Begin Ajax polling loop.
     */
    startPolling() {
        const interval = Math.max(3, Math.min(30, this.pollinterval)) * 1000;
        this.poll();
        this.pollTimer = setInterval(() => this.poll(), interval);
    }

    /**
     * Begin local timer countdown between polls.
     */
    startTimerTick() {
        this.tickTimer = setInterval(() => {
            this.reactive.dispatch('tickTimers');
        }, 1000);
    }

    /**
     * Poll server for fresh monitor state.
     */
    async poll() {
        if (this.pollInFlight) {
            return;
        }
        this.pollInFlight = true;
        try {
            const response = await Ajax.call([{
                methodname: 'quiz_livequizmonitor_get_monitor_state',
                args: {
                    cmid: this.cmid,
                    groupid: this.groupid,
                },
            }])[0];
            this.reactive.dispatch('refreshState', response);
        } catch (e) {
            this.reactive.dispatch('setStale', true);
        } finally {
            this.pollInFlight = false;
        }
    }

    /**
     * Update last-updated text.
     */
    renderLastUpdated() {
        const el = this.getElement(this.selectors.LASTUPDATED);
        if (!el) {
            return;
        }
        const updatedat = this.getState()?.meta?.updatedat;
        if (!updatedat) {
            return;
        }
        const date = new Date(updatedat * 1000);
        const formatted = date.toLocaleString();
        el.textContent = this.lastUpdatedPrefix ? `${this.lastUpdatedPrefix}${formatted}` : formatted;
    }

    /**
     * Toggle stale-data warning.
     */
    renderStaleIndicator() {
        const el = this.getElement(this.selectors.STALE);
        if (!el) {
            return;
        }
        el.classList.toggle('d-none', !this.getState()?.meta?.stale);
    }

    /**
     * Update summary tile counts and percentages.
     */
    renderSummary() {
        const summary = this.getState()?.summary;
        if (!summary) {
            return;
        }
        ['inprogress', 'notstarted', 'completed'].forEach((key) => {
            const bucket = summary[key];
            if (!bucket) {
                return;
            }
            const countEl = this.element.querySelector(`[data-summary="${key}"]`);
            const pctEl = this.element.querySelector(`[data-summary-pct="${key}"]`);
            if (countEl) {
                countEl.textContent = bucket.count;
            }
            if (pctEl) {
                pctEl.textContent = `${bucket.percent}%`;
            }
        });
    }

    /**
     * Return student rows from reactive state (StateMap or array).
     *
     * @returns {Array}
     */
    getStudentRows() {
        const students = this.getState()?.students;
        if (!students) {
            return [];
        }
        if (students instanceof Map) {
            return [...students.values()];
        }
        return students;
    }

    /**
     * Toggle row visibility based on active filters.
     */
    applyRowVisibility() {
        const filters = this.getState()?.meta?.filters ?? {search: '', status: 'all'};
        this.getStudentRows().forEach((student) => {
            const userid = student.userid ?? student.id;
            const row = this.element.querySelector(`tr[data-userid="${userid}"]`);
            if (!row) {
                return;
            }
            row.classList.toggle('d-none', !matchesFilters(student, filters));
        });
        this.renderFilterEmpty();
    }

    /**
     * Sync chip/tile active state and chip count labels.
     */
    renderFilterToolbar() {
        const state = this.getState();
        if (!state) {
            return;
        }
        const activeStatus = state.meta?.filters?.status ?? 'all';
        const summary = state.summary ?? {};
        const counts = {
            all: state.meta?.totalstudents ?? 0,
            notstarted: summary.notstarted?.count ?? 0,
            inprogress: summary.inprogress?.count ?? 0,
            completed: summary.completed?.count ?? 0,
        };

        this.element.querySelectorAll(this.selectors.FILTERCHIP).forEach((chip) => {
            const status = chip.dataset.status;
            const isActive = status === activeStatus;
            chip.classList.toggle('active', isActive);
            chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            const countEl = chip.querySelector(`[data-filter-count="${status}"]`);
            if (countEl && status in counts) {
                countEl.textContent = counts[status];
            }
        });

        this.element.querySelectorAll(this.selectors.SUMMARYTILE).forEach((tile) => {
            const status = tile.dataset.status;
            const isActive = status === activeStatus;
            tile.classList.toggle('livequizmonitor-tile-active', isActive);
            tile.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    /**
     * Show or hide the filtered-empty message vs the student table.
     */
    renderFilterEmpty() {
        const emptyEl = this.getElement(this.selectors.FILTEREMPTY);
        const tableEl = this.getElement(this.selectors.STUDENTTABLE);
        if (!emptyEl) {
            return;
        }
        const state = this.getState();
        const hasCohort = state?.meta?.hasstudents;
        const filters = state?.meta?.filters ?? {search: '', status: 'all'};
        const hasActiveFilter = filters.search !== '' || filters.status !== 'all';
        const visible = countVisible(state?.students ?? [], filters);
        const showEmpty = hasCohort && hasActiveFilter && visible === 0;

        emptyEl.classList.toggle('d-none', !showEmpty);
        if (tableEl) {
            tableEl.classList.toggle('d-none', showEmpty);
        }
    }

    /**
     * Whether extend time is available for a student row.
     *
     * @param {object} student Student state row
     * @returns {boolean}
     */
    isExtendActionEnabled(student) {
        return student?.status === 'inprogress';
    }

    /**
     * Escape text for safe HTML attribute or content insertion.
     *
     * @param {string} text Raw text
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';
        return div.innerHTML;
    }

    /**
     * Build row action menu markup matching row_action_menu.mustache.
     *
     * @param {object} student Student state row
     * @returns {string}
     */
    buildRowActionMenuHtml(student) {
        const userid = student.userid ?? student.id;
        const attemptendat = student.attemptendat ?? '';
        const fullname = this.escapeHtml(student.fullname ?? '');
        const extendlabel = this.escapeHtml(this.extendRowLabel);
        const actionslabel = this.escapeHtml(this.actionsMenuLabel);
        const enabled = this.isExtendActionEnabled(student);
        const disabledClass = enabled ? '' : ' disabled';
        const disabledAttrs = enabled ? '' : ' aria-disabled="true" tabindex="-1"';

        return `
            <div class="dropdown livequizmonitor-row-actions" data-region="row-actions">
                <button type="button"
                        class="btn btn-icon dropdown-toggle no-caret d-flex align-items-center justify-content-center"
                        data-toggle="dropdown"
                        aria-haspopup="true"
                        aria-expanded="false"
                        title="${actionslabel}">
                    <i class="icon fa fa-ellipsis-vertical fa-fw" aria-hidden="true"></i>
                    <span class="sr-only">${actionslabel}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a href="#"
                       class="dropdown-item menu-action${disabledClass}"
                       role="menuitem"
                       data-action="extend-individual"
                       data-userid="${userid}"
                       data-studentname="${fullname}"
                       data-attemptendat="${attemptendat}"${disabledAttrs}>
                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                        <span class="menu-action-text">${extendlabel}</span>
                    </a>
                </div>
            </div>
        `;
    }

    /**
     * Sync disabled state and data attributes on the extend menu item.
     *
     * @param {HTMLElement} link Extend action menu item
     * @param {object} student Student state row
     */
    updateExtendActionLink(link, student) {
        const enabled = this.isExtendActionEnabled(student);
        link.dataset.userid = String(student.userid ?? student.id);
        link.dataset.studentname = student.fullname ?? '';
        link.dataset.attemptendat = student.attemptendat ?? '';
        link.classList.toggle('disabled', !enabled);
        if (enabled) {
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
        } else {
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
        }
    }

    /**
     * Keep the row action menu visible and refresh extend item state.
     *
     * @param {object} student Student state row
     * @param {HTMLElement} row Table row element
     */
    renderRowActions(student, row) {
        const cell = row.querySelector('[data-field="actions"]');
        if (!cell) {
            return;
        }

        let menu = cell.querySelector('[data-region="row-actions"]');
        if (!menu) {
            cell.insertAdjacentHTML('beforeend', this.buildRowActionMenuHtml(student));
            return;
        }

        const link = menu.querySelector('[data-action="extend-individual"]');
        if (link) {
            this.updateExtendActionLink(link, student);
        }
    }

    /**
     * Update student table rows from state.
     */
    renderStudents() {
        this.getStudentRows().forEach((student) => {
            const userid = student.userid ?? student.id;
            const row = this.element.querySelector(`tr[data-userid="${userid}"]`);
            if (!row) {
                return;
            }
            const badge = row.querySelector(this.selectors.STATUSBADGE);
            if (badge) {
                badge.textContent = student.statuslabel;
                badge.className = `badge ${student.statusclass}`;
            }
            const progress = row.querySelector(this.selectors.PROGRESS);
            if (progress) {
                const bar = row.querySelector(this.selectors.PROGRESSBAR);
                const container = row.querySelector(this.selectors.PROGRESSCONTAINER);
                const label = row.querySelector(this.selectors.PROGRESSLABEL);
                const percent = student.progresspercent ?? 0;

                if (bar) {
                    bar.style.width = `${percent}%`;
                    bar.className = `progress-bar ${student.progressbarclass ?? ''}`.trim();
                }
                if (container) {
                    container.setAttribute('aria-valuenow', String(percent));
                    if (student.progresstext) {
                        container.setAttribute('aria-label', student.progresstext);
                    }
                }
                if (label) {
                    label.textContent = student.progresstext ?? '';
                    label.classList.toggle('d-none', !student.progresstext);
                }
            }
            const timer = row.querySelector(this.selectors.TIMER);
            if (timer) {
                if (student.hastimer && student.timeremaining !== null && student.timeremaining !== undefined) {
                    timer.dataset.timeremaining = student.timeremaining;
                    timer.textContent = student.timeremainingdisplay || formatDuration(student.timeremaining);
                } else {
                    timer.removeAttribute('data-timeremaining');
                    timer.textContent = '—';
                }
            }
            this.renderRowActions(student, row);
        });
        this.applyRowVisibility();
    }
}

/**
 * Initialise live monitor on a page region.
 *
 * @param {string} selector CSS selector for root element
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }

    const reactive = createMonitorReactive(root);
    const cmid = root.dataset.cmid;
    const groupid = root.dataset.groupid || 0;
    const pollinterval = root.dataset.pollinterval || 5;

    return new MonitorComponent({
        element: root,
        reactive,
        cmid,
        groupid,
        pollinterval,
    });
};

export default {init};
