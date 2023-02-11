import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default class GroupCalls {
    private endpoint: string;

    constructor() {
        this.endpoint = generateUrl('/apps/sendentsynchroniser/api/1.0/groups');
    }

    public async list(): Promise<any> {
        const response = await axios.get(this.endpoint);

        return response.data;
    }

    
}
