import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsAdditionalServicesIndexNewRoute extends Route {
    @service store;

    /**
     * These two are internal/legacy configs — exclude from the dropdown
     */
    excludedConfigIds = [
        '48a9fea4-1b80-45fb-9b2a-1ffc838ae2aa', // Transport
        'edde8b08-6053-44b3-bd75-5aed0abb9a4d', // Storefront
    ];

    /**
     * Reset the form whenever we leave this route
     */
    @action willTransition() {
        if (this.controller) {
            this.controller.resetForm();
        }
    }

    model() {
        return null;
    }

    /**
     * Wire up the controller:
     *  1. Reset form to clean state
     *  2. Load order configs, filter out excluded ones, set as @tracked
     */
    async setupController(controller, model) {
        super.setupController(controller, model);

        controller.resetForm();

        const allConfigs = await this.store.findAll('order-config');

        // Filter out the two internal configs AND set as tracked array
        // so Glimmer re-renders the dropdown on first load
        controller.set(
            'orderConfigs',
            allConfigs.filter((c) => !this.excludedConfigIds.includes(c.id))
        );
    }
}