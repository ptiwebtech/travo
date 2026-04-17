import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DirectoryPlaceIndexRoute extends Route {
    model() {
        // Parent route se slug uthana
        const { slug } = this.paramsFor('directory.place');
        
        const cleanSlug = slug ? slug.replace(/-/g, ' ') : slug;
        return { categoryName: cleanSlug };
    }

    setupController(controller, model) {
        super.setupController(controller, model);

        // controller.page = 1;
        // controller.query = null;
        // controller.country = ''; // Reset to All Countries
        controller.items = [];
        
        // Task ko perform karne ke liye .perform() ka use hota hai
        if (controller.fetchPlacesTask) {
            controller.fetchPlacesTask.perform();
        }
    }
}