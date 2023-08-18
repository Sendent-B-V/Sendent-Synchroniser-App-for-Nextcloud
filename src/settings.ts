/* eslint-disable @nextcloud/no-deprecations */
import GroupsManagementHandler from "./imports/GroupsManagementHandler";

$(() => {
    console.log('Sendentsynchroniser Setting script loaded');

	GroupsManagementHandler.setup();

    $('#settingsform').on('submit', function (ev) {
        ev.preventDefault();
        //I had an issue that the forms were submitted in geometrical progression after the next submit.
        // This solved the problem.
        ev.stopImmediatePropagation();
    });
})
