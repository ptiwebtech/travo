import Route from '@ember/routing/route';

export default class DirectoryPlaceDetailsRoute extends Route {
    model(params) {
        return { id: params.place_id }; 
    }

    setupController(controller, model) {
        super.setupController(controller, model); 
        if (model && model.id) {
            controller.fetchDetails(model.id);
        }
    }
}