import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsBusRoutesIndexNewRoute extends Route {
    @service store;

    model() {
        // ✅ CHANGE: EmberObject ki jagah asali model record create karein
        // 'bus-route' wahi naam hona chahiye jo aapne file ka rakha hai (bus-route.js)
        return this.store.createRecord('bus-route', {
            country: 'Nigeria',
            price: 0,
            route_class: 'Economy',
            operating_days: []
        });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);
        
        const cities = [
            'Lagos', 'Abuja', 'Ibadan', 'Port Harcourt', 'Onitsha', 'Enugu', 'Owerri', 'Asaba', 'Warri', 'Benin City', 
            'Akure', 'Ado-Ekiti', 'Ilorin', 'Osogbo', 'Abeokuta', 'Kano', 'Kaduna', 'Jos', 'Minna', 'Lokoja', 'Makurdi', 
            'Lafia', 'Keffi', 'Accra', 'Kumasi', 'Tema', 'Takoradi', 'Lomé', 'Cotonou', 'Porto-Novo', 'Ouagadougou', 
            'Niamey', 'N\'Djamena', 'Abia', 'Calabar', 'Uyo', 'Abakaliki', 'Bayelsa', 'Akure', 'Maiduguri', 'Kebbi', 'Taraba', 'Sokoto', 'Yobe',
        ];

        const africaCountries = [
            'Algeria', 'Angola', 'Benin', 'Botswana', 'Burkina Faso', 'Burundi', 'Cabo Verde', 'Cameroon', 'Central African Republic', 
            'Chad', 'Comoros', 'Congo (Congo-Brazzaville)', 'Congo (Democratic Republic)', 'Djibouti', 'Egypt', 'Equatorial Guinea', 
            'Eritrea', 'Eswatini', 'Ethiopia', 'Gabon', 'Gambia', 'Ghana', 'Guinea', 'Guinea-Bissau', 'Ivory Coast', 'Kenya', 
            'Lesotho', 'Liberia', 'Libya', 'Madagascar', 'Malawi', 'Mali', 'Mauritania', 'Mauritius', 'Morocco', 'Mozambique', 
            'Namibia', 'Niger', 'Nigeria', 'Rwanda', 'Sao Tome and Principe', 'Senegal', 'Seychelles', 'Sierra Leone', 'Somalia', 
            'South Africa', 'South Sudan', 'Sudan', 'Tanzania', 'Togo', 'Tunisia', 'Uganda', 'Zambia', 'Zimbabwe'
        ];

        // 3. Fetch Vendors
        const vendors = await this.store.query('vendor', { type: 'intercity-bus-operator' });

        // Sorting Logic: 
        const sortedVendors = vendors.toArray().sortBy('name');

        controller.setProperties({
            model,
            cities,
            africaCountries,
            vendors: sortedVendors,
            routeClasses: ['Economy', 'First Class', 'Premium', 'VIP'],
            isSubmitting: false
        });
    }

    // ✅ EK AUR ZARURI CHEEZ: 
    // Agar user bina save kiye page band kare, toh adha-adhura record delete ho jaye
    // deactivate() {
    //     const model = this.modelFor(this.routeName);
    //     if (model.isNew) {
    //         model.rollbackAttributes();
    //     }
    // }
}