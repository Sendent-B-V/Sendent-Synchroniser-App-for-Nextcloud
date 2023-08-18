import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import {GroupItem} from "./GroupItem";

export class API {

    public async getExternalGroups() : Promise<GroupItem[]> {
        const url = generateUrl('apps/sendentsynchroniser/api/1.0/groups/external');
        const response = await axios.get<GroupItem[]>(url);

        return response.data;
    }

    public async getUsersForGroup(groupid: string): Promise<GroupItem[]> {
        const url = generateUrl('apps/sendentsynchroniser/api/1.0/groups/' + groupid + '/users');
        const response = await axios.get<GroupItem[]>(url);

        return response.data;
    }
}
