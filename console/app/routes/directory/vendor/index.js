import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DirectoryVendorIndexRoute extends Route {
    model() {
        const { slug } = this.paramsFor('directory.vendor');
        return { 
            type: slug // Original slug API call ke liye
        };
    }

    setupController(controller, model) {
        super.setupController(controller, model);

        // Reset state before fetching
        controller.vendors = [];
        // controller.page = 1;

        // Task ko perform karna (Assuming aapne controller mein fetchVendorsTask likha hai)
        if (controller.fetchVendorsTask) {
            controller.fetchVendorsTask.perform();
        }
    }
}