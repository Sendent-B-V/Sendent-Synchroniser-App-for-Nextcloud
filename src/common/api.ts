import { generateOcsUrl, generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import {UserItem} from "../common/UserItem";
import {GroupItem} from "../common/GroupItem";



export class API {

    public async getGroups() : Promise<GroupItem[]> {
        const url = generateUrl('apps/sendentsynchroniser/api/1.0/groups');
        const response = await axios.get<GroupItem[]>(url);

        return response.data;
    }
    public async getUsersForGroup(groupid: string): Promise<GroupItem[]> {
        const url = generateUrl('apps/sendentsynchroniser/api/1.0/groups/' + groupid + '/users');
        const response = await axios.get<GroupItem[]>(url);

        return response.data;
    }
}