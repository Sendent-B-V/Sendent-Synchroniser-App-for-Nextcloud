/* eslint-disable @nextcloud/no-deprecations */
import SettingFormHandler from "./imports/SettingFormHandler";

$(() => {
    console.log('Setting script loaded');

    SettingFormHandler.get().loopThroughSettings();

    $('#settingsform').on('submit', function (ev) {
        ev.preventDefault();
        //I had an issue that the forms were submitted in geometrical progression after the next submit.
        // This solved the problem.
        ev.stopImmediatePropagation();
    });
})
