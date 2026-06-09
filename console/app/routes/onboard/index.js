import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OnboardIndexRoute extends Route {
    @service store;

    @service session;
    @service router;

    beforeModel() {
        if (this.session.isAuthenticated) {
            return this.router.transitionTo('console');
        }
    }

    model() {
        return this.store.findRecord('brand', 1);
    }
}
