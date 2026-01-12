import OperationsServiceRatesIndexNewController from './new';
import { action } from '@ember/object';

export default class OperationsServiceRatesIndexEditController extends OperationsServiceRatesIndexNewController {
    /**
     * Updates the service rate to server
     *
     * @void
     */
    @action async updateServiceRate() {
        const { serviceRate, rateFees, perDropRateFees, parcelFees } = this;
        const vendorSelectElement = document.getElementById('vendor-select');
        const selectedVendorId = vendorSelectElement?.value;

        if (serviceRate.isFixedMeter) {
            serviceRate.setServiceRateFees(rateFees);
        }

        if (serviceRate.isPerDrop) {
            serviceRate.setServiceRateFees(perDropRateFees);
        }

        if (serviceRate.isParcelService) {
            serviceRate.setServiceRateParcelFees(parcelFees);
        }

        this.isUpdatingServiceRate = true;
        this.loader.showLoader('.overlay-inner-content', { loadingMessage: 'Updating service rate...' });

        try {

            const response = await serviceRate.save();
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
