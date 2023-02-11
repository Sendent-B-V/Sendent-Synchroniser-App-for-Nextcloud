import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default class CalDavCalls {
    private endpoint: string;

    constructor() {
        this.endpoint = generateUrl('/apps/sendentsynchroniser/api/1.0/caldav');
    }

    public async getGroupMemberships(username: string): Promise<any> {
        const response = await axios.get(this.endpoint + '/groupmembership/' + username);

        return response.data;
    }

    
}
