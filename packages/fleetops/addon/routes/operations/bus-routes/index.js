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
        return this.store.query('bus-route', params)
            .catch(() => []);
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.rows = model || [];
    }
}