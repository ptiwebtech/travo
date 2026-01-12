import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class DriverFormPanelComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service currentUser;
    @service notifications;
    @service hostRouter;
    @service contextPanel;
    @service modalsManager;
    @service universe;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * Status options for drivers.
     * @type {Array}
     */
    @tracked driverStatusOptions = ['active', 'pending'];

    /**
     * The coordinates input component instance.
     * @type {CoordinateInputComponent}
     */
    @tracked coordinatesInputComponent;

    /**
     * Permission needed to update or create record.
     *
     * @memberof DriverFormPanelComponent
     */
    @tracked savePermission;

    @tracked cityOptions = [];

    /**
     * Action to create a new user quickly
     *
     * @memberof DriverFormPanelComponent
     */
    userAccountActionButtons = [
        {
            text: 'Create new user',
            icon: 'user-plus',
            size: 'xs',
            permission: 'iam create user',
            onClick: () => {
                const user = this.store.createRecord('user', {
                    status: 'pending',
                    type: 'user',
                });

                this.modalsManager.show('modals/user-form', {
                    title: 'Create a new user',
                    user,
                    formPermission: 'iam create user',
                    uploadNewPhoto: (file) => {
                        this.fetch.uploadFile.perform(
                            file,
                            {
                                path: `uploads/${this.currentUser.companyId}/users/${user.slug}`,
                                key_uuid: user.id,
                                key_type: 'user',
                                type: 'user_photo',
                            },
                            (uploadedFile) => {
                                user.setProperties({
                                    avatar_uuid: uploadedFile.id,
                                    avatar_url: uploadedFile.url,
                                    avatar: uploadedFile,
                                });
                            }
                        );
                    },
                    confirm: async (modal) => {
                        modal.startLoading();

                        try {
                            await user.save();
                            this.notifications.success('New user created successfully!');
                            modal.done();
                        } catch (error) {
                            this.notifications.serverError(error);
                            modal.stopLoading();
                        }
                    },
                });
            },
        },
    ];

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { driver = null }) {
        super(...arguments);
        this.driver = driver;
        if (this.driver && this.driver.isNew) {
            this.driver.internal_id = this.generateDrvCode();
        }
        this.savePermission = driver && driver.isNew ? 'fleet-ops create driver' : 'fleet-ops update driver';
        applyContextComponentArguments(this);

        if(driver && driver.isNew) {
            this.onCountryChange('NG','lagos');
        } else {
            this.onCountryChange(this.driver.country,this.driver.city);
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
     * Task to save driver.
     *
     * @return {void}
     * @memberof DriverFormPanelComponent
     */
    @task *save() {
        contextComponentCallback(this, 'onBeforeSave', this.driver);

        try {
            this.driver.city = this.driver.city.name;
            console.log(this.driver);
            this.driver = yield this.driver.save();
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        this.hostRouter.refresh();
        this.notifications.success(this.intl.t('fleet-ops.component.driver-form-panel.success-message', { driverName: this.driver.name }));
        this.universe.trigger('fleet-ops.driver.saved', this.driver);
        contextComponentCallback(this, 'onAfterSave', this.driver);
    }

    /**
     * Uploads a new photo for the driver.
     *
     * @param {File} file
     * @memberof DriverFormPanelComponent
     */
    @action onUploadNewPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.currentUser.companyId}/drivers/${this.driver.id}`,
                subject_uuid: this.driver.id,
                subject_type: 'fleet-ops:driver',
                type: 'driver_photo',
            },
            (uploadedFile) => {
                this.driver.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                    photo: uploadedFile,
                });
            }
        );
    }

    /**
     * View the details of the driver.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.driver);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.driver, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.driver);
    }

    /**
     * Handles the selection from an autocomplete. Updates the place properties with the selected data.
     * If a coordinates input component is present, updates its coordinates too.
     *
     * @action
     * @param {Object} selected - The selected item from the autocomplete.
     * @param {Object} selected.location - The location data of the selected item.
     * @memberof DriverFormPanelComponent
     */
    @action onAutocomplete({ location }) {
        if (location) {
            this.driver.set('location', location);
            if (this.coordinatesInputComponent) {
                this.coordinatesInputComponent.updateCoordinates(location);
            }
        }
    }

    /**
     * Sets the coordinates input component.
     *
     * @action
     * @param {Object} coordinatesInputComponent - The coordinates input component to be set.
     * @memberof DriverFormPanelComponent
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
     * @memberof DriverFormPanelComponent
     */
    @action onCoordinatesChanged({ latitude, longitude }) {
        const location = new Point(longitude, latitude);
        this.driver.setProperties({ location });
    }

    
    @action
    async onCountryChange(selectedCountry, selectedCity = null) {
        this.driver.country = selectedCountry;
        console.log('Selected country:', selectedCountry);

        try {
            let response = await fetch('https://countriesnow.space/api/v0.1/countries/cities', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ iso2: selectedCountry }),
            });

            let data = await response.json();
            console.log('Cities response:', data);

            if (data.error === false && Array.isArray(data.data) && data.data.length > 0) {
                this.cityOptions = data.data.map(city => ({
                    name: city,
                    id: city
                }));

                if (typeof selectedCity === 'string' && selectedCity.trim() !== '') {
                    const cityMatch = this.cityOptions.find(
                        city => city.name.toLowerCase() === selectedCity.toLowerCase()
                    );
                    if (cityMatch) {
                        this.driver.city = cityMatch; // Update only with city name
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

    @action generateDrvCode() {
        const randomNum = Math.floor(Math.random() * 1000000); // Generates a random number between 0 and 999999
        const paddedNum = String(randomNum).padStart(6, '0');  // Pads with leading zeros to ensure 6 digits
        const drvCode = `DRV${paddedNum}`;
        return drvCode;
    }



}
