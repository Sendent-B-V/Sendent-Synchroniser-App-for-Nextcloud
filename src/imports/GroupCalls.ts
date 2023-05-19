import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default class GroupCalls {
    private endpoint: string;

    constructor() {
        this.endpoint = generateUrl('/apps/sendentsynchroniser/api/1.0/groups');
    }

    public async listExternalGroups(): Promise<any> {
        const response = await axios.get(this.endpoint + '/external');

        return response.data;
    }
    public async listUsersByGroup(gropuId: string): Promise<any> {
        const response = await axios.get(this.endpoint + '/' + gropuId);
        return response.data;
    }
    public async listSyncGroups(): Promise<any> {
        const response = await axios.get(this.endpoint + '/sync');
        return response.data;
    }
}
