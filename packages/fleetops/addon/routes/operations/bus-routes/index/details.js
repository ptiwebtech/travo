import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsBusRoutesIndexDetailsRoute extends Route {
    @service store;
    model(params) {
        const id = params.id || params.bus_route_id || params.public_id;
        
        if (!id) {
            console.error('ID missing in Details Route');
            return;
        }
        return this.store.findRecord('bus-route', id);
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.busRoute = model;
    }
}