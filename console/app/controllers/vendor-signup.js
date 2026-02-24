import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class VendorSignupController extends Controller {
    @service fetch;
    @service notifications;
    @service router;

    @tracked isLoading = false;
    
    // Full sorted list from Fleetbase Engine
    @tracked vendorTypeOptions = [
        'bike-fleet-operator',
        'broker',
        'bulk-material-handler',
        'car-fleet-operator',
        'car-rental-company',
        'cargo-inspector',
        'carrier-air-cargo',
        'carrier-rail',
        'carrier-shipping-line',
        'carrier-trucking-company',
        'caterer',
        'cold-chain-provider',
        'cold-storage',
        'cold-transport',
        'consultant',
        'customs-broker',
        'dispatch-rider',
        'drayage-service-provider',
        'driver-leasing-service',
        'ecommerce',
        'equipment-rental',
        'freight-forwarder',
        'fuel-distribution-company',
        'fuel-station',
        'fuel-transport-and-logistics',
        'general-vendor',
        'hazardous-material-specialist',
        'independent-dispatch-rider-bike',
        'independent-vehicle-owner',
        'insurance-provider',
        'intercity-bus-operator',
        'intermodal-operator',
        'individual-car-owner',
        'label-packaging-material-supplier',
        'last-mile-delivery-service',
        'logistics-association',
        'maintenance-repair-facility',
        'packing-crating-service',
        'pallet-provider',
        'parcel-courier',
        'parts-supplier',
        'passenger-train-operator',
        'regulatory-body',
        'tank-farm (Oil & Gas)',
        'taxi-operator',
        'technology-provider',
        'third-party-logistics-provider',
        'tire-service-provider',
        'towing-service',
        'training-certification-provider',
        'trucking-company',
        'vehicle-dealership',
        'vehicle-manufacturer',
        'warehouse-provider',
        'waste-management',
        'water-distribution-company'
    ];

    @tracked selectedVendorType = 'general-vendor';

    // Form data with default type set to General Vendor
    @tracked vendor = {
        name: '',
        email: '',
        phone: '',
        website_url: '',
        type: 'general-vendor',
        country: 'NG',
        notes: '',
        status: 'Prospective' 
    };

    @action
    updateVendorType(type) {
        this.selectedVendorType = type; // UI turant update hoga
        console.log('Selected:', type);
    }

    @action
    async saveVendor(event) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }

        if (!this.vendor.name || !this.vendor.email) {
            return this.notifications.error('Please fill in the required fields (Name and Email).');
        }

        this.isLoading = true;

        try {
            // POST request to your backend API
            await this.fetch.post('auth/vendor-signup', { 
                vendor: this.vendor 
            });

            this.notifications.success('Thank you for registering as a vendor and offering your services to our community. Your application is currently under review, and we will contact you should we require any additional information.');
            this.router.transitionTo('auth.login');
        } catch (e) {
            this.notifications.error('Registration failed: ' + (e.message || 'Server Error'));
        } finally {
            this.isLoading = false;
        }
    }

    @action
    onUploadLogo(file) {
        // Local preview for the logo
        this.vendor.logo_url = URL.createObjectURL(file.file);
        this.notifications.info('Logo selected successfully.');
    }
}