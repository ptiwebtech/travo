import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import EmberObject from '@ember/object';

export default class OperationsBusRoutesIndexNewRoute extends Route {
    @service store;

    model() {
        return EmberObject.create({
            country: 'Nigeria',
            departure_city: null,
            arrival_city: null,
            vendor: null,
            departure_address: '',
            arrival_address: '',
            price: 0,
            route_class: 'Economy'
        });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);
        
        // 1. Full Cities List from your requirement
        const cities = [
            'Lagos', 'Abuja', 'Ibadan', 'Port Harcourt', 'Onitsha', 'Enugu', 'Owerri', 'Asaba', 'Warri', 'Benin City', 
            'Akure', 'Ado-Ekiti', 'Ilorin', 'Osogbo', 'Abeokuta', 'Kano', 'Kaduna', 'Jos', 'Minna', 'Lokoja', 'Makurdi', 
            'Lafia', 'Keffi', 'Accra', 'Kumasi', 'Tema', 'Takoradi', 'Lomé', 'Cotonou', 'Porto-Novo', 'Ouagadougou', 
            'Niamey', 'N\'Djamena'
        ];

        // 2. Full List of African Countries
        const africaCountries = [
            'Algeria', 'Angola', 'Benin', 'Botswana', 'Burkina Faso', 'Burundi', 'Cabo Verde', 'Cameroon', 'Central African Republic', 
            'Chad', 'Comoros', 'Congo (Congo-Brazzaville)', 'Congo (Democratic Republic)', 'Djibouti', 'Egypt', 'Equatorial Guinea', 
            'Eritrea', 'Eswatini', 'Ethiopia', 'Gabon', 'Gambia', 'Ghana', 'Guinea', 'Guinea-Bissau', 'Ivory Coast', 'Kenya', 
            'Lesotho', 'Liberia', 'Libya', 'Madagascar', 'Malawi', 'Mali', 'Mauritania', 'Mauritius', 'Morocco', 'Mozambique', 
            'Namibia', 'Niger', 'Nigeria', 'Rwanda', 'Sao Tome and Principe', 'Senegal', 'Seychelles', 'Sierra Leone', 'Somalia', 
            'South Africa', 'South Sudan', 'Sudan', 'Tanzania', 'Togo', 'Tunisia', 'Uganda', 'Zambia', 'Zimbabwe'
        ];

        // 3. Fetch Vendors with type 'intercity-bus-operator'
        const vendors = await this.store.query('vendor', { type: 'intercity-bus-operator' });

        controller.setProperties({
            model,
            cities,
            africaCountries,
            vendors,
            routeClasses: ['Economy', 'First Class', 'Premium', 'VIP']
        });
    }
}