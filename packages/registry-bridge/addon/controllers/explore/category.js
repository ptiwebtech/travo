import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task, timeout } from 'ember-concurrency';

export default class ExploreCategoryController extends Controller {
    @service store;
    @tracked extensions = [];
    @tracked query;
    queryParams = ['query'];

    @task({ restartable: true }) *search(event) {
        this.query = event.target.value;
        yield timeout(300);

        if (this.query) {
            this.extensions = yield this.store.query('registry-extension', { explore: 1, category: this.model.id, query: this.query });
        } else {
            this.extensions = yield this.store.query('registry-extension', { explore: 1, category: this.model.id });
        }
    }
}
