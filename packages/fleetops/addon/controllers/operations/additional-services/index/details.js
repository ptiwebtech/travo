import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class OperationsAdditionalServicesIndexDetailsController extends Controller {
    @service hostRouter;
    @service notifications;
    @service store;

    @tracked additionalService;

    @action
    onClose() {
        this.hostRouter.transitionTo('console.fleet-ops.operations.additional-services.index');
    }

    @action
    editService() {
        const serviceModel = this.additionalService || this.model;
        const id = serviceModel.get ? serviceModel.get('id') : serviceModel.id;
        if (!id) {
            return this.notifications.error('Additional Service ID not found.');
        }
        return this.hostRouter.transitionTo(
            'console.fleet-ops.operations.additional-services.index.edit',
            id
        );
    }
}