import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import intlTelInput from 'intl-tel-input';

export default class PhoneInputComponent extends Component {
    @service fetch;
    @tracked iti;

    @action setupIntlTelInput(element) {
        this.iti = intlTelInput(element, {
            containerClass: `w-full ${this.args.wrapperClass ?? ''}`,
            initialCountry: 'NG',
            separateDialCode: true,
            formatAsYouType: true,
            geoIpLookup: (success, failure) => {
                this.fetch
                    .get('lookup/whois')
                    .then((response) => {
                        success(response.country_code);
                    })
                    .catch(failure);
            },
            utilsScript: '/assets/libphonenumber/utils.js',
        });

        if (typeof this.args.onInit === 'function') {
            //this.args.onInit(this.iti);
            this.args.onInit(this);
        }

        element.addEventListener('countrychange', this.args.onCountryChange);
    }

    // @action onInput() {
    //     const { onInput } = this.args;
    //     const number = this.iti.getNumber(intlTelInput.utils.numberFormat.E164);

    //     if (typeof onInput === 'function') {
    //         onInput(number, ...arguments);
    //     }
    // }

    @action
    onKeyDown(event) {
        const input = event.target;
        const digits = input.value.replace(/\D/g, '');
    
        // Allow navigation keys
        const allowedKeys = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'];
        if (allowedKeys.includes(event.key)) {
            return;
        }
    
        // Block non-digit keys
        if (!/\d/.test(event.key)) {
            event.preventDefault();
            return;
        }
    
        // Block typing if already 10 digits
        if (digits.length >= 15) {
            event.preventDefault();
        }
    }
}
