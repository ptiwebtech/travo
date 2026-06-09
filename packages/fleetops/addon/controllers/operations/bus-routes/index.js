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
    @service crud;

    @tracked rows = [];

    /** Table Column Definitions */
    @tracked columns = [
        {
            label: 'Id',
            valuePath: 'public_id',
            width: '140px',
            cellComponent: 'table/cell/link-to',
            route: 'operations.bus-routes.index.details',
            onLinkClick: this.viewRoute, 
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        { label: 'Departure City', valuePath: 'departure_city', resizable: true, sortable: true },
        { label: 'Arrival City', valuePath: 'arrival_city', resizable: true, sortable: true },
        { 
            label: 'Vendor', 
            valuePath: 'vendor_name', // ✅ Backend se vendor_name field add karwao
            resizable: true 
        },
        { label: 'Price', valuePath: 'price', resizable: true, sortable: true },
        { label: 'Class', valuePath: 'route_class', resizable: true },
        { label: 'Country', valuePath: 'country', resizable: true },
        { 
            label: 'Created At', 
            valuePath: 'createdAtDisplay', // ✅
            resizable: true, 
            sortable: false,
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Bus Route Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '7%',
            actions: [
                {
                    label: 'View Details',
                    fn: this.viewRoute,
                    // permission: 'fleet-ops view bus-route', // Agar permissions set hain
                },
                {
                    label: 'Edit Route',
                    fn: this.editRoute,
                    // permission: 'fleet-ops update bus-route',
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Route',
                    fn: this.deleteRoute.bind(this),
                    // permission: 'fleet-ops delete bus-route',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
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
    viewRoute(busRoute) {
        return this.transitionToRoute('operations.bus-routes.index.details', busRoute);
    }

    @action 
    editRoute(busRoute) {
        return this.transitionToRoute('operations.bus-routes.index.edit', busRoute);
    }

    @action 
    deleteRoute(busRoute, options = {}) {
        this.crud.delete(busRoute, {
            acceptButtonText: 'Delete',
            acceptButtonIcon: 'trash',
            onSuccess: () => {
                return this.hostRouter.refresh(); 
            },
            ...options,
        });
    }
}