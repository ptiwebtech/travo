import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsBusRoutesIndexRoute extends Route {
    @service store;
    @service notifications;

    // Adding these ensures that if you filter/search, the model() re-runs
    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
    };

    model(params) {
        // Use the same pattern as ServiceRates
        // The 'with' array is critical if your backend expects eager loading
        return this.store.query('bus-route', {
            ...params,
            with: ['vendor'], // Add actual relationship names here
        }).catch((e) => {
            console.error('--- Bus Route Query Failed ---', e);
            this.notifications.error('Failed to load bus routes.');
            return [];
        });
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        // Fleetbase usually expects 'rows' for its data tables
        controller.rows = model;
    }
}