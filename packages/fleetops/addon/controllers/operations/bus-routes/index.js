import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsBusRoutesIndexController extends BaseController {
    @service notifications;
    @service modalsManager;
    @service intl;
    @service store;
    @service hostRouter;

    /** @tracked properties for table */
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked query;

    /** Columns for the table */
    @tracked columns = [
        { label: 'Departure City', valuePath: 'departure_city', resizable: true, sortable: true },
        { label: 'Arrival City', valuePath: 'arrival_city', resizable: true, sortable: true },
        { label: 'Vendor', valuePath: 'vendor.name', resizable: true },
        { label: 'Price', valuePath: 'price', resizable: true, sortable: true },
        { label: 'Class', valuePath: 'route_class', resizable: true },
        { label: 'Country', valuePath: 'country', resizable: true },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonIcon: 'ellipsis-h',
            actions: [
                { label: 'Edit Route', fn: () => {} },
                { label: 'Delete Route', fn: () => {} },
            ],
            width: '10%'
        }
    ];

    @action createRoute() {
        // Vendor pattern: transition to the 'new' sub-route
        return this.transitionToRoute('operations.bus-routes.index.new');
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}