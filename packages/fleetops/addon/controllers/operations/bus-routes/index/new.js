import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class OperationsBusRoutesIndexNewController extends Controller {
    @service notifications;
    @service hostRouter;

    /**
     * @action saveRoute
     * Isse hum data save karenge. 
     * Kyuki humne Route mein 'controller.setProperties' use kiya hai,
     * isliye hum seedha 'this.departureCity' etc. use kar sakte hain.
     */
    @action saveRoute() {
        // Data collect karein jo controller par set kiya gaya hai
        const { country, departureCity, arrivalCity, price, routeClass } = this;

        // Validation: Check if fields are empty
        if (!departureCity || !arrivalCity) {
            return this.notifications.error('Please select both Departure and Arrival cities.');
        }

        // Validation: Departure and Arrival cannot be same
        if (departureCity === arrivalCity) {
            return this.notifications.error('Departure and Arrival cities cannot be the same!');
        }

        console.log('Saving Data:', { country, departureCity, arrivalCity, price, routeClass });

        // Success Notification
        this.notifications.success(`Bus route from ${departureCity} to ${arrivalCity} created successfully.`);
        
        // Waapas table par jaana
        return this.transitionToRoute('operations.bus-routes.index');
    }
}