import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class OperationsBusRoutesIndexDetailsController extends Controller {
    @service hostRouter;
    @service notifications;
    @service store;

    @tracked busRoute;

    @action
    onClose() {
        this.hostRouter.transitionTo('console.fleet-ops.operations.bus-routes.index');
    }

    @action
    editRoute() {
        const routeModel = this.busRoute || this.model;
        const id = routeModel.get ? routeModel.get('id') : routeModel.id;
        if (!id) {
            return this.notifications.error('Bus Route ID not found.');
        }
        return this.hostRouter.transitionTo(
            'console.fleet-ops.operations.bus-routes.index.edit', 
            id
        );
    }
}