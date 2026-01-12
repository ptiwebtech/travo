import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexNewRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    // Define query parameters
    queryParams = {
        orderType: {
            refreshModel: true // Reload model if query param changes
        }
    };

    // Order type to ID mapping
    orderTypeMapping = {
        sendparcel: "96f3909e-081c-4609-a240-9c139a012771",
        travel: "8283f663-9320-482f-94d8-1136a8b1d08e",
        haulage: "40146ff3-6980-4711-b33c-308110eb6b4f",
        driver: "ba5bf36d-f2d7-46f5-a43f-b4895dd47aaf",
        storefront: "011dc44b-ecca-4826-8fd0-51d576da2738",
        exportimport: "03015011-25a8-4ba8-aad9-a208aa8a3aa2",
        emergencyservice: "bbf73bcc-f13b-44e1-8c2e-c324a1139eb8",
        airportpickup: "1d9af4c6-979f-4cd9-8583-a8b7ae2c7281",
        rubbishcollection: "4405acd5-2d4a-49d4-ae54-8c995a13f244",
        breakdownsresponse: "ed00c80b-b279-4258-8ba0-95c614c3a20d",
        foodcatering: "82d4b159-0c14-4bc3-9ab2-1476550291b2",
        utilities: "c476c21a-42b6-4801-a44b-a541a91430b7"
    };

    @action willTransition() {
        if (this.controller) {
            this.controller.resetForm();
        }
    }

    beforeModel(transition) {
        // Get the 'orderType' from query params
        const orderType = transition.to.queryParams.orderType;

        if (this.abilities.cannot('fleet-ops create order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }

        // Check and log the orderType
        if (orderType) {
            console.log('Order Type:', orderType);
            this.controllerFor(this.routeName).set('orderType', orderType);
        }
    }

    model() {
        return this.store.createRecord('order', {
            scheduled_at: new Date() 
        });
    }

    async setupController(controller, model) {
        controller.set('order', model);
        controller.orderConfigs = await this.store.findAll('order-config');
        controller.set('servicable', true);

        controller.setConfig({ target: { value: '96f3909e-081c-4609-a240-9c139a012771' } });


        // Get the queryParams directly from the route
        const { orderType } = this.paramsFor(this.routeName);
        if (orderType) {
            // Find the corresponding orderConfig ID based on the orderType
            const orderConfigId = this.orderTypeMapping[orderType];

            if (orderConfigId) {
                // Find the matching orderConfig by ID
                const matchingOrderConfig = controller.orderConfigs.find((config) => config.id === orderConfigId);

                if (matchingOrderConfig) {
                    // Set the matching orderConfig as selected
                    controller.set('selectedOrderConfig', matchingOrderConfig);

                    controller.set('servicable', true);

                    let orderPrefix = orderType.substring(0, 4).toUpperCase();
                    let uniqueNumber = await this.generateUniqueNumber();
                    controller.set('order.internal_id', `${orderPrefix}${uniqueNumber}`);

                } else {
                    console.warn(`No order config found with ID: ${orderConfigId}`);
                    controller.set('servicable', false);
                }
            } else {
                console.warn(`No order config mapping found for orderType: ${orderType}`);
                controller.set('selectedOrderConfig', null);
                controller.set('servicable', false);
            }
        } else {
            // If no orderType is provided, clear the selection
            controller.set('selectedOrderConfig', null);
        }

        controller.set('order.internal_id', controller.order.internal_id ?? '');
    }

    async generateUniqueNumber(orderType) {
        // Simulate a counter based on order type. In practice, this could be retrieved from a database.
        let orderCounters = {
            sendparcel: 1001,
            travel: 1002,
            haulage: 1003,
            driver: 1004,
            storefront: 1005,
            exportimport: 1006,
            utilities: 1007,
            foodcatering: 1008,
            breakdownsresponse: 1009,
            rubbishcollection: 1010,
            airportpickup: 1011,
            emergencyservice: 1012
        };

        let currentCounter = orderCounters[orderType] ?? 1001; // Default to 1001 if not found

        // Format the number as a 6-digit string, e.g., 001001
        let formattedCounter = String(currentCounter).padStart(6, '0');

        // Increment the counter for the next order (this should normally be updated in a persistent store)
        orderCounters[orderType]++;

        return formattedCounter;
    }

}

