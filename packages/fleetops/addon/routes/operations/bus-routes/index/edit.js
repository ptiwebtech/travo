import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsBusRoutesIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
  
    model(params) {
        const id = params.id || params.bus_route_id || params.public_id;
    
        if (!id) {
            console.error('ID is missing in Edit Route');
            return;
        }
        return this.store.findRecord('bus-route', id);
    }

    // 3. Pura data controller mein load karein
    async setupController(controller, model) {
        super.setupController(controller, model);
        
        const cities = [
            'Lagos', 'Abuja', 'Ibadan', 'Port Harcourt', 'Onitsha', 'Enugu', 'Owerri', 'Asaba', 'Warri', 'Benin City', 
            'Akure', 'Ado-Ekiti', 'Ilorin', 'Osogbo', 'Abeokuta', 'Kano', 'Kaduna', 'Jos', 'Minna', 'Lokoja', 'Makurdi', 
            'Lafia', 'Keffi', 'Accra', 'Kumasi', 'Tema', 'Takoradi', 'Lomé', 'Cotonou', 'Porto-Novo', 'Ouagadougou', 
            'Niamey', 'N\'Djamena','Abia', 'Calabar', 'Uyo', 'Abakaliki', 'Bayelsa', 'Akure', 'Maiduguri', 'Kebbi', 'Taraba', 'Sokoto', 'Yobe'
        ];

        const africaCountries = [
            'Algeria', 'Angola', 'Benin', 'Botswana', 'Burkina Faso', 'Burundi', 'Cabo Verde', 'Cameroon', 'Central African Republic', 
            'Chad', 'Comoros', 'Congo (Congo-Brazzaville)', 'Congo (Democratic Republic)', 'Djibouti', 'Egypt', 'Equatorial Guinea', 
            'Eritrea', 'Eswatini', 'Ethiopia', 'Gabon', 'Gambia', 'Ghana', 'Guinea', 'Guinea-Bissau', 'Ivory Coast', 'Kenya', 
            'Lesotho', 'Liberia', 'Libya', 'Madagascar', 'Malawi', 'Mali', 'Mauritania', 'Mauritius', 'Morocco', 'Mozambique', 
            'Namibia', 'Niger', 'Nigeria', 'Rwanda', 'Sao Tome and Principe', 'Senegal', 'Seychelles', 'Sierra Leone', 'Somalia', 
            'South Africa', 'South Sudan', 'Sudan', 'Tanzania', 'Togo', 'Tunisia', 'Uganda', 'Zambia', 'Zimbabwe'
        ];

        // Fetch Vendors
        try {
            const vendors = await this.store.query('vendor', { type: 'intercity-bus-operator' });
            controller.set('vendors', vendors.slice().sortBy('name'));
        } catch (e) {
            controller.set('vendors', []);
        }

        // Logic to check timing mode (Same time vs Custom schedule)
        const hasCustomSchedule = model.custom_schedule && Object.keys(model.custom_schedule).length > 0;
        
        controller.setProperties({
            cities,
            africaCountries,
            routeClasses: ['Economy', 'First Class', 'Premium', 'VIP'],
            travelTypes: ['Bus', 'Ferry', 'Train'],
            daysOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            useSameTime: !hasCustomSchedule,
            isSubmitting: false
        });
    }
}