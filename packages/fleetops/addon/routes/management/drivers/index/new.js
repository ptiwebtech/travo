import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementDriversIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service fetch;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create driver')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.drivers.index');
        }
    }

    async model() {
        const country = 'nigeria'; // You can dynamically set this or pass it via a parameter

        try {
            // Fetch cities for the given country using the API
            let response = await fetch('https://countriesnow.space/api/v0.1/countries/cities', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ country }), // Pass the country dynamically
            });

            if (!response.ok) {
                throw new Error('Failed to fetch cities');
            }

            let result = await response.json();

            // Check if cities are found in the response
            if (result.data && result.data.length > 0) {
                console.log('Cities:', result.data); // Log the cities
                return { cities: result.data }; // Return cities for the controller to use
            } else {
                throw new Error('No cities found for the selected country.');
            }
        } catch (error) {
            this.notifications.error(`Error fetching cities: ${error.message}`);
            return { cities: [] }; // Return an empty array in case of error
        }
    }

    setupController(controller, model) {
        super.setupController(controller, model);

        // Assign cities to the controller, to be used in the template
        controller.set('cities', model.cities);
    }

    
}
