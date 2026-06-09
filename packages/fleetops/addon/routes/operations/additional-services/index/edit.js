import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsAdditionalServicesIndexEditRoute extends Route {
    @service store;

    excludedConfigIds = [
        '48a9fea4-1b80-45fb-9b2a-1ffc838ae2aa', // Transport
        'edde8b08-6053-44b3-bd75-5aed0abb9a4d', // Storefront
    ];

    async model(params) {
        const id = params.id || params.additional_service_id || params.public_id;

        if (!id) {
            console.error('ID missing in Edit Route');
            return;
        }
        return this.store.findRecord('additional-service', id);
    }

    async setupController(controller, model) {
        super.setupController(controller, model);

        const allConfigs = await this.store.findAll('order-config');

        controller.set(
            'orderConfigs',
            allConfigs.filter((c) => !this.excludedConfigIds.includes(c.id))
        );

        controller.loadFromModel(model);
    }
}