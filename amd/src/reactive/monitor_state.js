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
 * Reactive state definition for the live quiz monitor.
 *
 * @module     quiz_livequizmonitor/reactive/monitor_state
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Reactive} from 'core/reactive';

/**
 * Empty summary bucket defaults.
 *
 * @returns {object}
 */
const emptySummary = () => ({
    notstarted: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-secondary',
    },
    inprogress: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-warning',
    },
    completed: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-success',
    },
});

/**
 * Create the initial monitor state shape.
 *
 * Moodle reactive state root may only contain objects; list items require an id.
 *
 * @returns {object}
 */
export const createInitialState = () => ({
    meta: {
        quizid: 0,
        cmid: 0,
        quizname: '',
        updatedat: 0,
        totalstudents: 0,
        hasstudents: false,
        stale: false,
        pollinterval: 5,
        groupid: 0,
        filters: {
            search: '',
            status: 'all',
        },
        canextend: false,
        inprogresscount: 0,
    },
    summary: emptySummary(),
    students: [],
});

/**
 * Normalise API student rows for reactive StateMap (requires id).
 *
 * @param {Array} students
 * @returns {Array}
 */
const normaliseStudents = (students) => students.map((student) => ({
    ...student,
    id: student.userid,
}));

/**
 * Mutation handlers for monitor state updates.
 */
class MonitorMutations {
    /**
     * Replace monitor state from a poll response.
     *
     * @param {StateManager} stateManager
     * @param {object} payload Monitor state payload
     */
    refreshState(stateManager, payload) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.quizid = payload.quizid;
        stateManager.state.meta.cmid = payload.cmid;
        stateManager.state.meta.quizname = payload.quizname;
        stateManager.state.meta.updatedat = payload.updatedat;
        stateManager.state.meta.totalstudents = payload.totalstudents;
        stateManager.state.meta.hasstudents = payload.hasstudents;
        stateManager.state.meta.stale = false;
        if (payload.canextend !== undefined) {
            stateManager.state.meta.canextend = payload.canextend;
        }
        if (payload.inprogresscount !== undefined) {
            stateManager.state.meta.inprogresscount = payload.inprogresscount;
        }

        // Update summary buckets in place so watchers receive summary.<bucket>:updated events.
        ['notstarted', 'inprogress', 'completed'].forEach((key) => {
            if (payload.summary?.[key]) {
                stateManager.state.summary[key] = payload.summary[key];
            }
        });

        // Merge students into the existing StateMap so watchers receive students:updated/created.
        const incoming = normaliseStudents(payload.students ?? []);
        const incomingids = new Set(incoming.map((student) => String(student.id)));
        const students = stateManager.state.students;

        stateManager.getIds('students').forEach((id) => {
            if (!incomingids.has(String(id))) {
                students.delete(id);
            }
        });

        incoming.forEach((student) => {
            students.set(student.id, student);
        });

        stateManager.setReadOnly(true);
    }

    /**
     * Update the search filter term.
     *
     * @param {StateManager} stateManager
     * @param {string} value Search input value
     */
    setSearch(stateManager, value) {
        stateManager.setReadOnly(false);
        const trimmed = (value ?? '').trim();
        stateManager.state.meta.filters.search = trimmed;
        stateManager.setReadOnly(true);
    }

    /**
     * Update the status filter (single-select with toggle-off).
     *
     * @param {StateManager} stateManager
     * @param {string} status Status key or all
     */
    setStatusFilter(stateManager, status) {
        stateManager.setReadOnly(false);
        if (status === 'all') {
            stateManager.state.meta.filters.status = 'all';
        } else {
            const current = stateManager.state.meta.filters.status;
            stateManager.state.meta.filters.status = current === status ? 'all' : status;
        }
        stateManager.setReadOnly(true);
    }

    /**
     * Reset search and status filters to defaults.
     *
     * @param {StateManager} stateManager
     */
    clearFilters(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.filters.search = '';
        stateManager.state.meta.filters.status = 'all';
        stateManager.setReadOnly(true);
    }

    /**
     * Mark state as stale after poll failure.
     *
     * @param {StateManager} stateManager
     * @param {boolean} stale Whether data is stale
     */
    setStale(stateManager, stale = true) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.stale = stale;
        stateManager.setReadOnly(true);
    }

    /**
     * Decrement in-progress timers locally between polls.
     *
     * @param {StateManager} stateManager
     */
    tickTimers(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.students.forEach((student) => {
            if (!student.hastimer || student.timeremaining === null || student.timeremaining === undefined) {
                return;
            }
            if (student.timeremaining > 0) {
                student.timeremaining -= 1;
                student.timeremainingdisplay = formatDuration(student.timeremaining);
            }
        });
        stateManager.setReadOnly(true);
    }
}

/**
 * Format seconds as MM:SS or HH:MM:SS.
 *
 * @param {number} seconds
 * @returns {string}
 */
export const formatDuration = (seconds) => {
    const safe = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

/**
 * Create a reactive instance bound to a DOM target.
 *
 * @param {HTMLElement} target Root element for custom events
 * @returns {Reactive}
 */
export const createMonitorReactive = (target) => {
    return new Reactive({
        name: 'quiz_livequizmonitor',
        eventName: 'quiz-live-monitor-state-change',
        eventDispatch: (detail, container) => {
            container.dispatchEvent(new CustomEvent('quiz-live-monitor-state-change', {
                bubbles: false,
                detail,
            }));
        },
        target,
        state: createInitialState(),
        mutations: new MonitorMutations(),
    });
};
