/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { translate as t } from '@nextcloud/l10n'

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
		}).find( "li" )
		.on( "click", this.instance.showSettingsForGroup)
		$("#sendentGroups").sortable({
			connectWith: ".connectedSortable",
			update: () => this.instance.updateGroupLists()
		}).find( "li" )
		.on( "click", this.instance.showSettingsForGroup)
		$("#defaultGroup").sortable({
			cancel: ".unsortable",
		}).find( "li" )
		.on( "click", this.instance.showSettingsForGroup)

		return this.instance;
	}

	private showSettingsForGroup(event) {

		// Don't do anything if the clicked group is not a Sendent group
		if (event.target.parentNode.id === "ncGroups") {
			return;
		}

		// Unselect all other previously selected groups
		$('#groupsManagement div ul li').each(function() {
			if (this !== event.target) {
				$(this).removeClass('ui-selected');
			} else {
				$(this).addClass('ui-selected');
			}
		});

		// Gets group for which settings are to be shown
		let ncgroupDisplayName = event.target.textContent
		const ncgroupGid = event.target.dataset.gid;

		// Changes currently selected group information
		$('#currentGroup').text(ncgroupDisplayName);

		// Default should be the empty string
		ncgroupDisplayName = ncgroupDisplayName === t('sendent', 'Default') ? '' : ncgroupDisplayName;

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
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/groups/updateFromNewList');
		return axios.post(url, {newSendentGroups});

	}
}
