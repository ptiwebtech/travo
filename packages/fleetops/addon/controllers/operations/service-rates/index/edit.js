import OperationsServiceRatesIndexNewController from './new';
import { action } from '@ember/object';

export default class OperationsServiceRatesIndexEditController extends OperationsServiceRatesIndexNewController {
    /**
     * Updates the service rate to server
     *
     * @void
     */
    @action async updateServiceRate() {
        const { serviceRate, rateFees, perDropRateFees, parcelFees, selectedVendor } = this;

        if (serviceRate.isFixedMeter) {
            serviceRate.setServiceRateFees(rateFees);
        }

        if (serviceRate.isPerDrop) {
            serviceRate.setServiceRateFees(perDropRateFees);
        }

        if (serviceRate.isParcelService) {
            serviceRate.setServiceRateParcelFees(parcelFees);
        }

        if (selectedVendor) {
            serviceRate.set('vendor_uuid', selectedVendor.id);
        }

        this.isUpdatingServiceRate = true;
        this.loader.showLoader('.overlay-inner-content', { loadingMessage: 'Updating service rate...' });

        try {

            const response = await serviceRate.save();
            this.isUpdatingServiceRate = false;
            this.loader.removeLoader();
            return this.transitionToRoute('operations.service-rates.index').then(() => {
                this.notifications.success(this.intl.t('fleet-ops.operations.service-rates.index.edit.success-message', { serviceName: serviceRate.service_name }));
                this.resetForm();
            });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
