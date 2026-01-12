import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task } from 'ember-concurrency';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class PlaceFormPanelComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service notifications;
    @service hostRouter;
    @service contextPanel;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * The coordinates input component instance.
     * @type {CoordinateInputComponent}
     */
    @tracked coordinatesInputComponent;

    /**
     * All possible place types
     *
     * @var {String}
     */
    @tracked placeTypes = ['place', 'customer'];

    /**
     * Permission needed to update or create record.
     *
     * @memberof DriverFormPanelComponent
     */
    @tracked savePermission;

    @tracked cityOptions = [];

 @tracked place = {
    name: '',
    street1: '',
    city: '',
    country: '',
    latitude: null,
    longitude: null,
    postalCode: ''
  };

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { place = null }) {
        super(...arguments);
        this.place = place;
        this.savePermission = place && place.isNew ? 'fleet-ops create place' : 'fleet-ops update place';
        applyContextComponentArguments(this);
        if(place && place.isNew) {
            this.onCountryChange('NG','lagos');
        } else {
            this.onCountryChange(this.place.country,this.place.city);
        }
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Task to save place.
     *
     * @return {void}
     * @memberof PlaceFormPanelComponent
     */
    @task *save() {
        contextComponentCallback(this, 'onBeforeSave', this.place);
        try {
            const latitude = parseFloat($('.coordinates-input .ember-text-field:nth-child(1)').val());
            const longitude = parseFloat($('.coordinates-input .ember-text-field:nth-child(2)').val());
            const location = new Point(longitude, latitude);
            this.place.setProperties({
                location: location,
                city: $('.city_section_select .ember-power-select-selected-item').text().trim() || this.place.city || '',
                street1: $('.placestreet1').val() || this.place.street1 || '',
                street2: $('.placestreet2').val() || this.place.street2 || '',
                neighborhood: $('.neighborhood_get_value .ember-text-field').val() || this.place.neighborhood || '',
                building: $('.building_get_value .ember-text-field').val() || this.place.building || '',
                postal_code: $('.postal_code_select .ember-text-field').val() || this.place.postalCode || '',
                province: $('.state_get_value').val() || this.place.province || '',
                country: $('.country_section_select input.ember-text-field').data('county') || this.place.country || '',
                country_name: $('.country_section_select .ember-power-select-selected-item span:nth-child(2)').text().trim() || this.place.country_name || '',
                type: $('#place-type').val() || this.place.type || '',
            });
            this.place = yield this.place.save();
            this.saveEmail(this.place.public_id);
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }
        this.notifications.success(this.intl.t('fleet-ops.component.place-form-panel.success-message', { placeAddress: this.place.address }));
        contextComponentCallback(this, 'onAfterSave', this.place);
    }

    saveEmail(placeId) {
        const email = $('.place-email').val() || null;
        if (!email) {
            console.warn('No email provided for saving.');
            return;
        }
        const placeDataToInsert = {
            place_id: placeId,
            email: email,
        };
        fetch('https://african.land/travo_api/insert_place.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(placeDataToInsert),
        });
    }

    /**
     * View the details of the place.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.place);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.place, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.place);
    }

    /**
     * Handles the selection from an autocomplete. Updates the place properties with the selected data.
     * If a coordinates input component is present, updates its coordinates too.
     *
     * @action
     * @param {Object} selected - The selected item from the autocomplete.
     * @param {Object} selected.location - The location data of the selected item.
     * @memberof PlaceFormPanelComponent
     */
    @action onAutocomplete(selected) {
        this.place.setProperties({
            ...selected,
            latitude: selected.location.latitude || null,
            longitude: selected.location.longitude || null,
            country: selected.country || '',
            city: selected.city || '',
            postalCode: selected.postalCode || '',
            neighborhood: selected.neighborhood || '',
            building: selected.building || '',
            securityCode: selected.securityCode || '',
            state: selected.state || ''
        });
    
        // Log the entire selected object and relevant data for debugging
        console.log('Selected data:', selected);
        console.log('Selected location:', selected.location);
        console.log('Complete place data:', this.place);    
        if (this.coordinatesInputComponent) {
            this.coordinatesInputComponent.updateCoordinates(selected.location);
        }
    }

     /*@action
    async onAutocomplete(selected) {
        // Set the selected properties on `place`
        this.place.setProperties({ ...selected });
        console.log('Selected data:', selected);

        // Call onInputChange with the selected address and retrieve geolocation data
        const geoData = await this.onInputChange(selected.address); // Assuming `selected.address` holds the address string

        if (geoData) {
            // Use jQuery to set the values in the DOM
            $('.coordinates-input .ember-text-field:nth-child(1)').val(geoData.latitude);
            $('.coordinates-input .ember-text-field:nth-child(2)').val(geoData.longitude);
            $('.city_section_select .ember-power-select-selected-item').text(geoData.city);
            $('.country_section_select .ember-power-select-selected-item span:nth-child(2)').text(geoData.country);
            $('.postal_code_select .ember-text-field').val(geoData.postalCode);
            $('.neighborhood_get_value .ember-text-field').val(geoData.neighborhood);
            $('.building_get_value .ember-text-field').val(geoData.building);
            $('.security_code_get_value .ember-text-field').val(geoData.securityCode);
            $('.state_get_value').val(geoData.state);

            console.log(`Geolocation data - Country: ${geoData.country}, City: ${geoData.city}, Latitude: ${geoData.latitude}, Longitude: ${geoData.longitude}`);
        }

        // Update coordinates in the component if it exists
        if (this.coordinatesInputComponent && geoData) {
            this.coordinatesInputComponent.updateCoordinates({
                lat: geoData.latitude,
                lng: geoData.longitude
            });
        }
    }*/

    /**
     * Performs reverse geocoding given latitude and longitude. Updates place properties with the geocoding result.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @returns {Promise} A promise that resolves with the reverse geocoding result.
     * @memberof PlaceFormPanelComponent
     */
    @action onReverseGeocode({ latitude, longitude }) {
        return this.fetch.get('geocoder/reverse', { coordinates: [latitude, longitude].join(','), single: true }).then((result) => {
            if (isBlank(result)) {
                return;
            }

            this.place.setProperties({ ...result });
        });
    }

    /**
     * Sets the coordinates input component.
     *
     * @action
     * @param {Object} coordinatesInputComponent - The coordinates input component to be set.
     * @memberof PlaceFormPanelComponent
     */
    @action setCoordinatesInput(coordinatesInputComponent) {
        this.coordinatesInputComponent = coordinatesInputComponent;
    }

    /**
     * Updates the place coordinates with the given latitude and longitude.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @memberof PlaceFormPanelComponent
     */
    @action updatePlaceCoordinates({ latitude, longitude }) {
        const location = new Point(longitude, latitude);

        this.place.setProperties({ location });
    }

    @action
    async onCountryChange(selectedCountry, selectedCity = null) {
        console.log(selectedCity)
        this.place.country = selectedCountry;
        try {
            let response = await fetch('https://countriesnow.space/api/v0.1/countries/cities', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ iso2: selectedCountry }),
            });
    
            let data = await response.json();
    
            if (data.error === false && Array.isArray(data.data) && data.data.length > 0) {
                // Populate city options
                this.cityOptions = data.data.map(city => ({
                    name: city,
                    id: city,
                }));
    
                if (typeof selectedCity === 'string' && selectedCity.trim() !== '') {
                    const cityMatch = this.cityOptions.find(
                        city => city.name.toLowerCase() === selectedCity.toLowerCase()
                    );
                    if (cityMatch) {
                        this.place.city = cityMatch; // Update only with city name
                        console.log('hellloooooo');
                    }
                }
            } else {
                this.cityOptions = [];
                console.warn('No cities found for the selected country.');
            }
        } catch (error) {
            console.error('Error fetching cities:', error);
            this.cityOptions = [];
        }
    }
    // async onCountryChange(selectedCountry){
    //     this.place.country = selectedCountry;
    //     console.log('Selected country:', selectedCountry);

    //     try {
            
    //         let response = await fetch('https://countriesnow.space/api/v0.1/countries/cities', {
    //             method: 'POST',
    //             headers: {
    //                'Content-Type': 'application/json',
    //            },
    //            body: JSON.stringify({iso2: selectedCountry}),
    //         });

    //         let data = await response.json();
    //         console.log('Cities response:', data);

    //         if (data.error === false && Array.isArray(data.data) && data.data.length > 0) {
    //            this.cityOptions = data.data.map(city => ({
    //                name: city,
    //                id: city
    //            }));

    //            // Check if Lagos exists in the city options and set it as default
    //            const lagosCity = this.cityOptions.find(city => city.name.toLowerCase() === 'lagos');
    //            if (lagosCity) {
    //                this.place.city = lagosCity; // Set Lagos as the default city
    //                console.log('Default city set to Lagos');
    //            }
    //        } else {
    //            this.cityOptions = [];
    //            console.warn('No cities found for the selected country.');
    //        }


    //     } catch(error) {
    //        console.error('Error fetching cities:', error);
    //        this.cityOptions = [];

    //     }
    // }
    
    






  @action
  async onInputChange(value) {
    // Ensure you have jQuery loaded before running this code
    const searchAddress = value.trim();
    console.log(searchAddress);

    if (searchAddress) {
        // Call the Geocoding API
        fetch(`https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(searchAddress)}&key=AIzaSyDGjeEtV0FnjiB_zrUuBgeA6d7L5JmFH2Q`)
            .then(response => response.json())
            .then(data => {
                if (data.status === "OK" && data.results[0]) {
                    const result = data.results[0];
                    const latitude = result.geometry.location.lat;
                    const longitude = result.geometry.location.lng;

                    let country = '';
                    let city = '';
                    let postalCode = '';
                    let neighborhood = '';
                    let building = '';
                    let securityCode = '';
                    let state = '';

                    result.address_components.forEach(component => {
                        const types = component.types;
                        if (types.includes("country")) {
                            country = component.long_name;
                        }
                        if (types.includes("locality") || types.includes("administrative_area_level_1")) {
                            city = component.long_name;
                        }
                        if (types.includes("postal_code")) {
                            postalCode = component.long_name;
                        }
                        if (types.includes("neighborhood")) {
                            neighborhood = component.long_name;
                        }
                        if (types.includes("premise")) {
                            building = component.long_name;
                        }
                        if (types.includes("administrative_area_level_1")) {
                            state = component.long_name;
                        }
                        if (types.includes("postal_code_suffix")) {
                            securityCode = component.long_name;
                        }
                    });

                    // Set the values in the DOM using jQuery
                    $('.coordinates-input .ember-text-field:nth-child(1)').val(latitude);
                    $('.coordinates-input .ember-text-field:nth-child(2)').val(longitude);
                    $('.city_section_select .ember-power-select-selected-item').text(city);
                    $('.country_section_select .ember-power-select-selected-item span:nth-child(2)').text(country);
                    $('.postal_code_select .ember-text-field').val(postalCode);
                    $('.neighborhood_get_value .ember-text-field').val(neighborhood);
                    $('.building_get_value .ember-text-field').val(building);
                    $('.security_code_get_value .ember-text-field').val(securityCode);
                    $('.state_get_value').val(state);

                    console.log(`Country: ${country}, City: ${city}, Latitude: ${latitude}, Longitude: ${longitude}`);
                } else {
                    console.error("No results found for this address.");
                }
            })
            .catch(error => {
                console.error("Error fetching geolocation data:", error);
            });
    }
}




}
