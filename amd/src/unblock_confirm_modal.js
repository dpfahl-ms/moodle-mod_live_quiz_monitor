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
 * Unblock student confirmation modal.
 *
 * @module     quiz_livequizmonitor/unblock_confirm_modal
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalFactory from 'core/modal_factory';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Show unblock confirmation modal; returns API outcome or null when cancelled.
 *
 * @param {object} config
 * @param {number} config.cmid Course module id
 * @param {number} config.userid Target student user id
 * @param {number} config.attemptid Quiz attempt id
 * @param {string} config.studentname Student display name
 * @returns {Promise<object|null>}
 */
export const showUnblockModal = async (config) => {
    const [modaltitle, modalbody, confirm, success] = await Promise.all([
        getString('onesession:unblockmodaltitle', 'quiz_livequizmonitor', config.studentname ?? ''),
        getString('onesession:unblockmodalbody', 'quiz_livequizmonitor'),
        getString('onesession:unblockconfirm', 'quiz_livequizmonitor'),
        getString('onesession:unblocksuccess', 'quiz_livequizmonitor'),
    ]);

    const modal = await ModalFactory.create({
        type: ModalFactory.types.SAVE_CANCEL,
        title: modaltitle,
        body: `<p class="mb-0">${modalbody}</p>`,
    });

    modal.setSaveButtonText(confirm);
    await modal.show();

    let finished = false;

    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.save, async () => {
            modal.getRoot().find('[data-action="save"]').prop('disabled', true);

            try {
                const response = await Ajax.call([{
                    methodname: 'quiz_livequizmonitor_unblock_student',
                    args: {
                        cmid: config.cmid,
                        userid: config.userid,
                        attemptid: config.attemptid,
                    },
                }])[0];

                await Notification.addNotification({
                    message: success,
                    type: 'success',
                });

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

export default {showUnblockModal};
