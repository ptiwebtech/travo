import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ExploreCategoryRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        query: {
            refreshModel: false,
        },
    };

    beforeModel() {
        if (this.abilities.cannot('registry-bridge list extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model({ slug }) {
        return this.store.queryRecord('category', { slug, for: 'extension_category', core_category: 1, single: 1 });
    }

    async setupController(controller, model) {
        super.setupController(...arguments);
        const params = { explore: 1, category: model.id };
        const query = controller.query;
        if (query) {
            params.query = controller.query;
        }

        controller.extensions = await this.store.query('registry-extension', params);
    }
}
