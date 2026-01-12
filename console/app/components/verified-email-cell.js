import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class VerifiedEmailCellComponent extends Component {
    @tracked isCopied = false;

    @action copyToClipboard(value) {
        if (!value) return;
        navigator.clipboard.writeText(value).then(() => {
            this.isCopied = true;
            setTimeout(() => { this.isCopied = false; }, 2000);
        });
    }
}