import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsAdditionalServicesIndexDetailsRoute extends Route {
    @service store;

    model(params) {
        const id = params.id || params.additional_service_id || params.public_id;

        if (!id) {
            console.error('ID missing in Additional Service Details Route');
            return;
        }
        return this.store.findRecord('additional-service', id);
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.additionalService = model;
    }
}