/* eslint-disable @nextcloud/no-deprecations */
import { translate as t, translatePlural as p } from '@nextcloud/l10n';
import {API} from '../common/api';
import {UserItem} from '../common/UserItem';
import {GroupItem} from '../common/GroupItem';
export default class MultiInputList {

    private apiCalls: API;

    constructor(private container: JQuery<HTMLElement>, value: string, private target: JQuery<HTMLElement>) {
        this.apiCalls = new API();
        const values = value.split(';').map(value => value.trim());

        this.appendListToggle();

        for (const value of values) {
            this.appendInput(value);
        }

        if (values[values.length - 1]) {
            this.appendInput();
        }

        this.target.hide();
    }

    private appendListToggle(): void {
        const element = $('<a>');
        const updateLabel = () => {
            const targetValue = this.target.val()?.toString() || '';
            const numberOfEntries = targetValue ? targetValue.split(';').length : 0;

            element.text(this.container.hasClass('collapsed') ?
                (
                    numberOfEntries > 0 ?
                        n('sendent', 'Show %n entry', 'Show %n entries', numberOfEntries)
                        :
                        t('sendent', 'Add new entry')
                )
                :
                n('sendent', 'Hide entry', 'Hide entries', numberOfEntries)
            );
        };

        this.container.addClass('collapsed');
        updateLabel();

        element.appendTo(this.container);
        element.on('click', (ev) => {
            ev.preventDefault();

            this.container.toggleClass('collapsed');

            updateLabel();
        });
    }

    private appendInput(value = '') {
        const rowElement = $('<div class="multiInputRow">');
        const valueElement = $('<p id="value-element" >'+value+'</p>');
        const checkboxElement = $('<input type="checkbox"></input>');
        valueElement.val(value);
        valueElement.on('change', () => this.updateValue());

        checkboxElement.on('click', () => {
            // TODO: Api call to php backend to disable group synchronisation
            if (this.container.find('#value-element').length === 0) {
                this.appendInput();
            }

            this.updateValue();
        });
        valueElement.on('click', async () => {
            // TODO: Api call to get users and 
            const userListElement = $('<ul id="usersForGroup" />')
            const userList = await this.apiCalls.getUsersForGroup(value);

            userList.forEach(group => {
                console.log(group.users.length + ' users retreived from api for group: ' + value);
                group.users.forEach(user => {
                    const userElement = $('<li class="list-item" id="useritem-"' + user.name + '">' + user.name + '</li>');
                    userElement.appendTo(userListElement);
                    console.log('User added to elementlist: ' + user.name);
                });
                
            });
            userListElement.appendTo(this.container);
            this.updateValue();
        });
        checkboxElement.appendTo(rowElement);
        valueElement.appendTo(rowElement);

        rowElement.prependTo(this.container);
    }

    private updateValue() {
        // const changedValues = this.container.find('#value-element').map((_, inputElement) => $(inputElement).val()).get();
        // const newValue = changedValues.map(value => value.toString().trim()).filter(value => !!value).join(';');

        // this.target.val(newValue).trigger('change');
    }
}
