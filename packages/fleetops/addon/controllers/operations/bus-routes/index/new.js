import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class OperationsBusRoutesIndexNewController extends Controller {
    @service notifications;
    @service store;

    daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    @tracked isSubmitting = false;
    @tracked useSameTime = true;

    @tracked customSchedule = {
        'Monday': { enabled: true, time: '08:00' },
        'Tuesday': { enabled: true, time: '08:00' },
        'Wednesday': { enabled: true, time: '08:00' },
        'Thursday': { enabled: true, time: '08:00' },
        'Friday': { enabled: true, time: '08:00' },
        'Saturday': { enabled: true, time: '08:00' },
        'Sunday': { enabled: true, time: '08:00' }
    };

    @action
    updateSchedule(day, property, event) {
        let value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
        let currentSchedule = { ...this.customSchedule };
        
        currentSchedule[day] = { 
            ...currentSchedule[day], 
            [property]: value 
        };
        
        this.customSchedule = currentSchedule;

        // Model mein data save karne ke liye ready karein
        this.model.set('advanced_schedule', this.customSchedule);
    }

    @action
    toggleTimeMode() {
        this.useSameTime = !this.useSameTime;
    }

    @action
    toggleDay(day) {
        let selectedDays = this.model.operating_days || [];
        if (selectedDays.includes(day)) {
            selectedDays = selectedDays.filter(d => d !== day);
        } else {
            selectedDays = [...selectedDays, day];
        }
        this.model.set('operating_days', selectedDays);
    }

    /**
     * ✅ UPDATED: Ab ye asali data save karega database mein
     */
    @action 
    async saveRoute() {
        const { model } = this;
        let vId = model.get('vendor.id') || model.get('vendor_uuid');
        let dId = model.get('departure_location.id') || model.get('departure_location_uuid');
        let aId = model.get('arrival_location.id') || model.get('arrival_location_uuid');

        // 2. UUID Cleanup Logic (Regex for <model::vendor:UUID> format)
        const cleanId = (id) => {
            if (typeof id === 'string' && id.includes(':')) {
                const parts = id.split(':');
                return parts[parts.length - 1].replace('>', '');
            }
            return id;
        };

        model.set('vendor_uuid', cleanId(vId));
        model.set('departure_location_uuid', cleanId(dId));
        model.set('arrival_location_uuid', cleanId(aId));

        // 3. Schedule & Operating Days Sync
        if (this.useSameTime) {
            // Agar same time hai, toh model.operating_days pehle se hi toggleDay se update ho raha hai
            model.set('custom_schedule', null);
        } else {
            model.set('custom_schedule', this.customSchedule);
            const activeDays = Object.keys(this.customSchedule)
                .filter(day => this.customSchedule[day].enabled);
            model.set('operating_days', activeDays);
        }

        // 4. Final Validations
        const vendorUuid = model.get('vendor_uuid');
        const depCity = model.get('departure_city');
        const arrCity = model.get('arrival_city');

        if (!depCity || !arrCity || !vendorUuid) {
            return this.notifications.error('Please fill all required fields (Vendor, Cities).');
        }

        if (depCity === arrCity) {
            return this.notifications.error('Departure and Arrival cities cannot be the same!');
        }

        // 5. Submit to Backend
        try {
            await model.save();
            this.notifications.success('Bus route created successfully!');
            
            // 1. Run the callback if it exists
            if (this.onAfterSave) {
                this.onAfterSave(model);
            }

            // 2. REDIRECT TO LISTING PAGE
            // Change the string below to match your actual route name
            this.router.transitionTo('console.fleet-ops.operations.bus-routes.index');

        } catch (error) {
            console.error("Save Error:", error);
            this.notifications.serverError(error);
        } finally {
            this.isSubmitting = false;
        }
    }

    @action
    handleVendorSelection(selectedVendor) {
        let vendorId = null;
    
        if (typeof selectedVendor === 'string') {
            // String format: "<model::vendor:979ce3ee...>"
            if (selectedVendor.includes(':')) {
                const parts = selectedVendor.split(':');
                vendorId = parts[parts.length - 1].replace('>', '');
            } else {
                vendorId = selectedVendor;
            }
        } else if (selectedVendor && typeof selectedVendor === 'object') {
            // Agar Ember object hai
            vendorId = typeof selectedVendor.get === 'function' ? selectedVendor.get('id') : selectedVendor.id;
        }
    
        if (vendorId) {
            this.model.set('vendor_uuid', vendorId);
            const vendorRecord = this.store.peekRecord('vendor', vendorId);
            if (vendorRecord) {
                this.model.set('vendor', vendorRecord);
            }
        } else {
            this.model.set('vendor_uuid', null);
            this.model.set('vendor', null);
        }
    }

    get travelTypes() {
        return ['Bus', 'Train', 'Ferry'];
    }

    @action
    selectDepartureLocation(place) {
        this.model.departure_location = place;
        this.model.departure_address = place.address; // Text format mein save karne ke liye
        this.model.departure_location_uuid = place.id; // Relationship ke liye
    }

    @action
    selectArrivalLocation(place) {
        this.model.arrival_location = place;
        this.model.arrival_address = place.address;
        this.model.arrival_location_uuid = place.id;
    }
}