import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class DirectoryController extends Controller {
    @service router;

    // Place Types for Section 1, 2, 3...
    // Inhe hum static links ki tarah use karenge template mein.

    // Vendor Types from Fleetbase Engine
    @tracked vendorTypeOptions = [
        'bike-fleet-operator', 'broker', 'bulk-material-handler', 'car-fleet-operator',
        'car-rental-company', 'cargo-inspector', 'carrier-air-cargo', 'carrier-rail',
        'carrier-shipping-line', 'carrier-trucking-company', 'caterer', 'cold-chain-provider',
        'cold-storage', 'cold-transport', 'consultant', 'customs-broker', 'dispatch-rider',
        'drayage-service-provider', 'driver-leasing-service', 'ecommerce', 'equipment-rental',
        'freight-forwarder', 'fuel-distribution-company', 'fuel-station', 
        'fuel-transport-and-logistics', 'general-vendor', 'hazardous-material-specialist',
        'independent-dispatch-rider-bike', 'independent-vehicle-owner', 'insurance-provider',
        'intercity-bus-operator', 'intermodal-operator', 'individual-car-owner',
        'label-packaging-material-supplier', 'last-mile-delivery-service', 'logistics-association',
        'maintenance-repair-facility', 'packing-crating-service', 'pallet-provider',
        'parcel-courier', 'parts-supplier', 'passenger-train-operator', 'regulatory-body',
        'tank-farm (Oil & Gas)', 'taxi-operator', 'technology-provider',
        'third-party-logistics-provider', 'tire-service-provider', 'towing-service',
        'training-certification-provider', 'trucking-company', 'vehicle-dealership',
        'vehicle-manufacturer', 'warehouse-provider', 'waste-management', 'water-distribution-company'
    ];

    /**
     * Common action for both Places and Vendors
     * @param {String} type - The specific category (e.g., 'Airport' or 'broker')
     * @param {String} route - The target route name (e.g., 'operations.places.index')
     */
    @action
    viewCategory(slug, category, event) {
        if (event) event.preventDefault();

        // Humne router mein 'directory.place' aur 'directory.vendor' define kiya hai
        // slug ki value 'airport', 'bus-station' etc. hogi
        const route = category === 'places' ? 'directory.place' : 'directory.vendor';
        
        this.router.transitionTo(route, slug);
    }
}