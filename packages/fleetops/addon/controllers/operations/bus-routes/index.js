import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsBusRoutesIndexController extends BaseController {
    @service notifications;
    @service hostRouter;

    // Default pagination and sorting values
    @tracked page = 1;
    @tracked limit = 20; 
    @tracked sort = '-created_at';
    @tracked query;

    @tracked rows = [];

    /** Table Column Definitions */
    @tracked columns = [
        { label: 'Departure City', valuePath: 'departure_city', resizable: true, sortable: true },
        { label: 'Arrival City', valuePath: 'arrival_city', resizable: true, sortable: true },
        { label: 'Vendor', valuePath: 'vendor.name', resizable: true }, // Accessing nested relationship name
        { label: 'Price', valuePath: 'price', resizable: true, sortable: true },
        { label: 'Class', valuePath: 'route_class', resizable: true },
        { label: 'Country', valuePath: 'country', resizable: true },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonIcon: 'ellipsis-h',
            actions: [
                { label: 'Edit Route', fn: this.editRoute },
                { label: 'Delete Route', fn: this.deleteRoute },
            ],
            width: '10%'
        }
    ];

    @action 
    createRoute() {
        // Navigate to the creation form
        return this.transitionToRoute('operations.bus-routes.index.new');
    }

    @action 
    reload() {
        // Force a data refresh from the server
        return this.hostRouter.refresh();
    }

    @action
    editRoute(model) {
        // Placeholder for edit logic
        console.log('Editing:', model.id);
    }

    @action
    deleteRoute(model) {
        // Placeholder for delete logic
        if (confirm('Are you sure you want to delete this route?')) {
            model.destroyRecord();
        }
    }
}