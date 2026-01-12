import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';
import { task } from 'ember-concurrency';

export default class CountrySelectComponent extends Component {
    @service fetch;
    @tracked countries = [];
    @tracked selected;
    @tracked disabled = false;
    @tracked value;
    @tracked id = guidFor(this);

    constructor(owner, { value = null, disabled = false }) {
        super(...arguments);
        this.disabled = disabled;
        this.value = value;
        this.fetchCountries.perform(value);
    }

    @task *fetchCountries(value = null) {
        try {
            this.countries = yield this.fetch.get('lookup/countries', { columns: ['name', 'cca2', 'flag', 'emoji'] });
            

      
        const excludedCountries = [
            'Dhekelia', 'Somaliland', 'USNB Guantanamo Bay', 'Brazilian I.', 'N. Cyprus',
            'Cyprus U.N. Buffer Zone', 'Siachen Glacier', 'Baikonur', 'Akrotiri', 
            'Southern Patagonian Ice Field', 'Bir Tawil', 'Indian Ocean Ter.', 
            'Coral Sea Is.', 'Spratly Is.', 'Clipperton I.', 'Ashmore and Cartier Is.',
            'Bajo Nuevo Bank', 'Serranilla Bank', 'Scarborough Reef'
        ];

        // Filter out the unwanted countries
        this.countries = this.countries.filter(country => !excludedCountries.includes(country.name));


      console.log("Fetched country names:");
        this.countries.forEach(country => {
            console.log(country.name);
        });



            if (!value) {
                this.selected = this.findCountry('NG'); // ISO code for Nigeria
            } else {
                this.selected = this.findCountry(value);
            }
        } catch (error) {
            this.countries = [];
        }
    }

    @action changed(value) {
        const country = this.findCountry(value);

        if (country) {
            this.selectCountry(country);
        }
    }

    @action listenForInputChanges(element) {
        setInterval(() => {
            const { value } = element;

            if (this.value !== value) {
                this.value = value;
                this.changed(value);
            }
        }, 100);
    }

    @action selectCountry(country) {
        const { onChange } = this.args;
        this.selected = country;

        if (typeof onChange === 'function') {
            onChange(country.cca2, country);
        }
    }

    findCountry(iso2) {
        if (typeof iso2 === 'string') {
            return this.countries.find((country) => country.cca2 === iso2.toUpperCase());
        }

        return null;
    }
}
