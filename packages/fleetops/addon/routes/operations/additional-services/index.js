import Route from '@ember/routing/route';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsAdditionalServicesIndexRoute extends Route {
    @service store;

    @tracked queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        order_type: { refreshModel: true },
    };

    @action model(params) {
        return this.store.query('additional-service', params);
    }
    // @action model() {
    //     // Static dummy data - backend ready hone tak
    //     return [
    //         {
    //             id: '1',
    //             name: 'Packaging Service',
    //             order_type_name: 'SEND PARCEL',
    //             price: 500,
    //             description: 'Professional packaging for your parcel',
    //             status: 'active',
    //             createdAtShort: '20/05/2026',
    //         },
    //         {
    //             id: '2',
    //             name: 'Insurance',
    //             order_type_name: 'SEND PARCEL',
    //             price: 1000,
    //             description: 'Insurance coverage for valuable items',
    //             status: 'active',
    //             createdAtShort: '20/05/2026',
    //         },
    //         {
    //             id: '3',
    //             name: 'Express Delivery',
    //             order_type_name: 'AIRPORT TRANSFER',
    //             price: 2500,
    //             description: 'Same day express delivery service',
    //             status: 'inactive',
    //             createdAtShort: '19/05/2026',
    //         },
    //     ];
    // }
}