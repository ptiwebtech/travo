import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsServiceRatesIndexNewRoute extends Route {
    @service store;

    model() {
        return this.store.createRecord('service-rate');
    }

    async setupController(controller, model) {

        controller.serviceRate = model;
        controller.rateFees = [];
        controller.parcelFees = [];

        controller.orderConfigs = await this.store.findAll('order-config');
        controller.serviceAreas = await this.store.findAll('service-area');
    }
}
