/* eslint-disable @nextcloud/no-deprecations */
import { translate as t, translatePlural as p } from '@nextcloud/l10n';

export default class MultiInputList {
    constructor(private container: JQuery<HTMLElement>, value: string, private target: JQuery<HTMLElement>) {
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
        const valueElement = $('<input type="text" placeholder="Enter username to enable synchronisation for"/>');
        const deleteElement = $('<button type="button"><span class="icon-delete icon-visible"></span></button>');

        valueElement.val(value);
        valueElement.on('change', () => this.updateValue());
        valueElement.on('input', () => {
            if (rowElement.is(':last-child')) {
                this.appendInput();
            }
        })
        valueElement.appendTo(rowElement);


        deleteElement.on('click', () => {
            rowElement.remove();

            if (this.container.find('input').length === 0) {
                this.appendInput();
            }

            this.updateValue();
        });
        deleteElement.appendTo(rowElement);

        rowElement.appendTo(this.container);
    }

    private updateValue() {
        const changedValues = this.container.find('input').map((_, inputElement) => $(inputElement).val()).get();
        const newValue = changedValues.map(value => value.toString().trim()).filter(value => !!value).join(';');

        this.target.val(newValue).trigger('change');
    }
}
