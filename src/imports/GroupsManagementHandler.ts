/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

require("jquery-ui/ui/widgets/sortable");

export default class GroupsManagementHandler {

	private static instance: GroupsManagementHandler;

	public static setup(): GroupsManagementHandler {
		console.log('Initializing sendent groups lists');

		if (!this.instance) {
			this.instance = new GroupsManagementHandler();
		}

		// Activates group lists filters
		$("#ncGroupsFilter").on( "keyup", function() {
			const value = $(this).val()!.toString().toLowerCase()
			$("#ncGroups li").each(function() {
				$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
			})
		})
		$("#sendentGroupsFilter").on( "keyup", function() {
			const value = $(this).val()!.toString().toLowerCase()
			$("#sendentGroups li").each(function() {
				$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
			})
		})

		// Makes the Sendent groups lists sortable
		$("#ncGroups").sortable({
			items: "li:not(.ui-state-disabled",
			connectWith: ".connectedSortable"
		})
		$("#sendentGroups").sortable({
			connectWith: ".connectedSortable",
			update: () => this.instance.updateGroupLists()
		})
		$("#defaultGroup").sortable({
			cancel: ".unsortable",
		})

		// Action for the "Remind users" button
		$("#btnRemindUsers").on('click', (ev) => {
            ev.preventDefault();
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sendReminder');
			return axios.get(url);
        });


		return this.instance;
	}

	private updateGroupLists() {

		// Disable sortable attribute for NC groups that have been deleted
		$('#ncGroups li').filter(function() {return this.innerHTML.endsWith('*** DELETED GROUP ***')}).addClass('ui-state-disabled')

		// Get the list of sendent groups from the UI
		// TODO: Rewrite the selection with a each()
		const li = $('#sendentGroups li');
		const newSendentGroups = Object.values(li).map(htmlElement => htmlElement.dataset?.gid).filter(text => text !== undefined);

		// Update backend
		console.log('Updating backend with groups: ' +  newSendentGroups);
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/activeGroups');
		return axios.post(url, {newSendentGroups});

	}
}
