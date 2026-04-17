import Route from '@ember/routing/route';

export default class DirectoryVendorDetailsRoute extends Route {
    model(params) {
        return { 
            id: params.vendor_id, 
            type: params.slug 
        }; 
    }

    setupController(controller, model) {
        super.setupController(controller, model); 
        
        if (model && model.id) {
            controller.fetchVendorDetails(model.id);
        }
    }
}