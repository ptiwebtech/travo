import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { isBlank } from '@ember/utils';

export default class OperationsServiceRatesIndexNewController extends BaseController {
    @service store;
    @service notifications;
    @service intl;
    @service loader;
    @service hostRouter;

    /**
     * Inject the `modalsManager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * The service rate being created.
     *
     * @var {ServiceRateModel}
     */
    @tracked serviceRate = this.store.createRecord('service-rate', { per_meter_unit: 'm'});

    /**
     * Available order configs.
     *
     * @var {Array}
     */
    @tracked orderConfigs = [];

    /**
     * Service areas.
     *
     * @var {Array}
     */
    @tracked serviceAreas = [];

    /**
     * Zones.
     *
     * @var {Array}
     */
    @tracked zones = [];

    /**
     * True if creating service rate.
     *
     * @var {Boolean}
     */
    @tracked isCreatingServiceRate = false;

    /**
     * The current selected order config.
     *
     * @var {OrderConfigModel|null}
     */
    @tracked orderConfig;

    /**
     * True if updating service rate.
     *
     * @var {Boolean}
     */
    @tracked isUpdatingServiceRate = false;

    /**
     * Dimension units.
     *
     * @var {Array}
     */
    dimensionUnits = ['cm', 'in', 'ft', 'mm', 'm', 'yd'];

    /**
     * Weight units.
     *
     * @var {Array}
     */
    weightUnits = ['g', 'oz', 'lb', 'kg'];

    /**
     * Rate calculation methods
     *
     * @var {Array}
     */
    calculationMethods = [
        { name: 'Fixed Meter', key: 'fixed_meter' },
        { name: 'Per Meter', key: 'per_meter' },
        { name: 'Per Drop-off', key: 'per_drop' },
        { name: 'Algorithm', key: 'algo' },
        { name: 'Ticket', key: 'ticket' },
        { name: 'Fixed Rate', key: 'fixed_rate' },
        { name: 'Freight Charge', key: 'freight_charge' },
        { name: 'Call Out Rate', key: 'call_out_rate' },
        { name: 'Stopover', key: 'stopover' },
    ];

    /**
     * COD Fee calculation methods
     *
     * @var {Array}
     */
    codCalculationMethods = [
        { name: 'Flat Fee', key: 'flat' },
        { name: 'Percentage', key: 'percentage' },
    ];

    /**
     * Peak hour fee calculation methods
     *
     * @var {Array}
     */
    peakHourCalculationMethods = [
        { name: 'Flat Fee', key: 'flat' },
        { name: 'Percentage', key: 'percentage' },
    ];

    /**
     * The applicable distance units for calculation.
     *
     * @var {Array}
     */
    distanceUnits = [
        { name: 'Meter', key: 'm' },
        { name: 'Kilometer', key: 'km' },
    ];

    /**
     * By km max distance set
     *
     * @var {String}
     */
    @tracked fixedMeterUnit = 'km';

    /**
     * By km max distance set
     *
     * @var {Integer}
     */
    @tracked fixedMeterMaxDistance = 5;

    /**
     * Mutable rate fee's.
     *
     * @var {Array}
     */
    @tracked _rateFees = [];

    @tracked showSecondaryRateFields = false;

    @tracked uploadQueue = [];
    @tracked uploadedFiles = [];
    acceptedFileTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-flv',
        'video/x-ms-wmv',
        'audio/mpeg',
        'video/x-msvideo',
        'application/zip',
        'application/x-tar',
    ];
    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `fileQueue` service
     *
     * @var {Service}
    */
    @service fileQueue;

    /**
     * The current ability permission based on record id.
     *
     * @readonly
     * @memberof OperationsServiceRatesIndexNewController
     */
    @computed('serviceRate.id') get abilityPermission() {
        return this.serviceRate.id ? 'fleet-ops update service-rate' : 'fleet-ops create service-rate';
    }

    /**
     * The rate feess for per km
     *
     * @var {Array}
     */
    @computed('fixedMeterMaxDistance', 'fixedMeterUnit', 'serviceRate.currency', '_rateFees') get rateFees() {
        if (!isBlank(this._rateFees)) {
            return this._rateFees;
        }

        let maxDistance = parseInt(this.fixedMeterMaxDistance ?? 0);
        let distanceUnit = this.fixedMeterUnit;
        let currency = this.serviceRate.currency;
        let rateFees = [];

        for (let distance = 0; distance < maxDistance; distance++) {
            rateFees.pushObject({
                distance,
                distance_unit: distanceUnit,
                fee: 0,
                currency,
            });
        }

        return rateFees;
    }

    /** setter for rate fee's */
    set rateFees(rateFees) {
        this._rateFees = rateFees;
    }

    /**
     * Mutable per drop-off rate fee's.
     *
     * @var {Array}
     */
    @tracked perDropRateFees = this.serviceRate.isNew
        ? [
              {
                  min: 1,
                  max: 5,
                  fee: 0,
                  unit: 'waypoint',
                  currency: this.serviceRate.currency,
              },
          ]
        : this.serviceRate.rate_fees.toArray();

    /**
     * Default parcel fee's
     *
     * @var {Array}
     */
    @tracked parcelFees = [
        {
            size: 'small',
            length: 34,
            width: 18,
            height: 10,
            dimensions_unit: 'cm',
            weight: 2,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'medium',
            length: 34,
            width: 32,
            height: 10,
            dimensions_unit: 'cm',
            weight: 4,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'large',
            length: 34,
            width: 32,
            height: 18,
            dimensions_unit: 'cm',
            weight: 8,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
        {
            size: 'x-large',
            length: 34,
            width: 32,
            height: 34,
            dimensions_unit: 'cm',
            weight: 13,
            weight_unit: 'kg',
            fee: 0,
            currency: this.serviceRate.currency,
        },
    ];
    /**
     * Adds a per drop-off rate fee
     */
    @action addPerDropoffRateFee() {
        const rateFees = this.perDropRateFees;
        const currency = this.serviceRate.currency;

        const min = rateFees.lastObject?.max ? rateFees.lastObject?.max + 1 : 1;
        const max = min + 5;

        rateFees.pushObject({
            min: min,
            max: max,
            unit: 'waypoint',
            fee: 0,
            currency,
        });
    }

    @action setConfig(event) {
        const orderConfigId = event.target.value;
        if (!orderConfigId) {
            return;
        }

        const orderConfig = this.store.peekRecord('order-config', orderConfigId);
        if (orderConfig) {
            this.orderConfig = orderConfig;
            this.serviceRate.set('order_config_uuid', orderConfig.id);
            this.serviceRate.set('service_type', orderConfig.key);
        }
    }

    /**
     * Adds a per drop-off rate fee
     */
    @action removePerDropoffRateFee(index) {
        this.perDropRateFees.removeAt(index);
    }

    /**
     * Saves the service rate to server
     *
     * @void
     */
    @action async createServiceRate() {
        const { serviceRate, rateFees, parcelFees } = this;
        const vendorSelectElement = document.getElementById('vendor-select');
        const selectedVendorId = vendorSelectElement?.value;

        serviceRate.setServiceRateFees(rateFees).setServiceRateParcelFees(parcelFees);

        if (serviceRate.isPerDrop) {
            serviceRate.clearServiceRateFees().setServiceRateFees(this.perDropRateFees);
        }

        this.isCreatingServiceRate = true;
        this.loader.showLoader('.overlay-inner-content', { loadingMessage: 'Creating service rate...' });
        
        try {
            const response = await serviceRate.save();
            //new code 2 jan 2026
            const serviceUUID = response.id;
            const filesToLink = serviceRate.files.toArray();
            if (filesToLink.length > 0) {
                const filePromises = filesToLink.map(file => {
                    file.setProperties({
                        subject_uuid: serviceUUID,
                        subject_type: 'service_file'
                    });
                    return file.save();
                });
                await Promise.all(filePromises);
            }
            //end code
            const apiData = {
                service_rate_id: response.public_id,
                vendor_id: selectedVendorId,
            };
            const vendordata = await fetch('https://african.land/travo_api/insert_service_vendor_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(apiData),
            });
            this.isCreatingServiceRate = false;
            this.loader.removeLoader();
            return this.transitionToRoute('operations.service-rates.index').then(() => {
                this.notifications.success(this.intl.t('fleet-ops.operations.service-rates.index.new.success-message', { serviceName: serviceRate.service_name }));
                this.resetForm();
                //return this.hostRouter.refresh();
            });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Select a service area and load it's zones
     *
     * @param {String} serviceAreaId
     * @memberof OperationsServiceRatesIndexNewController
     */
    @action selectServiceArea(serviceAreaId) {
        if (typeof serviceAreaId === 'string' && !isBlank(serviceAreaId)) {
            this.serviceRate.service_area_uuid = serviceAreaId;

            // load zones for this service area
            this.store.query('zone', { service_area_uuid: serviceAreaId }).then((zones) => {
                this.zones = zones;
            });
        } else {
            this.zones = [];
        }
    }

    /**
     * Resets the service rate form
     *
     * @void
     */
    @action resetForm() {
        this.serviceRate = this.store.createRecord('service-rate');
        this.byKmMaxDistance = 5;
        this.rateFees = this.rateFees.map((rateFee) => ({ ...rateFee, fee: 0 }));
        this.parcelFees = this.parcelFees.map((parcelFee) => ({
            ...parcelFee,
            fee: 0,
            dimensions_unit: 'cm',
            weight_unit: 'kg',
        }));
    }

    /**
     * Handle back button action
     *
     * @return {Transition}
     */
    @action transitionBack() {
        return this.transitionToRoute('operations.service-rates.index').then(() => {
            this.resetForm();
        });
    }


    @action
    viewFile(file) {
        if (file.content_type && file.content_type.startsWith('image/')) {
            this.modalsManager.show('modals/image-viewer', {
                title: file.original_filename,
                imageSource: file.url,
                acceptButtonText: 'Done',
                hideDeclineButton: true,
                onClose: (modal) => {
                    this.modalsManager.done(modal);
                }
            });
        } else {
            window.open(file.url, '_blank');
        }
    }

    @action queueFile(file) {
        // since we have dropzone and upload button within dropzone validate the file state first
        // as this method can be called twice from both functions
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) {
            return;
        }

        // Queue and upload immediatley
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: 'uploads/service-files',
                type: 'service_file',
            },
            async (uploadedFile) => {
                const fileRecord = this.store.push(this.store.normalize('file', uploadedFile));
                this.serviceRate.files.pushObject(fileRecord);
                if (this.serviceRate.id) {
                    fileRecord.setProperties({
                        subject_uuid: this.serviceRate.id,
                        subject_type: 'service_file'
                    });
                    await fileRecord.save();
                }
                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);
            }
        );
    }

    @action removeFile(file) {
        return file.destroyRecord();
    }

    get displayBaseFee() {
        return (this.serviceRate.base_fee / 100).toFixed(2);
    }

    get displayPerMeterRate() {
        return (this.serviceRate.per_meter_flat_rate_fee / 100).toFixed(2);
    }

    get displayPerMeterRate2() {
        return (this.serviceRate.secondary_per_meter_rate / 100).toFixed(2);
    }

    @action
    toggleSecondaryRateFields(event) {
        this.showSecondaryRateFields = event.target.checked;
        
        // If unchecked, you might want to clear the values on the model
        if (!this.showSecondaryRateFields) {
           
        }
    }
}
