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
import {createMonitorReactive, formatDuration} from 'quiz_livequizmonitor/reactive/monitor_state';

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
            TIMER: '[data-field="timer"]',
        };
        this.pollTimer = null;
        this.tickTimer = null;
        this.pollInFlight = false;
        const root = descriptor.element ?? this.element;
        this.cmid = parseInt(descriptor.cmid ?? root.dataset.cmid, 10);
        this.groupid = parseInt(descriptor.groupid ?? root.dataset.groupid ?? 0, 10);
        this.pollinterval = parseInt(descriptor.pollinterval ?? root.dataset.pollinterval ?? 5, 10);
        this.lastUpdatedPrefix = root.dataset.lastupdatedPrefix ?? '';
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
            {watch: 'students.statuslabel:updated', handler: this.renderStudents},
        ];
    }

    /**
     * Start polling once state is ready.
     */
    stateReady() {
        this.startPolling();
        this.startTimerTick();
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
                progress.textContent = student.progresstext;
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
        });
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
