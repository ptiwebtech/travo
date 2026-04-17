import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action, set } from '@ember/object';
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
        logo_url: null,
        notes: '',
        has_physical_location: false,
        status: 'Prospective' 
    };

    @action
    updateVendorType(type) {
        this.selectedVendorType = type;
        this.vendor.type = type; // Ye zaroori hai backend ke liye
        console.log('Selected:', type);
    }

    @action
    async saveVendor(event) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }

        const { name, email, phone, type } = this.vendor;

        if (!name || name.length < 3) {
            return this.notifications.error('Please enter a valid Business Name.');
        }
    
        if (!email || !email.includes('@')) {
            return this.notifications.error('Please enter a valid Business Email.');
        }
    
        if (!phone) {
            return this.notifications.error('Phone number is required for verification.');
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
            const errorMsg = e.error || e.message || 'Registration failed.';
            this.notifications.error(errorMsg);
        } finally {
            this.isLoading = false;
        }
    }

    @action
    onUploadLogo(file) {
        // 1. Loading state ko turant khatam karne ke liye
        if (file.queue) {
            file.queue.remove(file);
        }
    
        // 2. Image ko Base64 string mein convert karo
        const reader = new FileReader();
        reader.readAsDataURL(file.file);
        reader.onload = () => {
            const base64Data = reader.result;

            // 2. Reactivity trigger karne ke liye 'set' use karein
            // Isse Ember ko pata chalega ki 'logo_url' badal gaya hai aur UI update hoga
            set(this.vendor, 'logo_url', base64Data);
            set(this.vendor, 'logo_data', base64Data);
            this.notifications.info('Logo selected successfully.');
        };
    }
    @action
    onLocationToggle(value) {
        this.vendor.has_physical_location = value;
    }
}