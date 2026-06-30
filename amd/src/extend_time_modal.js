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
 * Extend quiz time confirmation modal.
 *
 * @module     quiz_livequizmonitor/extend_time_modal
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Templates from 'core/templates';

/** @type {number[]} */
const PRESETS = [5, 10, 15, 30];

/** @type {number} */
const DEFAULT_MINUTES = 15;

/**
 * Format a unix timestamp for display.
 *
 * @param {number} timestamp Unix seconds
 * @returns {string}
 */
const formatDeadline = (timestamp) => {
    if (!timestamp) {
        return '—';
    }
    return new Date(timestamp * 1000).toLocaleString();
};

/**
 * Load modal language strings.
 *
 * @returns {Promise<object>}
 */
const loadStrings = async () => {
    const keys = [
        {key: 'extend:modaltitle', component: 'quiz_livequizmonitor'},
        {key: 'extend:addtime', component: 'quiz_livequizmonitor'},
        {key: 'extend:modalbodyindividual', component: 'quiz_livequizmonitor'},
        {key: 'extend:modalbodybulk', component: 'quiz_livequizmonitor'},
        {key: 'extend:newdeadlineindividual', component: 'quiz_livequizmonitor'},
        {key: 'extend:newdeadlinebulk', component: 'quiz_livequizmonitor'},
        {key: 'extend:confirm', component: 'quiz_livequizmonitor'},
        {key: 'extend:mineach', component: 'quiz_livequizmonitor'},
        {key: 'extend:successindividual', component: 'quiz_livequizmonitor'},
        {key: 'extend:successbulk', component: 'quiz_livequizmonitor'},
    ];
    const [
        modaltitle,
        addtime,
        modalbodyindividual,
        modalbodybulk,
        newdeadlineindividual,
        newdeadlinebulk,
        confirm,
        mineach,
        successindividual,
        successbulk,
    ] = await Promise.all(keys.map(({key, component}) => getString(key, component)));

    return {
        modaltitle,
        addtime,
        modalbodyindividual,
        modalbodybulk,
        newdeadlineindividual,
        newdeadlinebulk,
        confirm,
        mineach,
        successindividual,
        successbulk,
    };
};

/**
 * Build modal body template context.
 *
 * @param {object} config Modal configuration
 * @param {object} strings Loaded language strings
 * @param {number} minutes Selected minutes
 * @returns {Promise<object>}
 */
const buildBodyContext = async (config, strings, minutes) => {
    const isBulk = config.mode === 'bulk';
    const description = isBulk
        ? await getString('extend:modalbodybulk', 'quiz_livequizmonitor', {count: config.inprogresscount ?? 0})
        : await getString('extend:modalbodyindividual', 'quiz_livequizmonitor', {name: config.studentname ?? ''});

    let previewlabel;
    let previewvalue;
    if (isBulk) {
        previewlabel = await getString('extend:newdeadlinebulk', 'quiz_livequizmonitor', {count: config.inprogresscount ?? 0});
        previewvalue = await getString('extend:mineach', 'quiz_livequizmonitor', minutes);
    } else {
        previewlabel = strings.newdeadlineindividual;
        const base = parseInt(config.attemptendat, 10) || 0;
        previewvalue = base ? formatDeadline(base + minutes * 60) : '—';
    }

    return {
        description,
        addtimelabel: strings.addtime,
        previewlabel,
        previewvalue,
        presets: PRESETS.map((preset) => ({
            minutes: preset,
            active: preset === minutes,
        })),
    };
};

/**
 * Update preview and confirm label after preset change.
 *
 * @param {HTMLElement} root Modal root element
 * @param {object} config Modal configuration
 * @param {object} strings Loaded language strings
 * @param {number} minutes Selected minutes
 * @param {object} modal Modal instance
 */
const updatePreview = async (root, config, strings, minutes, modal) => {
    const context = await buildBodyContext(config, strings, minutes);
    const previewLabel = root.querySelector('[data-region="extend-preview-label"]');
    const previewValue = root.querySelector('[data-region="extend-preview"]');
    if (previewLabel) {
        previewLabel.textContent = context.previewlabel;
    }
    if (previewValue) {
        previewValue.textContent = context.previewvalue;
    }

    root.querySelectorAll('.livequizmonitor-extend-preset').forEach((button) => {
        const isActive = parseInt(button.dataset.minutes, 10) === minutes;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    const confirmLabel = await getString('extend:confirm', 'quiz_livequizmonitor', minutes);
    modal.setSaveButtonText(confirmLabel);
};

/**
 * Show extend time modal and return outcome on confirm.
 *
 * @param {object} config
 * @param {string} config.mode individual|bulk
 * @param {number} config.cmid Course module id
 * @param {number} config.groupid Group id
 * @param {number} [config.userid] Target user for individual mode
 * @param {string} [config.studentname] Student name for individual mode
 * @param {number} [config.attemptendat] Attempt deadline for individual preview
 * @param {number} [config.inprogresscount] In-progress count for bulk mode
 * @returns {Promise<object|null>} API response or null when cancelled
 */
export const showExtendModal = async (config) => {
    const strings = await loadStrings();
    let minutes = DEFAULT_MINUTES;
    const bodyContext = await buildBodyContext(config, strings, minutes);

    const {html, js} = await Templates.renderForPromise(
        'quiz_livequizmonitor/extend_time_modal_body',
        bodyContext
    );

    const modal = await ModalFactory.create({
        type: ModalFactory.types.SAVE_CANCEL,
        title: strings.modaltitle,
        body: html,
    });

    await modal.show();

    const root = modal.getRoot()[0];
    if (js) {
        Templates.runTemplateJS(js);
    }

    modal.setSaveButtonText(await getString('extend:confirm', 'quiz_livequizmonitor', minutes));

    root.querySelectorAll('.livequizmonitor-extend-preset').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            minutes = parseInt(button.dataset.minutes, 10);
            updatePreview(root, config, strings, minutes, modal);
        });
    });

    let finished = false;

    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.save, async () => {
            modal.getRoot().find('[data-action="save"]').prop('disabled', true);
            try {
                const args = {
                    cmid: config.cmid,
                    groupid: config.groupid ?? 0,
                    minutes,
                    scope: config.mode === 'bulk' ? 'bulk' : 'individual',
                    userid: config.mode === 'bulk' ? 0 : (config.userid ?? 0),
                };
                const response = await Ajax.call([{
                    methodname: 'quiz_livequizmonitor_extend_quiz_time',
                    args,
                }])[0];

                if (config.mode === 'bulk') {
                    await Notification.addNotification({
                        message: await getString('extend:successbulk', 'quiz_livequizmonitor', {
                            minutes: response.minutes,
                            count: response.extendedcount,
                        }),
                        type: 'success',
                    });
                } else {
                    const name = config.studentname ?? (response.usernames[0] ?? '');
                    await Notification.addNotification({
                        message: await getString('extend:successindividual', 'quiz_livequizmonitor', {
                            minutes: response.minutes,
                            name,
                        }),
                        type: 'success',
                    });
                }

                if (response.warnings?.length) {
                    await Notification.addNotification({
                        message: response.warnings.join('\n'),
                        type: 'warning',
                    });
                }

                finished = true;
                modal.destroy();
                resolve(response);
            } catch (error) {
                modal.getRoot().find('[data-action="save"]').prop('disabled', false);
                Notification.exception(error);
            }
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            if (!finished) {
                resolve(null);
            }
        });
    });
};

export default {showExtendModal};
