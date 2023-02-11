import { generateOcsUrl, generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';

export interface GroupItem {
    id: string,
    users: Array<UserItem>,

}
export interface UserItem {
    name: string
}
class API {
    public async getGroups() {
        const url = generateUrl('apps/sendentsynchroniser/api/1.0/groups');
        const response = await axios.get<GroupItem[]>(url);

        return response.data;
    }
}